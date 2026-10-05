<?php

namespace Drupal\Tests\event_reminder\Kernel;

use Drupal\event_reminder\Service\ReminderScheduler;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * @coversDefaultClass \Drupal\event_reminder\Service\ReminderScheduler
 * @group event_reminder
 */
class ReminderSchedulerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'datetime',
    'options',
    'token',
    'event_reminder',
  ];

  protected NodeInterface $person;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'node', 'event_reminder']);
    $this->config('system.date')->set('timezone.default', 'Indian/Antananarivo')->save();

    NodeType::create(['type' => 'person', 'name' => 'Personne'])->save();
    \Drupal::moduleHandler()->loadInclude('event_reminder', 'install');
    event_reminder_install(FALSE);

    $this->person = Node::create(['type' => 'person', 'title' => 'Rakoto']);
    $this->person->save();
  }

  /**
   * Event at $relative (e.g. "+1 day 10:00") in Antananarivo time.
   */
  protected function createEvent(string $relative, bool $sent = FALSE): NodeInterface {
    $date = (new \DateTime($relative, new \DateTimeZone('Indian/Antananarivo')))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Y-m-d\TH:i:s');
    $node = Node::create([
      'type' => 'event',
      'title' => "Événement $relative",
      'status' => 1,
      'field_event_date' => $date,
      'field_person' => $this->person->id(),
      'field_reminder_sent' => $sent,
    ]);
    $node->save();
    return $node;
  }

  protected function queuedCount(): int {
    \Drupal::queue('event_reminder_queue')->deleteQueue();
    $this->container->get('event_reminder.scheduler')->queueDueReminders();
    return \Drupal::queue('event_reminder_queue')->numberOfItems();
  }

  public function testEventTomorrowIsQueued(): void {
    $this->createEvent('tomorrow 10:00');
    $this->assertSame(1, $this->queuedCount());
  }

  public function testEventEarlyTomorrowMorningIsQueued(): void {
    // 00:30 Antananarivo = 21:30 UTC the day before: the UTC window must cover it.
    $this->createEvent('tomorrow 00:30');
    $this->assertSame(1, $this->queuedCount());
  }

  public function testEventInTwoDaysIsNotQueued(): void {
    $this->createEvent('+2 days 10:00');
    $this->assertSame(0, $this->queuedCount());
  }

  public function testAlreadySentIsNotQueued(): void {
    $this->createEvent('tomorrow 10:00', TRUE);
    $this->assertSame(0, $this->queuedCount());
  }

  public function testDateChangeResetsReminderFlag(): void {
    $node = $this->createEvent('tomorrow 10:00', TRUE);
    $this->assertTrue((bool) $node->get('field_reminder_sent')->value);

    $node->set('field_event_date', (new \DateTime('+3 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s'));
    $node->save();
    $this->assertFalse((bool) Node::load($node->id())->get('field_reminder_sent')->value);
  }

  public function testPastWeeklyEventMovesToNextWeekAndResetsReminder(): void {
    $node = $this->createEvent('-1 day 10:00', TRUE);
    $node->set('field_event_repeat', 'weekly')->save();

    $this->assertSame(1, $this->container->get('event_reminder.scheduler')->rollRecurringEvents());
    $node = Node::load($node->id());
    $expected = (new \DateTime('+6 days 10:00', new \DateTimeZone('Indian/Antananarivo')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    $this->assertSame($expected, $node->get('field_event_date')->value);
    $this->assertFalse((bool) $node->get('field_reminder_sent')->value);
  }

  public function testDailyEventMovedToLaterTodayIsQueued(): void {
    $node = $this->createEvent('-1 day 23:59', TRUE);
    $node->set('field_event_repeat', 'daily')->save();
    $this->container->get('event_reminder.scheduler')->rollRecurringEvents();
    $this->assertSame(1, $this->queuedCount());
  }

  public function testOneOffEventLaterTodayIsNotQueued(): void {
    $this->createEvent('today 23:59');
    $this->assertSame(0, $this->queuedCount());
  }

  public function testPastEventWithoutRepeatStays(): void {
    $node = $this->createEvent('-1 day 10:00', TRUE);
    $this->assertSame(0, $this->container->get('event_reminder.scheduler')->rollRecurringEvents());
    $this->assertTrue((bool) Node::load($node->id())->get('field_reminder_sent')->value);
  }

  /**
   * @covers ::nextOccurrence
   */
  public function testNextOccurrence(): void {
    $tz = new \DateTimeZone('Indian/Antananarivo');
    $at = fn(string $s) => new \DateTimeImmutable($s, $tz);
    $next = fn(string $date, string $repeat, string $now) => ReminderScheduler::nextOccurrence($at($date), $repeat, $at($now), $tz)?->format('Y-m-d H:i');

    $this->assertSame('2026-10-06 08:00', $next('2026-10-01 08:00', 'daily', '2026-10-05 09:00'));
    $this->assertSame('2026-10-08 08:00', $next('2026-10-01 08:00', 'weekly', '2026-10-05 09:00'));
    $this->assertSame('2026-02-28 08:00', $next('2026-01-31 08:00', 'monthly', '2026-02-01 00:00'));
    $this->assertSame('2026-03-31 08:00', $next('2026-01-31 08:00', 'monthly', '2026-03-01 00:00'));
    $this->assertSame('2028-02-29 08:00', $next('2024-02-29 08:00', 'yearly', '2027-03-01 00:00'));
    $this->assertSame('2027-02-28 08:00', $next('2024-02-29 08:00', 'yearly', '2026-03-01 00:00'));
    $this->assertNull($next('2026-10-01 08:00', '', '2026-10-05 09:00'));
  }

  public function testTitleChangeKeepsReminderFlag(): void {
    $node = $this->createEvent('tomorrow 10:00', TRUE);
    $node->setTitle('Nouveau titre')->save();
    $this->assertTrue((bool) Node::load($node->id())->get('field_reminder_sent')->value);
  }

}
