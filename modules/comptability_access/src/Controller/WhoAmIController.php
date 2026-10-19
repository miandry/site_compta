<?php

namespace Drupal\comptability_access\Controller;

use Drupal\comptability_access\ApiUser;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shows how the API sees the caller: who they are and what they may list.
 */
class WhoAmIController extends ControllerBase {

  public function show() {
    $api_user = \Drupal::service('comptability_access.api_user');
    $user = $api_user->get();
    $scope = [];
    foreach (ApiUser::PRIVATE_BUNDLES as $bundle) {
      $scope[$bundle] = $api_user->canSeeAll($bundle) ? 'all' : 'own';
    }
    return new JsonResponse([
      'uid' => $user ? (int) $user->id() : 0,
      'name' => $user ? $user->getAccountName() : NULL,
      'roles' => $user ? $user->getRoles(TRUE) : [],
      'admin' => $api_user->isAdmin(),
      'scope' => $scope,
    ]);
  }

}
