<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads theme settings the way core resolves them.
 *
 * Core does more than read <theme>.settings. It seeds from
 * system.theme.global, merges the theme's own settings over it, removes the
 * settings the theme does not declare, and resolves logo.url and favicon.url
 * to real file URLs with fallbacks.
 *
 * Reading the config object directly gives an unresolved path that is also
 * blind to which theme is active. This class always goes through core.
 *
 * ThemeSettingsProvider exists from Drupal 11.3. theme_get_setting() is
 * deprecated in 11.3 and removed in 13.0. This class uses whichever is
 * available, so the module runs on both majors.
 */
final readonly class ThemeSettingsReader {

  /**
   * Constructs the reader.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param object|null $themeSettingsProvider
   *   Core's ThemeSettingsProvider. Typed object on purpose: the class only
   *   exists from Drupal 11.3, so naming it would not analyse on Drupal 10.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private ?object $themeSettingsProvider = NULL,
  ) {}

  /**
   * Tells whether a config name is a theme settings object.
   */
  public function isThemeSettings(string $name): bool {
    return str_ends_with($name, '.settings')
      && $this->themeExists($this->themeFromConfigName($name));
  }

  /**
   * Returns the config object name for the site's active theme.
   */
  public function activeThemeConfigName(CacheableMetadata $cacheability): string {
    $theme = $this->configFactory->get('system.theme');
    $cacheability->addCacheableDependency($theme);

    return $theme->get('default') . '.settings';
  }

  /**
   * Reads the resolved settings of one theme.
   *
   * @return array
   *   The merged theme settings, including resolved logo.url and
   *   favicon.url.
   */
  public function read(string $name, CacheableMetadata $cacheability): array {
    $theme = $this->themeFromConfigName($name);

    // These are the tags core itself uses for its theme settings cache entry.
    // Without them a response does not change when an administrator edits the
    // theme settings.
    $cacheability->addCacheTags([
      'config:core.extension',
      'config:system.theme.global',
      'config:' . $name,
    ]);

    $settings = $this->allSettings($theme);

    return is_array($settings) ? $settings : [];
  }

  /**
   * Gets every merged setting for a theme.
   *
   * Passing an empty name to the getter returns the whole merged set, so the
   * merge does not need to be repeated here.
   */
  private function allSettings(string $theme): mixed {
    $provider = $this->themeSettingsProvider;
    if ($provider !== NULL && method_exists($provider, 'getSetting')) {
      return $provider->getSetting('', $theme);
    }

    // Cores before 11.3 have no ThemeSettingsProvider. theme_get_setting()
    // is deprecated there but is the only reader available, so this branch
    // must stay a function call. Rector's ReplaceThemeGetSettingRector is
    // skipped for exactly this reason.
    // @phpstan-ignore-next-line Deprecated on 11.3, required below it.
    return theme_get_setting('', $theme);
  }

  /**
   * Gets the theme name from a config object name.
   */
  private function themeFromConfigName(string $name): string {
    return substr($name, 0, -strlen('.settings'));
  }

  /**
   * Tells whether a theme is installed.
   */
  private function themeExists(string $theme): bool {
    $installed = $this->configFactory->get('core.extension')->get('theme') ?: [];

    return array_key_exists($theme, $installed);
  }

}
