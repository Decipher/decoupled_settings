<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests reading theme settings through core's own resolution.
 *
 * The version shim and the resolved logo and favicon URLs need a real
 * installed theme, so they cannot be covered by the unit test.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\ThemeSettingsReader
 */
class ThemeSettingsReaderTest extends KernelTestBase {

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
  ];

  /**
   * The reader under test.
   *
   * @var \Drupal\decoupled_settings\ThemeSettingsReader
   */
  protected $reader;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->enableModules(['decoupled_settings']);
    $this->reader = $this->container->get('decoupled_settings.theme_settings_reader');
  }

  /**
   * An installed theme's settings object is recognised.
   */
  public function testInstalledThemeIsRecognised(): void {
    $this->assertTrue($this->reader->isThemeSettings('stark.settings'));
    $this->assertFalse($this->reader->isThemeSettings('system.site'));
  }

  /**
   * The active theme is read from system.theme.
   */
  public function testActiveThemeConfigName(): void {
    $this->assertSame(
      'stark.settings',
      $this->reader->activeThemeConfigName(new CacheableMetadata())
    );
  }

  /**
   * Reading returns the merged settings, not just the theme's own config.
   *
   * Core seeds from system.theme.global before merging the theme's own
   * settings over it, so keys the theme never declared are still present.
   */
  public function testReadReturnsMergedSettings(): void {
    $settings = $this->reader->read('stark.settings', new CacheableMetadata());

    $this->assertNotEmpty($settings);
    $this->assertArrayHasKey('logo', $settings);
    $this->assertArrayHasKey('favicon', $settings);
  }

  /**
   * Logo and favicon come back as resolved URLs, not raw config paths.
   *
   * This is the behaviour jsonapi_site gets wrong, so it is asserted
   * explicitly rather than inferred.
   */
  public function testLogoAndFaviconAreResolvedUrls(): void {
    $settings = $this->reader->read('stark.settings', new CacheableMetadata());

    $this->assertArrayHasKey('url', $settings['logo']);
    $this->assertArrayHasKey('url', $settings['favicon']);
    $this->assertIsString($settings['favicon']['url']);
    $this->assertNotSame('', $settings['favicon']['url']);
  }

  /**
   * A second theme resolves its own settings, matching core exactly.
   *
   * The reader must not be fixed to the active theme. Each theme's logo
   * resolves to that theme's own file.
   */
  public function testSecondThemeResolvesItsOwnSettings(): void {
    $this->container->get('theme_installer')->install(['olivero']);

    $stark = $this->reader->read('stark.settings', new CacheableMetadata());
    $olivero = $this->reader->read('olivero.settings', new CacheableMetadata());

    $this->assertNotSame(
      $stark['logo']['url'],
      $olivero['logo']['url'],
      'Each theme resolves its own logo.'
    );

    // The reader must return what core itself returns for that theme.
    // ThemeSettingsProvider exists from 11.3 only, so the comparison goes
    // through whichever reader core provides.
    // Resolved by string id: the class only exists from Drupal 11.3, so
    // naming it would not analyse on Drupal 10.
    $provider_id = 'Drupal\\Core\\Extension\\ThemeSettingsProvider';
    if ($this->container->has($provider_id)) {
      $provider = $this->container->get($provider_id);
      if (!method_exists($provider, 'getSetting')) {
        $this->fail('The theme settings provider lost its getter.');
      }
      $expected = $provider->getSetting('', 'olivero');
    }
    else {
      // @phpstan-ignore-next-line Deprecated on 11.3, required below it.
      $expected = theme_get_setting('', 'olivero');
    }
    $this->assertSame(
      $expected,
      $olivero,
      'The reader matches core resolution for a theme that is not active.'
    );
  }

  /**
   * Reading collects the cache tags core uses for theme settings.
   */
  public function testReadCollectsCacheTags(): void {
    $cacheability = new CacheableMetadata();
    $this->reader->read('stark.settings', $cacheability);

    $tags = $cacheability->getCacheTags();
    $this->assertContains('config:core.extension', $tags);
    $this->assertContains('config:system.theme.global', $tags);
    $this->assertContains('config:stark.settings', $tags);
  }

  /**
   * Reading a theme that is not installed does not error.
   */
  public function testReadUninstalledThemeIsSafe(): void {
    $cacheability = new CacheableMetadata();
    $this->reader->read('notatheme.settings', $cacheability);

    // The call must not throw, and must still declare what it depended on so
    // the response is invalidated if that theme is later installed.
    $this->assertContains('config:notatheme.settings', $cacheability->getCacheTags());
  }

}
