<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings\Resource;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeAttribute;
use Drupal\jsonapi_resources\Resource\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Serves the resolved settings as a read-only JSON:API resource.
 *
 * This is a thin adapter: everything about what is exposed and how overrides
 * merge lives in the resolver, which has no HTTP in its signature. A second
 * transport would sit beside this class, not replace it.
 */
final class SettingsResource extends ResourceBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly SettingsResolver $settingsResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('decoupled_settings.resolver'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * Processes the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Drupal\jsonapi\ResourceType\ResourceType[] $resource_types
   *   The route resource types.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   */
  public function process(Request $request, array $resource_types): ResourceResponse {
    $cacheability = new CacheableMetadata();

    // The response changes with the consumer, however the consumer names
    // itself. Both negotiation mechanisms get a cache context, because the
    // consumers module's own Vary header does not make Drupal's caches vary.
    $cacheability->addCacheContexts([
      'url.query_args:consumerId',
      'headers:X-Consumer-ID',
    ]);

    $consumer = $this->consumerFor($request, $cacheability);
    $resolved = $this->settingsResolver->resolve($consumer, $cacheability);

    $resource_type = reset($resource_types);
    if (!$resource_type instanceof ResourceType) {
      throw new \LogicException('The settings route defines exactly one resource type.');
    }
    $primary_data = new ResourceObject(
      $cacheability,
      $resource_type,
      'decoupled-settings',
      NULL,
      [
        'settings' => $resolved,
        'consumer' => $consumer?->getClientId(),
      ],
      new LinkCollection([])
    );

    $response = $this->createJsonapiResponse(new ResourceObjectData([$primary_data], 1), $request);
    if ($response instanceof CacheableResponseInterface) {
      $response->addCacheableDependency($cacheability);
    }

    return $response;
  }

  /**
   * Finds the consumer a request explicitly identifies.
   *
   * The consumers negotiator is deliberately not used here. Its fallback is
   * the consumer flagged is_default, so with the negotiator a request naming
   * no consumer would read the default consumer's overrides as if they were
   * the site's global values. The contract here is different: no consumer
   * named, or a name that matches nothing, means the global values.
   *
   * The identification order matches the negotiator, so a client written
   * against the consumers module behaves the same: the X-Consumer-ID header
   * first, then the consumerId query parameter.
   */
  private function consumerFor(Request $request, CacheableMetadata $cacheability): ?ConsumerInterface {
    $client_id = $request->headers->get('X-Consumer-ID')
      ?: $request->query->get('consumerId');
    if (!$client_id) {
      return NULL;
    }

    $consumers = $this->entityTypeManager->getStorage('consumer')
      ->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    if (!$consumer instanceof ConsumerInterface) {
      return NULL;
    }

    // A consumer the caller may not read reads as one that does not exist:
    // the global values, with no consumer named. A 403 would tell a caller
    // which client IDs are real.
    return $this->mayRead($consumer, $cacheability) ? $consumer : NULL;
  }

  /**
   * Tests whether the caller may read the settings of one consumer.
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
   */
  private function mayRead(ConsumerInterface $consumer, CacheableMetadata $cacheability): bool {
    // The answer depends on who is asking, however they authenticated.
    $cacheability->addCacheContexts(['user']);

    if ($this->currentUser->isAnonymous()) {
      return TRUE;
    }

    $cacheability->addCacheContexts(['user.permissions']);
    if ($this->currentUser->hasPermission('read any consumer decoupled settings')) {
      return TRUE;
    }

    $cacheability->addCacheableDependency($consumer);
    // user_id is the account a token acts as, which Simple OAuth adds, and
    // owner_id is who created the consumer, which Consumers checks below.
    // They are different fields, so both are asked.
    if ($consumer->hasField('user_id')) {
      $acts_as = (int) ($consumer->get('user_id')->target_id ?? 0);
      if ($acts_as !== 0 && $acts_as === (int) $this->currentUser->id()) {
        return TRUE;
      }
    }

    $access = $consumer->access('view', $this->currentUser, TRUE);
    $cacheability->addCacheableDependency($access);

    return $access->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteResourceTypes(Route $route, string $route_name): array {
    $fields = [
      'settings' => new ResourceTypeAttribute('settings'),
      'consumer' => new ResourceTypeAttribute('consumer'),
    ];

    // A non-entity, read-only resource type: not internal, locatable, not
    // mutable, not versionable.
    // The resource is not mutable, so nothing is ever deserialized into the
    // target class. The constructor still wants a class-string.
    $resource_type = new ResourceType('decoupled_settings', 'settings', \stdClass::class, FALSE, TRUE, FALSE, FALSE, $fields);

    return [$resource_type];
  }

}
