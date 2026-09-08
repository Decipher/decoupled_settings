<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\decoupled_settings\Resource\SettingsResource;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the resource can be built from the container.
 *
 * The resource is a transport, so what it does is covered by functional
 * tests over HTTP. What it depends on is not: a renamed or removed service
 * would surface only as a 500 in a browser test, well away from the change
 * that caused it.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\Resource\SettingsResource
 */
class SettingsResourceWiringTest extends KernelTestBase {

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
    'consumers',
    'decoupled_settings',
  ];

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
