<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps the internal page cache away from header-identified consumers.
 *
 * The internal page cache looks responses up by URL alone. A consumer that
 * names itself with the consumerId query parameter gets its own cache entry
 * for free, because the URL differs. A consumer that names itself with the
 * X-Consumer-ID header does not: the URL is the same for every consumer, so
 * the page cache would serve one consumer's settings to the next. The
 * headers cache context protects the dynamic page cache, but the internal
 * page cache ignores cache contexts, so the header path must not use it at
 * all.
 */
final class DenyOnConsumerHeader implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return $request->headers->has('X-Consumer-ID') ? self::DENY : NULL;
  }

}
