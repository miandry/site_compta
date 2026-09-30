<?php

namespace Drupal\comptability_access\EventSubscriber;

use Drupal\comptability_access\ApiUser;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Denies /api_solutions/api/v2/node/{bundle}/{id} for nodes of other users.
 */
class DetailsAccessSubscriber implements EventSubscriberInterface {

  protected ApiUser $apiUser;

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(ApiUser $api_user, EntityTypeManagerInterface $entity_type_manager) {
    $this->apiUser = $api_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function getSubscribedEvents(): array {
    // After routing (32) so the route parameters are available.
    return [KernelEvents::REQUEST => ['onRequest', 20]];
  }

  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'api_solutions.api.v2.details' || $request->attributes->get('entitype') !== 'node') {
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($request->attributes->get('id'));
    if ($node && $this->apiUser->canSeeAll($node->bundle())) {
      return;
    }
    $user = $this->apiUser->get();
    if ($node instanceof EntityOwnerInterface && $user && (int) $node->getOwnerId() === (int) $user->id()) {
      return;
    }
    $event->setResponse(new JsonResponse(['message' => 'Accès refusé', 'status' => 'error'], 403));
  }

}
