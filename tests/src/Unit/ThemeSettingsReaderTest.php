<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\decoupled_settings\ThemeSettingsReader;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the parts of the theme settings reader that need no Drupal bootstrap.
 *
 * Reading the merged settings themselves needs an installed theme, so that
 * is covered by the kernel test instead.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\ThemeSettingsReader
 */
class ThemeSettingsReaderTest extends UnitTestCase {

  /**
   * Builds a reader whose config factory returns the given data.
   *
   * @param array $configs
   *   Config data keyed by config object name.
   *
   * @return \Drupal\decoupled_settings\ThemeSettingsReader
   *   The reader.
   */
  protected function readerWith(array $configs): ThemeSettingsReader {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(
      function (string $name) use ($configs): ImmutableConfig {
        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnCallback(
          static fn (string $key = '') => $configs[$name][$key] ?? NULL
        );
        // addCacheableDependency() reads all three, and a mock returns NULL
        // for anything not stubbed.
        $config->method('getCacheContexts')->willReturn([]);
        $config->method('getCacheTags')->willReturn(['config:' . $name]);
        $config->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
        return $config;
      }
    );

    return new ThemeSettingsReader($factory);
  }

  /**
   * A theme settings object of an installed theme is recognised.
   */
  public function testInstalledThemeSettingsIsRecognised(): void {
    $reader = $this->readerWith([
      'core.extension' => ['theme' => ['olivero' => 0, 'claro' => 0]],
    ]);

    $this->assertTrue($reader->isThemeSettings('olivero.settings'));
    $this->assertTrue($reader->isThemeSettings('claro.settings'));
  }

  /**
   * A settings object of a theme that is not installed is not recognised.
   *
   * Without this check any config object ending in ".settings" would be
   * routed through the theme reader.
   */
  public function testUninstalledThemeSettingsIsNotRecognised(): void {
    $reader = $this->readerWith([
      'core.extension' => ['theme' => ['olivero' => 0]],
    ]);

    $this->assertFalse($reader->isThemeSettings('notatheme.settings'));
  }

  /**
   * A config object that is not theme settings is not recognised.
   */
  public function testNonThemeConfigIsNotRecognised(): void {
    $reader = $this->readerWith([
      'core.extension' => ['theme' => ['olivero' => 0]],
    ]);

    $this->assertFalse($reader->isThemeSettings('system.site'));
    $this->assertFalse($reader->isThemeSettings('system.theme'));
  }

  /**
   * A module settings object is not mistaken for theme settings.
   *
   * Module config commonly ends in ".settings" too, so the installed-theme
   * check is what separates them.
   */
  public function testModuleSettingsIsNotRecognised(): void {
    $reader = $this->readerWith([
      'core.extension' => ['theme' => ['olivero' => 0]],
    ]);

    $this->assertFalse($reader->isThemeSettings('decoupled_settings.settings'));
  }

  /**
   * The active theme's config object name is derived from system.theme.
   */
  public function testActiveThemeConfigName(): void {
    $reader = $this->readerWith([
      'system.theme' => ['default' => 'olivero'],
    ]);

    $this->assertSame(
      'olivero.settings',
      $reader->activeThemeConfigName(new CacheableMetadata())
    );
  }

  /**
   * Reading the active theme name records the config it depended on.
   */
  public function testActiveThemeConfigNameCollectsCacheability(): void {
    $reader = $this->readerWith([
      'system.theme' => ['default' => 'olivero'],
    ]);

    $cacheability = new CacheableMetadata();
    $reader->activeThemeConfigName($cacheability);

    $this->assertContains('config:system.theme', $cacheability->getCacheTags());
  }

}
