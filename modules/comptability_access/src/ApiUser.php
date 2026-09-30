<?php

namespace Drupal\comptability_access;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the account behind an api_solutions request.
 *
 * api_solutions authenticates with its own bearer token (cookie `auth_token`,
 * `Authorization: Bearer`, or `?token=`), not with a Drupal session, so
 * \Drupal::currentUser() is anonymous for most API calls.
 */
class ApiUser {

  protected RequestStack $requestStack;

  protected AccountInterface $currentUser;

  protected $apiCrud;

  /** @var \Drupal\user\UserInterface|false|null */
  protected $resolved = NULL;

  public function __construct(RequestStack $request_stack, AccountInterface $current_user, $api_crud) {
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
    $this->apiCrud = $api_crud;
  }

  /**
   * @return \Drupal\user\UserInterface|null
   */
  public function get() {
    if ($this->resolved === NULL) {
      $this->resolved = $this->resolve() ?: FALSE;
    }
    return $this->resolved ?: NULL;
  }

  public function isAdmin(): bool {
    $user = $this->get();
    return $user && ((int) $user->id() === 1 || in_array('administrator', $user->getRoles(), TRUE));
  }

  /**
   * Whether the caller sees every node of $bundle, not only their own.
   */
  public function canSeeAll(?string $bundle): bool {
    if ($this->isAdmin()) {
      return TRUE;
    }
    $user = $this->get();
    return $user && $bundle && $user->hasPermission("edit any {$bundle} content");
  }

  /**
   * True on the api_solutions routes whose node results must be scoped.
   */
  public function isScopedRoute(?string $route_name): bool {
    return in_array($route_name, ['api_solutions.api.v2.list', 'api_solutions.api.v2.details', 'api_solutions.api.v1.list'], TRUE);
  }

  protected function resolve() {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $token = $request->cookies->get('auth_token');
      if (!$token) {
        $header = $request->headers->get('Authorization') ?: ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
          $token = trim($m[1]);
        }
      }
      if (!$token) {
        $token = $request->query->get('token');
      }
      if ($token) {
        $user = $this->apiCrud->validateBearerToken($token);
        if ($user) {
          return $user;
        }
      }
    }
    return $this->currentUser->isAuthenticated() ? User::load($this->currentUser->id()) : NULL;
  }

}
