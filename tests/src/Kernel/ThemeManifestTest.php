<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\decoupled_settings\ThemeManifest;
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

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
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
   * A site with no default theme recorded gives nothing, and does not throw.
   */
  public function testNoDefaultThemeGivesNothing(): void {
    $this->config('system.theme')->set('default', '')->save();

    $this->assertSame([], $this->manifest->build(new CacheableMetadata()));
  }

  /**
   * A response carries no manifest until the site exposes one.
   */
  public function testResponseCarriesNothingWhenNotExposed(): void {
    $this->assertNull($this->manifest->forResponse(NULL, new CacheableMetadata()));
  }

  /**
   * Once exposed, a response carries the manifest.
   */
  public function testResponseCarriesTheManifestWhenExposed(): void {
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_manifest', TRUE)
      ->save();

    $manifest = $this->manifest->forResponse(NULL, new CacheableMetadata());

    $this->assertSame('stark', $manifest['default']);
  }

  /**
   * An exposed manifest for a broken theme is NULL, not an empty array.
   *
   * A client reading the attribute gets one answer for "no manifest", however
   * it came about.
   */
  public function testResponseIsNullWhenTheThemeIsUninstalled(): void {
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_manifest', TRUE)
      ->save();
    $this->config('system.theme')->set('default', 'no_such_theme')->save();

    $this->assertNull($this->manifest->forResponse(NULL, new CacheableMetadata()));
  }

  /**
   * The exposure flag is a cacheable dependency of the response.
   *
   * Without it, switching the flag on would not invalidate a cached response.
   */
  public function testTheExposureFlagIsCacheable(): void {
    $cacheability = new CacheableMetadata();
    $this->manifest->forResponse(NULL, $cacheability);

    $this->assertContains(
      'config:decoupled_settings.settings',
      $cacheability->getCacheTags()
    );
  }

  /**
   * A consumer that names a theme gets that theme, not the site default.
   */
  public function testConsumerThemeWinsOverTheSiteDefault(): void {
    $this->container->get('theme_installer')->install(['claro']);
    $consumer = $this->createConsumer('claro');

    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata(), $consumer);

    $this->assertSame('claro', $manifest['default']);
    $this->assertSame('claro.settings', $manifest['settings_object']);
    $this->assertArrayHasKey('sidebar_first', $manifest['regions']);
  }

  /**
   * A consumer that names no theme follows the site default.
   *
   * The same sparse rule the setting overrides use: storing nothing means
   * following the site.
   */
  public function testConsumerWithNoThemeFollowsTheSite(): void {
    $consumer = $this->createConsumer(NULL);

    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata(), $consumer);

    $this->assertSame('stark', $manifest['default']);
  }

  /**
   * A consumer naming an uninstalled theme falls back rather than breaking.
   *
   * An uninstalled theme has no regions or settings to read, so the choice
   * cannot be honoured. Serving the site default beats serving nothing.
   */
  public function testConsumerNamingAnUninstalledThemeFallsBack(): void {
    $consumer = $this->createConsumer('no_such_theme');

    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata(), $consumer);

    $this->assertSame('stark', $manifest['default']);
  }

  /**
   * The admin theme is the site's, whichever theme a consumer renders as.
   *
   * Nothing renders Drupal's admin UI for a consumer, so this is not a
   * per-consumer value.
   */
  public function testAdminThemeStaysTheSitesOwn(): void {
    $this->container->get('theme_installer')->install(['claro']);
    $this->config('system.theme')->set('admin', 'claro')->save();
    $consumer = $this->createConsumer('claro');

    $manifest = $this->container->get('decoupled_settings.theme_manifest')
      ->build(new CacheableMetadata(), $consumer);

    $this->assertSame('claro', $manifest['admin']);
  }

  /**
   * The consumer is a cacheable dependency once its theme is read.
   *
   * Without it, editing a consumer's theme would not invalidate a response.
   */
  public function testTheConsumerIsCacheable(): void {
    $consumer = $this->createConsumer('stark');
    $cacheability = new CacheableMetadata();

    $this->container->get('decoupled_settings.theme_manifest')
      ->build($cacheability, $consumer);

    $this->assertContains(
      'consumer:' . $consumer->id(),
      $cacheability->getCacheTags()
    );
  }

  /**
   * Creates a consumer with a theme choice.
   *
   * @param string|null $theme
   *   The theme machine name, or NULL for no choice.
   *
   * @return \Drupal\consumers\Entity\ConsumerInterface
   *   The saved consumer.
   */
  protected function createConsumer(?string $theme) {
    $values = [
      'client_id' => 'test_app',
      'label' => 'Test app',
    ];
    if ($theme !== NULL) {
      $values[ThemeManifest::THEME_FIELD] = $theme;
    }
    $consumer = Consumer::create($values);
    $consumer->save();

    return $consumer;
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
