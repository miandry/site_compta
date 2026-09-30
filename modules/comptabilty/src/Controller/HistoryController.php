<?php

namespace Drupal\comptabilty\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Revision history of operations for the Vue app.
 */
class HistoryController extends ControllerBase {

  /**
   * Same rule as the api_solutions lists: owner, admin, or "edit any operation".
   */
  public function access(NodeInterface $node) {
    if ($node->bundle() !== 'operation') {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $api_user = \Drupal::service('comptability_access.api_user');
    $user = $api_user->get();
    $allowed = $api_user->canSeeAll('operation') || ($user && (int) $node->getOwnerId() === (int) $user->id());
    return AccessResult::allowedIf($allowed)->setCacheMaxAge(0);
  }

  public function operation(NodeInterface $node) {
    return new JsonResponse(['rows' => \Drupal::service('comptabilty.manager')->operationHistory($node)]);
  }

}
