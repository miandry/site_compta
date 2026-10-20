<?php

namespace Drupal\comptability_access;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
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

  /**
   * Bundles only administrators see in full; everyone else sees their own.
   */
  public const PRIVATE_BUNDLES = ['operation', 'operation_lot'];

  /**
   * Bundles content editors may list and open in full (not only their own).
   */
  public const SHARED_BUNDLES = ['person'];

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
    return $user && static::isAdminAccount($user);
  }

  /**
   * Only administrators see the nodes of other users.
   */
  public static function isAdminAccount(AccountInterface $account): bool {
    if ((int) $account->id() === 1) {
      return TRUE;
    }
    foreach (Role::loadMultiple($account->getRoles(TRUE)) as $role) {
      if ($role->id() === 'administrator' || $role->isAdmin()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the caller sees every node of $bundle, not only their own.
   */
  public function canSeeAll(?string $bundle = NULL): bool {
    if ($this->isAdmin()) {
      return TRUE;
    }
    if (!$bundle || in_array($bundle, static::PRIVATE_BUNDLES, TRUE)) {
      return FALSE;
    }
    $user = $this->get();
    if (!$user) {
      return FALSE;
    }
    // Personnes: content editors see and use the full directory.
    if (in_array($bundle, static::SHARED_BUNDLES, TRUE)
      && in_array('content_editor', $user->getRoles(), TRUE)) {
      return TRUE;
    }
    return $user->hasPermission("edit any {$bundle} content");
  }

  /**
   * Whether this API list/detail must be limited to the caller's own nodes.
   */
  public function mustScopeToOwner(?string $bundle = NULL): bool {
    return $bundle && in_array($bundle, static::PRIVATE_BUNDLES, TRUE) && !$this->canSeeAll($bundle);
  }

  /**
   * True on the api_solutions routes whose node results must be scoped.
   */
  public function isScopedRoute(?string $route_name): bool {
    if (in_array($route_name, ['api_solutions.api.v2.list', 'api_solutions.api.v2.details', 'api_solutions.api.v1.list'], TRUE)) {
      return TRUE;
    }
    // Other api_solutions versions name their routes differently.
    return $this->isApiRequest();
  }

  /**
   * Any /api_solutions/api/ request (also behind a language prefix).
   */
  public function isApiRequest(): bool {
    $request = $this->requestStack->getCurrentRequest();
    return $request && str_contains($request->getPathInfo(), '/api_solutions/api/');
  }

  /**
   * Bundle requested on /api_solutions/api/v2/{entitype}/{bundle}[/{id}].
   */
  public function requestedBundle(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return NULL;
    }
    $bundle = $request->attributes->get('bundle');
    if (is_string($bundle) && $bundle !== '') {
      return $bundle;
    }
    if (preg_match('#/api_solutions/api/v2/node/([a-z0-9_]+)#', $request->getPathInfo(), $m)) {
      return $m[1];
    }
    $bundle = $request->query->get('bundle') ?? $request->query->get('type');
    return is_string($bundle) && $bundle !== '' ? $bundle : NULL;
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
