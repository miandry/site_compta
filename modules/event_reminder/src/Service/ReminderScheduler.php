<?php

namespace Drupal\event_reminder\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds the events happening in `days_before` days and queues their reminder.
 */
class ReminderScheduler {

  public const QUEUE = 'event_reminder_queue';

  /**
   * field_event_repeat values that move the event forward once passed.
   */
  public const REPEATS = ['daily', 'weekly', 'monthly', 'yearly'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Queues one {nid, person} item per due event.
   *
   * @return int
   *   Number of queued items.
   */
  public function queueDueReminders(): int {
    $queue = $this->queueFactory->get(self::QUEUE);
    $count = 0;
    foreach ($this->dueEvents() as $node) {
      $queue->createItem([
        'nid' => (int) $node->id(),
        'person' => (int) $node->get('field_person')->target_id,
      ]);
      $count++;
    }
    if ($count) {
      $this->logger->info('@count rappel(s) mis en file.', ['@count' => $count]);
    }
    return $count;
  }

  /**
   * Moves past repeating events to their next occurrence.
   *
   * Saving the new date resets field_reminder_sent (hook_node_presave), so the
   * next occurrence gets its own reminder. Must run before queueDueReminders().
   *
   * @return int
   *   Number of events moved.
   */
  public function rollRecurringEvents(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $utc = new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $now = new \DateTimeImmutable('@' . $this->time->getRequestTime());
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_event_repeat', self::REPEATS, 'IN')
      ->condition('field_event_date', $now->setTimezone($utc)->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT), '<')
      ->execute();

    $count = 0;
    foreach ($storage->loadMultiple($ids) as $node) {
      $date = new \DateTimeImmutable($node->get('field_event_date')->value, $utc);
      $next = static::nextOccurrence($date, (string) $node->get('field_event_repeat')->value, $now, $this->timezone());
      if (!$next) {
        continue;
      }
      $node->set('field_event_date', $next->setTimezone($utc)->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT));
      $node->save();
      $count++;
      $this->logger->info('Événement répété « @title » (@nid) reporté au @date.', [
        '@title' => $node->label(),
        '@nid' => $node->id(),
        '@date' => $next->format('d/m/Y H:i'),
      ]);
    }
    return $count;
  }

  /**
   * First occurrence strictly after $now.
   *
   * Steps are computed from the original date in the local timezone, so the
   * local time is kept across DST changes and a monthly event on the 31st
   * falls on the last day of shorter months (31/01 → 28/02 → 31/03).
   */
  public static function nextOccurrence(\DateTimeImmutable $date, string $repeat, \DateTimeImmutable $now, \DateTimeZone $timezone): ?\DateTimeImmutable {
    $local = $date->setTimezone($timezone);
    for ($k = 1; $k <= 100000; $k++) {
      $candidate = match ($repeat) {
        'daily' => $local->modify("+{$k} days"),
        'weekly' => $local->modify('+' . (7 * $k) . ' days'),
        'monthly' => static::addMonths($local, $k),
        'yearly' => static::addMonths($local, 12 * $k),
        default => NULL,
      };
      if ($candidate === NULL) {
        return NULL;
      }
      if ($candidate > $now) {
        return $candidate;
      }
    }
    return NULL;
  }

  protected static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable {
    $index = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
    $year = intdiv($index, 12);
    $month = $index % 12 + 1;
    $last_day = (int) $date->setDate($year, $month, 1)->format('t');
    return $date->setDate($year, $month, min((int) $date->format('j'), $last_day));
  }

  protected function timezone(): \DateTimeZone {
    return new \DateTimeZone($this->configFactory->get('event_reminder.settings')->get('timezone') ?: date_default_timezone_get());
  }

  /**
   * Published events of the target day whose reminder has not been sent.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  public function dueEvents(): array {
    [$start, $end] = $this->window();
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE);
    $not_sent = $query->orConditionGroup()
      ->condition('field_reminder_sent', 0)
      ->notExists('field_reminder_sent');
    // A repeating event moved forward after its target day already started
    // (e.g. daily event, cron run after midnight) still gets its reminder.
    $in_window = $query->orConditionGroup()
      ->condition($query->andConditionGroup()
        ->condition('field_event_date', $start, '>=')
        ->condition('field_event_date', $end, '<='))
      ->condition($query->andConditionGroup()
        ->condition('field_event_repeat', self::REPEATS, 'IN')
        ->condition('field_event_date', $now, '>=')
        ->condition('field_event_date', $end, '<='));
    $ids = $query
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition($in_window)
      ->condition($not_sent)
      ->exists('field_person')
      ->sort('field_event_date')
      ->execute();
    return $ids ? $this->entityTypeManager->getStorage('node')->loadMultiple($ids) : [];
  }

  /**
   * Target day [00:00, 23:59:59] in the configured timezone, in UTC storage format.
   *
   * @return string[]
   *   [start, end] as Y-m-d\TH:i:s UTC strings (datetime field storage).
   */
  public function window(): array {
    $config = $this->configFactory->get('event_reminder.settings');
    $timezone = new \DateTimeZone($config->get('timezone') ?: date_default_timezone_get());
    $days = max(0, (int) $config->get('days_before'));
    $day = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->modify("+{$days} days");
    $utc = new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    return [
      $day->setTime(0, 0, 0)->setTimezone($utc)->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT),
      $day->setTime(23, 59, 59)->setTimezone($utc)->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT),
    ];
  }

}
