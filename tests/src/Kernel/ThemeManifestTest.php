<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the description of the active theme's structure.
 *
 * Regions come from a theme's .info.yml, which needs a real installed theme,
 * so this cannot be a unit test.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\ThemeManifest
 */
class ThemeManifestTest extends KernelTestBase {

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
   * The manifest builder under test.
   *
   * @var \Drupal\decoupled_settings\ThemeManifest
   */
  protected $manifest;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->enableModules(['decoupled_settings']);
    $this->manifest = $this->container->get('decoupled_settings.theme_manifest');
  }

  /**
   * The manifest names the active theme and the object its settings live in.
   *
   * The settings object name is the point. A client reading the settings map
   * cannot otherwise tell which group belongs to the theme.
   */
  public function testNamesTheActiveThemeAndItsSettingsObject(): void {
    $manifest = $this->manifest->build(new CacheableMetadata());

    $this->assertSame('stark', $manifest['default']);
    $this->assertSame('stark.settings', $manifest['settings_object']);
  }

  /**
   * Regions arrive as machine name to label, in the theme's declared order.
   *
   * Order is asserted with assertSame on the key list, not on the set,
   * because the declared order is the only ordering Drupal records and a
   * frontend laying out a page depends on it.
   */
  public function testRegionsKeepTheirDeclaredOrder(): void {
    $manifest = $this->manifest->build(new CacheableMetadata());

    // Stark declares no regions, so core's defaults apply. That is a real
    // case: a theme is not required to declare any.
    $this->assertSame([
      'sidebar_first',
      'sidebar_second',
      'content',
      'header',
      'primary_menu',
      'secondary_menu',
      'footer',
      'highlighted',
      'help',
      'page_top',
      'page_bottom',
      'breadcrumb',
    ], array_keys($manifest['regions']));
    $this->assertSame('Left sidebar', $manifest['regions']['sidebar_first']);
  }

  /**
   * Hidden regions carry the theme's own list plus the two core reserves.
   *
   * Core appends page_top and page_bottom to every theme in
   * system_info_alter(), because modules populate those from outside the
   * block system. So the list is never empty, and a theme's own entries
   * come first.
   */
  public function testHiddenRegionsPassThroughWithTheCoreReserves(): void {
    $this->container->get('theme_installer')->install(['claro']);
    $this->config('system.theme')->set('default', 'claro')->save();
    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata());

    $this->assertSame(
      ['sidebar_first', 'page_top', 'page_bottom'],
      $manifest['regions_hidden']
    );
    $this->assertArrayHasKey('sidebar_first', $manifest['regions']);
  }

  /**
   * A theme that hides nothing itself still reports the two core reserves.
   */
  public function testCoreReservesAreReportedWhenThemeHidesNothing(): void {
    $manifest = $this->manifest->build(new CacheableMetadata());

    $this->assertSame(['page_top', 'page_bottom'], $manifest['regions_hidden']);
  }

  /**
   * Hidden regions are reported beside the regions, never subtracted.
   *
   * A hidden region can be one the theme never declared, so a client cannot
   * treat the list as a subset. Both lists are reported as Drupal records
   * them and the client decides.
   */
  public function testHiddenRegionsAreNotSubtractedFromRegions(): void {
    $manifest = $this->manifest->build(new CacheableMetadata());

    foreach ($manifest['regions_hidden'] as $hidden) {
      $this->assertArrayHasKey($hidden, $manifest['regions']);
    }
    $this->assertArrayHasKey('page_top', $manifest['regions']);
  }

  /**
   * Breakpoints are empty while the breakpoint module is not installed.
   */
  public function testBreakpointsAreEmptyWithoutTheModule(): void {
    $this->assertSame([], $this->manifest->build(new CacheableMetadata())['breakpoints']);
  }

  /**
   * Breakpoints arrive with their media query once the module is installed.
   */
  public function testBreakpointsArriveWithTheModule(): void {
    $this->enableModules(['breakpoint']);
    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata());

    $this->assertSame(
      '(min-width: 0px)',
      $manifest['breakpoints']['stark.mobile']['mediaQuery']
    );
    $this->assertSame('mobile', $manifest['breakpoints']['stark.mobile']['label']);
    $this->assertSame(['1x'], $manifest['breakpoints']['stark.mobile']['multipliers']);
  }

  /**
   * A default theme that is not installed gives nothing, and does not throw.
   */
  public function testUninstalledDefaultThemeGivesNothing(): void {
    $this->config('system.theme')->set('default', 'no_such_theme')->save();

    $this->assertSame([], $this->manifest->build(new CacheableMetadata()));
  }

  /**
   * The manifest carries the cache tags that make it change when it should.
   */
  public function testCacheabilityCoversTheThemeSelection(): void {
    $cacheability = new CacheableMetadata();
    $this->manifest->build($cacheability);

    $this->assertContains('config:system.theme', $cacheability->getCacheTags());
    $this->assertContains('config:core.extension', $cacheability->getCacheTags());
  }

}
