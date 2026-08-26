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
 * page cache ignores cache contexts, so a header-identified request must
 * not use it at all.
 *
 * The header is the whole test. Request policies run before routing, so
 * there is no reliable way to tell "the settings resource" apart from any
 * other path here: the router lowercases, decodes, trims slashes and
 * resolves aliases before it matches, and a text comparison of the raw path
 * misses every one of those spellings. Each miss is a cached response
 * served to the wrong consumer. A request that carries the header pays
 * with the internal page cache on every path instead, and keeps the
 * dynamic page cache, which varies correctly.
 */
final class DenyOnConsumerHeader implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    return $request->headers->has('X-Consumer-ID') ? self::DENY : NULL;
  }

}
