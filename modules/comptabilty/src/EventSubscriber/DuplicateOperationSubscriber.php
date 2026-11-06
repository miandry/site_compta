<?php

namespace Drupal\comptabilty\EventSubscriber;

use Drupal\comptability_access\ApiUser;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Refuses /api_solutions/save when every compared field matches an existing op.
 *
 * Compared: title (libellé), amount, date, person, caisse, category, movement,
 * justificatif image name — among the operations the caller can see.
 */
class DuplicateOperationSubscriber implements EventSubscriberInterface {

  public const BUNDLE = 'operation';

  public function __construct(
    protected ApiUser $apiUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 31]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'api_solutions.save' || !$request->isMethod('POST')) {
      return;
    }
    $content = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($content) || ($content['entity_type'] ?? '') !== 'node' || ($content['bundle'] ?? '') !== self::BUNDLE) {
      return;
    }
    if (!isset($content['field_amount']) || (isset($content['status']) && (int) $content['status'] === 0)) {
      return;
    }

    $duplicate = $this->findDuplicate($content, isset($content['nid']) ? (int) $content['nid'] : NULL);
    if ($duplicate) {
      $event->setResponse(new JsonResponse([
        'status' => 'error',
        'code' => 'duplicate',
        'duplicate' => ['id' => (int) $duplicate->id(), 'title' => $duplicate->label()],
        'message' => sprintf(
          'Doublon : l\'opération « %s » a déjà les mêmes champs (libellé, montant, date, personne, caisse, catégorie, mouvement, justificatif).',
          $duplicate->label()
        ),
      ], 409));
      $event->stopPropagation();
    }
  }

  /**
   * @param array<string, mixed> $content
   *   The api_solutions save payload.
   */
  public function findDuplicate(array $content, ?int $excludeNid = NULL): ?NodeInterface {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::BUNDLE)
      ->condition('status', 1)
      ->condition('field_amount', (int) round((float) $content['field_amount']));

    $title = trim((string) ($content['title'] ?? ''));
    if ($title !== '') {
      $query->condition('title', $title);
    }
    foreach (['field_caisse', 'field_category', 'field_mouvement_argent', 'field_person'] as $field) {
      $value = $this->scalar($content[$field] ?? NULL);
      if ($value === '') {
        $query->notExists($field);
      }
      else {
        $query->condition($field, $value);
      }
    }
    $date = $this->operationDate($content['field_operation_date'] ?? NULL);
    if ($date !== '') {
      $query->condition('field_operation_date', $date, 'STARTS_WITH');
    }
    if ($excludeNid) {
      $query->condition('nid', $excludeNid, '<>');
    }
    // Always own-only for non-admins: never block on another user's operation.
    if (!$this->apiUser->canSeeAll(self::BUNDLE)) {
      $user = $this->apiUser->get();
      $query->condition('uid', $user ? (int) $user->id() : -1);
    }

    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }
    $images = $this->imageNames($this->fileIds($content['field_image_prof'] ?? NULL));
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      $existing = $this->imageNames(array_column($node->get('field_image_prof')->getValue(), 'target_id'));
      if ($existing === $images) {
        return $node;
      }
    }
    return NULL;
  }

  protected function scalar(mixed $value): string {
    if (is_array($value)) {
      $value = $value['target_id'] ?? $value['value'] ?? reset($value);
    }
    return $value === NULL || $value === FALSE ? '' : trim((string) $value);
  }

  /**
   * YYYY-MM-DD from a Drupal datetime payload ("2026-10-20" or "…T00:00:00").
   */
  protected function operationDate(mixed $value): string {
    $raw = $this->scalar($value);
    if ($raw === '') {
      return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
      return $m[1];
    }
    return $raw;
  }

  /**
   * @return int[]
   */
  protected function fileIds(mixed $value): array {
    if ($value === NULL || $value === '' || $value === []) {
      return [];
    }
    $ids = [];
    foreach ((array) $value as $item) {
      $id = is_array($item) ? ($item['target_id'] ?? $item['fid'] ?? NULL) : $item;
      if (is_numeric($id)) {
        $ids[] = (int) $id;
      }
    }
    return $ids;
  }

  /**
   * Sorted, normalized file names: "Reçu_0.JPG" and "reçu.jpg" are the same
   * upload (Drupal appends _N when the name already exists).
   *
   * @param array<int|string> $fids
   *
   * @return string[]
   */
  protected function imageNames(array $fids): array {
    $names = [];
    foreach ($fids ? $this->entityTypeManager->getStorage('file')->loadMultiple($fids) : [] as $file) {
      $name = mb_strtolower((string) $file->getFilename());
      $names[] = preg_replace('/_\d+(\.[^.]+)$/', '$1', $name) ?? $name;
    }
    sort($names);
    return $names;
  }

}
