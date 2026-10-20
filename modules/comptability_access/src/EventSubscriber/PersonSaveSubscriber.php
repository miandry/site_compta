<?php

namespace Drupal\comptability_access\EventSubscriber;

use Drupal\comptability_access\ApiUser;
use Drupal\user\Entity\Role;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets content_editor create/edit personnes when api_solutions would refuse.
 *
 * On production the role often lacks « create person content » in the permission
 * cache, so api_solutions returns « pas la permission de creer … (person) ».
 * We grant the perms and perform the save before that check runs.
 */
class PersonSaveSubscriber implements EventSubscriberInterface {

  protected ApiUser $apiUser;

  public function __construct(ApiUser $api_user) {
    $this->apiUser = $api_user;
  }

  public static function getSubscribedEvents(): array {
    // After routing (32), before the controller.
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'api_solutions.save' || !$request->isMethod('POST')) {
      return;
    }
    $content = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($content) || ($content['entity_type'] ?? '') !== 'node' || ($content['bundle'] ?? '') !== 'person') {
      return;
    }

    $user = $this->apiUser->get();
    if (!$user || !in_array('content_editor', $user->getRoles(), TRUE)) {
      return;
    }

    $this->ensurePersonPermissions();

    if (!isset($content['uid'])) {
      $content['uid'] = $user->id();
    }
    $bundle = $content['bundle'];
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

  protected function ensurePersonPermissions(): void {
    $role = Role::load('content_editor');
    if (!$role) {
      return;
    }
    $changed = FALSE;
    foreach ([
      'create person content',
      'edit own person content',
      'edit any person content',
      'delete own person content',
      'delete any person content',
    ] as $permission) {
      if (!$role->hasPermission($permission)) {
        $role->grantPermission($permission);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $role->save();
    }
  }

}
