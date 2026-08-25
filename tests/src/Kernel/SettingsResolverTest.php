<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests settings resolution against real config.
 *
 * The merge itself is covered by the unit test. This covers the parts that
 * need Drupal: reading the exposure list, filtering keys through typed config
 * schema, and collecting cacheability.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\SettingsResolver
 */
class SettingsResolverTest extends KernelTestBase {

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
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->config('system.site')
      ->set('name', 'Global Site')
      ->set('slogan', 'Global slogan')
      ->save();

    // Theme settings are off by default here, so each test opts in.
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site'])
      ->set('expose_theme_settings', FALSE)
      ->save();

    $this->resolver = $this->container->get('decoupled_settings.resolver');
  }

  /**
   * With no consumer, the global values are returned.
   */
  public function testGlobalValuesWithoutConsumer(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertSame('Global Site', $resolved['system.site']['name']);
    $this->assertSame('Global slogan', $resolved['system.site']['slogan']);
  }

  /**
   * A config object that is not on the exposure list is not readable.
   */
  public function testUnlistedObjectIsNotExposed(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('system.mail', $resolved);
    $this->assertArrayNotHasKey('system.theme', $resolved);
  }

  /**
   * Adding an object to the exposure list makes it readable.
   */
  public function testListedObjectIsExposed(): void {
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site', 'system.date'])
      ->save();

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayHasKey('system.date', $resolved);
  }

  /**
   * Only keys the schema declares are returned.
   *
   * The key is written straight to the storage, bypassing the config object,
   * because an undocumented key is what this filter exists to drop.
   */
  public function testUndocumentedKeyIsDropped(): void {
    $storage = $this->container->get('config.storage');
    $data = $storage->read('system.site');
    if (!is_array($data)) {
      $this->fail('The system.site config could not be read.');
    }
    $data['not_in_schema'] = 'should not appear';
    $storage->write('system.site', $data);

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('not_in_schema', $resolved['system.site']);
    $this->assertSame('Global Site', $resolved['system.site']['name']);
  }

  /**
   * Internal plumbing keys are never exposed.
   */
  public function testInternalKeysAreDropped(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('uuid', $resolved['system.site']);
    $this->assertArrayNotHasKey('_core', $resolved['system.site']);
  }

  /**
   * An excluded key is not exposed, even though its schema declares it.
   *
   * The site email address is schema declared and would otherwise ship to
   * every frontend by default.
   */
  public function testExcludedKeyIsNotExposed(): void {
    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('mail', $resolved['system.site']);
  }

  /**
   * Removing a key from the exclusion list exposes it again.
   */
  public function testExclusionListIsConfigurable(): void {
    $this->config('decoupled_settings.settings')
      ->set('excluded_keys', [])
      ->save();

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayHasKey('mail', $resolved['system.site']);
  }

  /**
   * A config object with no schema exposes nothing.
   *
   * Without a schema there is nothing to bound the keys, so the safe answer
   * is to expose none of them rather than all of them.
   */
  public function testObjectWithoutSchemaExposesNothing(): void {
    $this->container->get('config.storage')
      ->write('decoupled_settings.no_such_schema', ['secret' => 'value']);

    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site', 'decoupled_settings.no_such_schema'])
      ->save();

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('decoupled_settings.no_such_schema', $resolved);
    $this->assertArrayHasKey('system.site', $resolved);
  }

  /**
   * A schema-declared key that the stored data lacks is simply absent.
   *
   * Schema describes what may be present, not what must be, so a missing key
   * is not an error and must not surface as a null.
   */
  public function testDeclaredKeyMissingFromDataIsAbsent(): void {
    $storage = $this->container->get('config.storage');
    $data = $storage->read('system.site');
    if (!is_array($data)) {
      $this->fail('The system.site config could not be read.');
    }
    unset($data['slogan']);
    $storage->write('system.site', $data);

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('slogan', $resolved['system.site']);
    $this->assertSame('Global Site', $resolved['system.site']['name']);
  }

  /**
   * A config object that does not exist is skipped rather than erroring.
   */
  public function testMissingObjectIsSkipped(): void {
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site', 'does.not.exist'])
      ->save();

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('does.not.exist', $resolved);
    $this->assertSame('Global Site', $resolved['system.site']['name']);
  }

  /**
   * The theme settings of the active theme are exposed when enabled.
   */
  public function testActiveThemeSettingsAreExposed(): void {
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_settings', TRUE)
      ->save();

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayHasKey('stark.settings', $resolved);
    $this->assertArrayHasKey('url', $resolved['stark.settings']['favicon']);
  }

  /**
   * A consumer can override a theme setting.
   *
   * Theme settings come from core's resolution rather than straight from
   * config, so the override path is asserted separately from system.site.
   */
  public function testConsumerCanOverrideThemeSetting(): void {
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_settings', TRUE)
      ->save();

    $consumer = Consumer::create([
      'client_id' => 'theme_consumer',
      'label' => 'Theme consumer',
      SettingsResolver::OVERRIDE_FIELD => [
        'stark.settings:logo.url' => '/custom/logo.svg',
      ],
    ]);
    $consumer->save();

    $resolved = $this->resolver->resolve($consumer, new CacheableMetadata());

    $this->assertSame('/custom/logo.svg', $resolved['stark.settings']['logo']['url']);
  }

  /**
   * An ignore-typed schema key is never exposed.
   *
   * Declared unknowable is not declared safe: its raw value, nested
   * included, must not fall through the filter.
   */
  public function testIgnoreTypedKeyIsNotExposed(): void {
    $this->enableModules(['decoupled_settings_test']);
    $this->installConfig(['decoupled_settings_test']);
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['decoupled_settings_test.settings'])
      ->save();
    // enableModules() rebuilt the container; the resolver from setUp() holds
    // the old one.
    $this->resolver = $this->container->get('decoupled_settings.resolver');

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertSame('visible', $resolved['decoupled_settings_test.settings']['safe']);
    $this->assertArrayNotHasKey('secret_blob', $resolved['decoupled_settings_test.settings']);
  }

  /**
   * An undeclared key smuggled into theme settings is not exposed.
   *
   * Core's resolution merges the stored object over its defaults, so a key
   * written straight to storage would ride along without the schema bound.
   * The computed favicon URL survives the same filter: the theme_settings
   * schema type declares it.
   */
  public function testUndeclaredThemeKeyIsNotExposed(): void {
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_settings', TRUE)
      ->save();

    $storage = $this->container->get('config.storage');
    $data = $storage->read('stark.settings') ?: [];
    $data['sneaky'] = 'should not appear';
    $storage->write('stark.settings', $data);

    $resolved = $this->resolver->resolve(NULL, new CacheableMetadata());

    $this->assertArrayNotHasKey('sneaky', $resolved['stark.settings']);
    $this->assertArrayHasKey('url', $resolved['stark.settings']['favicon']);
  }

  /**
   * The install hook backfills consumers that predate the module.
   *
   * A direct database check on purpose: the point of the backfill is the
   * raw column value that Drupal 10 unserializes without a null guard.
   */
  public function testInstallBackfillsNullOverrideColumns(): void {
    $consumer = Consumer::create([
      'client_id' => 'legacy_consumer',
      'label' => 'Legacy consumer',
    ]);
    $consumer->save();

    $connection = $this->container->get('database');
    $connection->update('consumer_field_data')
      ->fields([SettingsResolver::OVERRIDE_FIELD => NULL])
      ->condition('id', $consumer->id())
      ->execute();

    $this->container->get('module_handler')->loadInclude('decoupled_settings', 'install');
    decoupled_settings_install();

    $value = $connection->select('consumer_field_data', 'c')
      ->fields('c', [SettingsResolver::OVERRIDE_FIELD])
      ->condition('id', $consumer->id())
      ->execute()->fetchField();
    $this->assertSame(serialize([]), $value);
  }

  /**
   * A consumer reads its overrides, and inherits everything else.
   */
  public function testConsumerOverridesAndInherits(): void {
    $consumer = Consumer::create([
      'client_id' => 'test_consumer',
      'label' => 'Test consumer',
      SettingsResolver::OVERRIDE_FIELD => [
        'system.site:name' => 'Consumer Site',
      ],
    ]);
    $consumer->save();

    $resolved = $this->resolver->resolve($consumer, new CacheableMetadata());

    $this->assertSame('Consumer Site', $resolved['system.site']['name']);
    $this->assertSame('Global slogan', $resolved['system.site']['slogan']);
  }

  /**
   * A consumer holding no overrides reads the global values.
   */
  public function testConsumerWithoutOverridesInherits(): void {
    $consumer = Consumer::create([
      'client_id' => 'plain_consumer',
      'label' => 'Plain consumer',
    ]);
    $consumer->save();

    $resolved = $this->resolver->resolve($consumer, new CacheableMetadata());

    $this->assertSame('Global Site', $resolved['system.site']['name']);
  }

  /**
   * Resolution collects the cache tags of everything it read.
   *
   * Without these a response does not change when an administrator edits the
   * site information.
   */
  public function testCacheabilityIsCollected(): void {
    $cacheability = new CacheableMetadata();
    $this->resolver->resolve(NULL, $cacheability);

    $this->assertContains('config:system.site', $cacheability->getCacheTags());
    $this->assertContains('config:decoupled_settings.settings', $cacheability->getCacheTags());
  }

  /**
   * A consumer's overrides are part of the response cacheability.
   */
  public function testConsumerCacheabilityIsCollected(): void {
    $consumer = Consumer::create([
      'client_id' => 'cached_consumer',
      'label' => 'Cached consumer',
    ]);
    $consumer->save();

    $cacheability = new CacheableMetadata();
    $this->resolver->resolve($consumer, $cacheability);

    $this->assertNotEmpty(array_intersect(
      $consumer->getCacheTags(),
      $cacheability->getCacheTags()
    ));
  }

}
