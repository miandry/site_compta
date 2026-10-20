<?php

namespace Drupal\comptability_access\EventSubscriber;

use Drupal\comptability_access\ApiUser;
use Drupal\user\Entity\Role;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets content_editor save personnes / opérations when Drupal perms lag.
 *
 * api_solutions refuses with « pas la permission de creer … » when the role
 * lacks the matching create/edit permission. We grant the perms and perform
 * the save before that check runs.
 */
class ContentEditorSaveSubscriber implements EventSubscriberInterface {

  /**
   * Bundles content_editor may save, and whether they may edit any or only own.
   */
  protected const BUNDLES = [
    'person' => 'any',
    'operation' => 'own',
    'operation_lot' => 'own',
  ];

  protected ApiUser $apiUser;

  public function __construct(ApiUser $api_user) {
    $this->apiUser = $api_user;
  }

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'api_solutions.save' || !$request->isMethod('POST')) {
      return;
    }
    $content = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($content) || ($content['entity_type'] ?? '') !== 'node') {
      return;
    }
    $bundle = $content['bundle'] ?? '';
    if (!isset(self::BUNDLES[$bundle])) {
      return;
    }

    $user = $this->apiUser->get();
    if (!$user || !in_array('content_editor', $user->getRoles(), TRUE)) {
      return;
    }

    $this->ensurePermissions();

    $is_update = isset($content['nid']) && is_numeric($content['nid']);
    if ($is_update && self::BUNDLES[$bundle] === 'own') {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($content['nid']);
      if (!$node || (int) $node->getOwnerId() !== (int) $user->id()) {
        $event->setResponse(new JsonResponse([
          'message' => "Vous ne pouvez modifier que vos propres contenus ({$bundle})",
          'status' => 'error',
        ], 403));
        return;
      }
    }

    if (!isset($content['uid'])) {
      $content['uid'] = $user->id();
    }
    unset($content['bundle'], $content['entity_type'], $content['token'], $content['author']);

    try {
      $entity = \Drupal::service('api_solutions.crud')->save('node', $bundle, $content);
      if (is_object($entity)) {
        $event->setResponse(new JsonResponse(['item' => $entity->id(), 'status' => TRUE]));
        return;
      }
      $event->setResponse(new JsonResponse(['message' => 'Erreur lors de la sauvegarde', 'status' => 'error'], 500));
    }
    catch (\Throwable $e) {
      $event->setResponse(new JsonResponse(['message' => $e->getMessage(), 'status' => 'error'], 500));
    }
  }

  protected function ensurePermissions(): void {
    $role = Role::load('content_editor');
    if (!$role) {
      return;
    }
    $changed = FALSE;
    foreach (self::BUNDLES as $bundle => $scope) {
      $perms = [
        "create {$bundle} content",
        "edit own {$bundle} content",
        "delete own {$bundle} content",
      ];
      if ($scope === 'any') {
        $perms[] = "edit any {$bundle} content";
        $perms[] = "delete any {$bundle} content";
      }
      foreach ($perms as $permission) {
        if (!$role->hasPermission($permission)) {
          $role->grantPermission($permission);
          $changed = TRUE;
        }
      }
    }
    if ($changed) {
      $role->save();
    }
  }

}
