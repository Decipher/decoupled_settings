<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests settings contributed through the alter hook.
 *
 * The hook runs before the merge, so a contributed setting has to behave
 * exactly like one read from config, overrides included. That is what makes
 * the hook usable as a delivery mechanism for other modules.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\SettingsResolver
 */
class SettingsAlterHookTest extends KernelTestBase {

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
    'decoupled_settings_test',
  ];

  /**
   * The resolver under test.
   */
  protected SettingsResolver $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'decoupled_settings']);

    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site'])
      ->set('expose_theme_settings', FALSE)
      ->save();

    $this->resolver = $this->container->get('decoupled_settings.resolver');
  }

  /**
   * A contributed group appears alongside the config-derived ones.
   */
  public function testContributedGroupIsExposed(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayHasKey('test_group', $resolved);
    $this->assertSame(['header', 'content', 'footer'], $resolved['test_group']['order']);
    $this->assertSame('Contributed', $resolved['test_group']['label']);
  }

  /**
   * Config-derived settings still resolve when a hook contributes as well.
   */
  public function testContributionDoesNotDisplaceConfig(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayHasKey('system.site', $resolved);
    $this->assertArrayHasKey('name', $resolved['system.site']);
  }

  /**
   * A consumer can override a contributed setting.
   *
   * This is the property that lets another module deliver its settings
   * through this one and get per-consumer variation for free.
   */
  public function testContributedSettingIsOverridable(): void {
    $consumer = Consumer::create([
      'client_id' => 'hook_consumer',
      'label' => 'Hook consumer',
      SettingsResolver::OVERRIDE_FIELD => [
        'test_group:order' => ['footer', 'header'],
      ],
    ]);
    $consumer->save();

    $resolved = $this->resolver->resolve($consumer, new CacheableMetadata());

    $this->assertSame(['footer', 'header'], $resolved['test_group']['order']);
    // Everything else in the contributed group is still inherited.
    $this->assertSame('Contributed', $resolved['test_group']['label']);
  }

  /**
   * A nested contributed setting is overridable by path.
   */
  public function testNestedContributedSettingIsOverridable(): void {
    $consumer = Consumer::create([
      'client_id' => 'nested_consumer',
      'label' => 'Nested consumer',
      SettingsResolver::OVERRIDE_FIELD => [
        'test_group:nested.depth' => 5,
      ],
    ]);
    $consumer->save();

    $resolved = $this->resolver->resolve($consumer, new CacheableMetadata());

    $this->assertSame(5, $resolved['test_group']['nested']['depth']);
  }

  /**
   * Cacheability declared by the hook reaches the response.
   */
  public function testContributedCacheabilityIsKept(): void {
    $cacheability = new CacheableMetadata();
    $this->resolver->resolve(NULL, $cacheability);

    $this->assertContains('decoupled_settings_test', $cacheability->getCacheTags());
  }

}
