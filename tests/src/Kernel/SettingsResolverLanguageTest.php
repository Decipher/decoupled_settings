<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Pins how language negotiation and consumer overrides interact.
 *
 * Exposed values follow the config override language, so a translated
 * setting is served in that language. A consumer override does not: it
 * replaces the setting in every language. Per-language overrides are
 * planned for 1.1.0, and this test is the baseline they start from.
 *
 * @group decoupled_settings
 */
class SettingsResolverLanguageTest extends KernelTestBase {

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
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'language', 'decoupled_settings']);

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->config('system.site')->set('name', 'Global Site')->save();
    $language_manager = $this->container->get('language_manager');
    assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->getLanguageConfigOverride('fr', 'system.site')
      ->set('name', 'Site Global')
      ->save();

    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site'])
      ->set('expose_theme_settings', FALSE)
      ->save();

    Consumer::create([
      'client_id' => 'partner',
      'label' => 'Partner',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Partner Portal'],
    ])->save();
  }

  /**
   * Resolves the global values under a config override language.
   */
  protected function resolveIn(string $langcode, ?Consumer $consumer, CacheableMetadata $cacheability): array {
    $language_manager = $this->container->get('language_manager');
    assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->setConfigOverrideLanguage($language_manager->getLanguage($langcode));
    $this->container->get('config.factory')->reset();

    return $this->container->get('decoupled_settings.resolver')->resolve($consumer, $cacheability);
  }

  /**
   * A translated setting is served in the negotiated language.
   */
  public function testTranslationFollowsTheOverrideLanguage(): void {
    $cacheability = new CacheableMetadata();

    $this->assertSame('Global Site', $this->resolveIn('en', NULL, $cacheability)['system.site']['name']);
    $this->assertSame('Site Global', $this->resolveIn('fr', NULL, $cacheability)['system.site']['name']);
    $this->assertContains('languages:language_interface', $cacheability->getCacheContexts());
  }

  /**
   * A consumer override replaces the setting in every language.
   */
  public function testConsumerOverrideAppliesInEveryLanguage(): void {
    $consumer = $this->container->get('entity_type.manager')
      ->getStorage('consumer')
      ->loadByProperties(['client_id' => 'partner']);
    $consumer = reset($consumer);
    assert($consumer instanceof Consumer);
    $cacheability = new CacheableMetadata();

    $this->assertSame('Partner Portal', $this->resolveIn('en', $consumer, $cacheability)['system.site']['name']);
    $this->assertSame('Partner Portal', $this->resolveIn('fr', $consumer, $cacheability)['system.site']['name']);
  }

}
