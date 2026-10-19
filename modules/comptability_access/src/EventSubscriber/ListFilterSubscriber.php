<?php

namespace Drupal\comptability_access\EventSubscriber;

use Drupal\comptability_access\ApiUser;
use Drupal\Core\Database\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drops other users' operations from api_solutions JSON lists.
 *
 * Second line of defence behind the entity query alter, for api_solutions
 * versions whose lists are not built with a tagged entity query.
 */
class ListFilterSubscriber implements EventSubscriberInterface {

  protected ApiUser $apiUser;

  protected Connection $database;

  public function __construct(ApiUser $api_user, Connection $database) {
    $this->apiUser = $api_user;
    $this->database = $database;
  }

  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 0]];
  }

  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof JsonResponse || !$this->apiUser->isApiRequest() || $this->apiUser->isAdmin()) {
      return;
    }
    $data = json_decode((string) $response->getContent(), TRUE);
    if (!is_array($data)) {
      return;
    }
    $wrapped = isset($data['rows']) && is_array($data['rows']);
    $rows = $wrapped ? $data['rows'] : $data;
    if (!array_is_list($rows) || !$rows) {
      return;
    }

    $nids = [];
    foreach ($rows as $row) {
      if (is_array($row) && isset($row['nid']) && is_scalar($row['nid'])) {
        $nids[] = (int) $row['nid'];
      }
    }
    if (!$nids) {
      return;
    }
    $user = $this->apiUser->get();
    $uid = $user ? (int) $user->id() : -1;
    $hidden = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->condition('n.nid', $nids, 'IN')
      ->condition('n.type', ApiUser::PRIVATE_BUNDLES, 'IN')
      ->condition('n.uid', $uid, '<>')
      ->distinct()
      ->execute()
      ->fetchCol();
    if (!$hidden) {
      return;
    }
    $hidden = array_flip(array_map('intval', $hidden));
    $kept = array_values(array_filter($rows, fn ($row) => !(is_array($row) && isset($row['nid']) && isset($hidden[(int) $row['nid']]))));
    $removed = count($rows) - count($kept);

    if ($wrapped) {
      $data['rows'] = $kept;
      if (isset($data['total']) && is_numeric($data['total'])) {
        $data['total'] = max(count($kept), (int) $data['total'] - $removed);
      }
    }
    else {
      $data = $kept;
    }
    $response->setData($data);
  }

}
