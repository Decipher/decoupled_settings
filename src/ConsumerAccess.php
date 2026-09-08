<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides who may read a named consumer's settings.
 *
 * Naming a consumer in a request asks for that consumer's overrides, which
 * are not the site's values. So the question is not only whether the caller
 * may use the endpoint, but whether this caller may read this consumer.
 *
 * The decision has no HTTP in its signature, so a second transport asks the
 * same question and gets the same answer.
 */
final readonly class ConsumerAccess {

  /**
   * The permission that lifts the per-consumer check.
   */
  public const string READ_ANY = 'read any consumer decoupled settings';

  public function __construct(
    private AccountInterface $account,
  ) {}

  /**
   * Tells whether the current account may read one consumer's settings.
   *
   * Allowed, in order:
   * - an anonymous caller, because the site already chose to expose
   *   settings to anonymous when it granted the read permission;
   * - a holder of "read any consumer's decoupled settings";
   * - the consumer's own app, which Simple OAuth authenticates as the
   *   account in the consumer's user_id field;
   * - anyone Consumers' own "view" access allows.
   *
   * Everyone else is refused, and a refused consumer reads as one that
   * does not exist. The permission adds a grant rather than replacing the
   * entity access check, so Consumers stays the answer to "who may view a
   * consumer" and there is no second source of truth.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface $consumer
   *   The consumer named by the request.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache contexts and tags the decision depends on.
   *
   * @return bool
   *   TRUE if the account may read this consumer's settings.
   */
  public function mayRead(ConsumerInterface $consumer, CacheableMetadata $cacheability): bool {
    // The answer depends on who is asking, however they authenticated.
    $cacheability->addCacheContexts(['user']);

    // An anonymous caller has no identity to match a consumer against, so
    // there is nothing to check it against. What anonymous may read is
    // decided by the read permission and the exposure list instead.
    if ($this->account->isAnonymous()) {
      return TRUE;
    }

    $cacheability->addCacheContexts(['user.permissions']);
    if ($this->account->hasPermission(self::READ_ANY)) {
      return TRUE;
    }

    $cacheability->addCacheableDependency($consumer);
    // user_id is the account a token acts as, which Simple OAuth adds, and
    // owner_id is who created the consumer, which Consumers checks below.
    // They are different fields, so both are asked.
    if ($consumer->hasField('user_id')) {
      $acts_as = (int) ($consumer->get('user_id')->target_id ?? 0);
      if ($acts_as !== 0 && $acts_as === (int) $this->account->id()) {
        return TRUE;
      }
    }

    $access = $consumer->access('view', $this->account, TRUE);
    $cacheability->addCacheableDependency($access);

    return $access->isAllowed();
  }

}
