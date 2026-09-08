<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * Describes the active theme's structure.
 *
 * A theme declares its regions in its .info.yml file. Regions are not
 * entities and not config, so nothing exposes them over JSON:API. A
 * decoupled frontend that groups blocks by region has to hardcode the region
 * names and the theme they belong to, and those copies drift from the
 * backend without any error.
 *
 * This class reads what Drupal records, and only that. It does not read
 * templates.
 *
 * The manifest is structure, not settings. It is the same for every
 * consumer, so it is not merged with the per-consumer overrides.
 */
final readonly class ThemeManifest {

  /**
   * The name of the theme field on the consumer entity.
   */
  public const string THEME_FIELD = 'decoupled_settings_theme';

  /**
   * Constructs the manifest builder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param object|null $breakpointManager
   *   The breakpoint manager. Typed object on purpose: the service only
   *   exists while the breakpoint module is installed.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private ThemeHandlerInterface $themeHandler,
    private ?object $breakpointManager = NULL,
  ) {}

  /**
   * Builds the manifest a response carries, if the site exposes one.
   *
   * The exposure decision lives here rather than in the transport, so it is
   * decided once no matter how many transports there are.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface|null $consumer
   *   The consumer to describe the theme for, or NULL for the site default.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache tags of everything that is read.
   *
   * @return array|null
   *   The manifest, or NULL when it is not exposed.
   */
  public function forResponse(?ConsumerInterface $consumer, CacheableMetadata $cacheability): ?array {
    $settings = $this->configFactory->get('decoupled_settings.settings');
    $cacheability->addCacheableDependency($settings);
    if (!$settings->get('expose_theme_manifest')) {
      return NULL;
    }

    return $this->build($cacheability, $consumer) ?: NULL;
  }

  /**
   * Builds the manifest for the site's active theme.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache tags of everything that is read.
   * @param \Drupal\consumers\Entity\ConsumerInterface|null $consumer
   *   The consumer whose theme to describe, or NULL for the site default.
   *
   * @return array
   *   The manifest. Empty if the theme is not installed.
   */
  public function build(CacheableMetadata $cacheability, ?ConsumerInterface $consumer = NULL): array {
    // A theme is added or removed by installing it, which rewrites
    // core.extension. The .info.yml itself only changes with the code.
    $cacheability->addCacheTags(['config:core.extension']);

    $default = $this->themeFor($consumer, $cacheability);
    $info = $this->themeInfo($default);
    if ($info === NULL) {
      return [];
    }

    $regions = $info['regions'] ?? [];

    return [
      'default' => $default,
      // The admin theme is named, not read. Its settings are exposed only
      // if an administrator lists the object explicitly. It is the site's
      // admin theme in every case: a consumer chooses what it renders as,
      // and nothing renders Drupal's admin UI for a consumer.
      'admin' => (string) ($this->configFactory->get('system.theme')->get('admin') ?? '') ?: NULL,
      // The config object the theme's own settings are read from. A client
      // reads the settings group under this name instead of guessing which
      // group belongs to the theme. Naming it does not expose it: the group
      // is absent unless the theme settings are exposed too.
      'settings_object' => $default . '.settings',
      // Machine name to label, in the order the theme declares them. That
      // order is the only ordering Drupal records.
      'regions' => array_map(strval(...), $regions),
      // Regions the theme hides from the block layout screen. Core appends
      // page_top and page_bottom to every theme in system_info_alter(), so
      // this list is never empty and can name a region the theme does not
      // declare. It is passed through as recorded, not subtracted from the
      // regions above, because a client may have its own reason to render
      // one.
      'regions_hidden' => array_values($info['regions_hidden'] ?? []),
      'breakpoints' => $this->breakpoints($default),
    ];
  }

  /**
   * Resolves the theme a consumer renders as.
   *
   * A consumer that names no theme follows the site default, which is the
   * same sparse rule the setting overrides use: storing nothing means
   * following the site.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface|null $consumer
   *   The consumer, or NULL for the site default.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache tags of everything that is read.
   *
   * @return string
   *   The theme machine name, which may be empty on a broken site.
   */
  public function themeFor(?ConsumerInterface $consumer, CacheableMetadata $cacheability): string {
    if ($consumer instanceof ConsumerInterface && $consumer->hasField(self::THEME_FIELD)) {
      $cacheability->addCacheableDependency($consumer);
      $chosen = (string) ($consumer->get(self::THEME_FIELD)->value ?? '');
      // An uninstalled theme has no regions or settings to read, so it is
      // treated as no choice at all rather than as a broken response.
      if ($chosen !== '' && $this->themeInfo($chosen) !== NULL) {
        return $chosen;
      }
    }

    $system_theme = $this->configFactory->get('system.theme');
    $cacheability->addCacheableDependency($system_theme);

    return (string) ($system_theme->get('default') ?? '');
  }

  /**
   * Reads one theme's .info.yml data.
   *
   * @return array|null
   *   The theme info, or NULL if the theme is not installed.
   */
  private function themeInfo(string $theme): ?array {
    if ($theme === '') {
      return NULL;
    }
    try {
      return $this->themeHandler->getTheme($theme)->info ?? NULL;
    }
    catch (\Throwable) {
      // The default theme names a theme that is not installed. That is a
      // broken site, but it must not break this endpoint.
      return NULL;
    }
  }

  /**
   * Reads the breakpoints a theme declares.
   *
   * Breakpoints live in a separate .breakpoints.yml file and are only
   * readable while the breakpoint module is installed.
   *
   * @return array
   *   Breakpoint id to its label, media query and multipliers. Empty when
   *   the module is not installed or the theme declares none.
   */
  private function breakpoints(string $theme): array {
    $manager = $this->breakpointManager;
    if ($manager === NULL || !method_exists($manager, 'getBreakpointsByGroup')) {
      return [];
    }

    $breakpoints = [];
    foreach ($manager->getBreakpointsByGroup($theme) as $id => $breakpoint) {
      $breakpoints[$id] = [
        'label' => (string) $breakpoint->getLabel(),
        'mediaQuery' => $breakpoint->getMediaQuery(),
        'weight' => (int) $breakpoint->getWeight(),
        'multipliers' => array_values($breakpoint->getMultipliers()),
      ];
    }

    return $breakpoints;
  }

}
