<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\Resource\SettingsResource;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the resource as the route uses it.
 *
 * Functional tests drive this over HTTP, which is the real contract but
 * leaves the transport invisible to coverage and reports its failures a long
 * way from the cause. Building the resource from the real container and
 * calling process() directly keeps both: a wrong service id or a broken
 * guard fails here, next to the code.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\Resource\SettingsResource
 */
class SettingsResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'consumers',
    'decoupled_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'decoupled_settings']);
    $this->config('system.site')->set('name', 'Global Site')->save();
  }

  /**
   * Builds the resource the way the route does.
   *
   * JsonapiResourceClassResolver calls these three setters after create(),
   * so a resource built without them cannot answer a request.
   *
   * @return \Drupal\decoupled_settings\Resource\SettingsResource
   *   The resource, ready to process a request.
   */
  protected function buildResource(): SettingsResource {
    $resource = SettingsResource::create($this->container);
    $resource->setResourceTypeRepository($this->container->get('jsonapi.resource_type.repository'));
    $resource->setResourceResponseFactory($this->container->get('jsonapi_resources.resource_response_factory'));
    $resource->setDocumentExtractor($this->container->get('jsonapi_resources.document_extractor'));

    return $resource;
  }

  /**
   * Runs one request through the resource and returns the primary object.
   */
  protected function runRequest(Request $request) {
    $resource = $this->buildResource();
    $types = $resource->getRouteResourceTypes(
      new Route('/jsonapi/decoupled/settings'),
      'decoupled_settings.settings'
    );

    return $resource->process($request, $types)
      ->getResponseData()->getData()->getIterator()->current();
  }

  /**
   * A request naming no consumer reads the global values.
   *
   * This is the transport's own path, including the consumer lookup, which
   * a functional test exercises over HTTP where coverage cannot see it.
   */
  public function testProcessReadsGlobalsWithNoConsumer(): void {
    $object = $this->runRequest(Request::create('/jsonapi/decoupled/settings'));

    $this->assertNull($object->getField('consumer'));
    $this->assertSame('Global Site', $object->getField('settings')['system.site']['name']);
  }

  /**
   * A request naming a consumer resolves it, and reads its overrides.
   */
  public function testProcessResolvesTheNamedConsumer(): void {
    Consumer::create([
      'client_id' => 'app_one',
      'label' => 'App one',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'App One Site'],
    ])->save();

    $object = $this->runRequest(
      Request::create('/jsonapi/decoupled/settings', 'GET', ['consumerId' => 'app_one'])
    );

    $this->assertSame('app_one', $object->getField('consumer'));
    $this->assertSame('App One Site', $object->getField('settings')['system.site']['name']);
  }

  /**
   * Naming a consumer that does not exist reads the global values.
   *
   * A typo in a build variable must not fail the build loudly here and
   * quietly serve someone else's branding, so the fallback is pinned.
   */
  public function testUnknownConsumerFallsBackToGlobals(): void {
    $object = $this->runRequest(
      Request::create('/jsonapi/decoupled/settings', 'GET', ['consumerId' => 'no_such_app'])
    );

    $this->assertNull($object->getField('consumer'));
    $this->assertSame('Global Site', $object->getField('settings')['system.site']['name']);
  }

  /**
   * A route that does not carry the resource type is a programming error.
   *
   * The resource declares exactly one, so anything else means the route was
   * built wrongly and a clear exception beats a confusing response.
   */
  public function testRouteWithoutTheResourceTypeThrows(): void {
    $this->expectException(\LogicException::class);

    $this->buildResource()->process(
      Request::create('/jsonapi/decoupled/settings'),
      []
    );
  }

  /**
   * Every service the resource names is resolvable.
   *
   * The real container is used rather than mocks, so this fails if a service
   * id stops existing instead of passing against a fiction.
   */
  public function testCreateResolvesEveryService(): void {
    // create() throws if any service id is wrong, so the call is the test.
    SettingsResource::create($this->container);

    $this->assertTrue($this->container->has('decoupled_settings.resolver'));
    $this->assertTrue($this->container->has('decoupled_settings.consumer_access'));
  }

}
