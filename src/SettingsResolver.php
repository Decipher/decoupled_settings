<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings;

use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves the settings a consumer reads.
 *
 * The module does not store global values. It reads them from the config
 * objects that already hold them. A consumer stores only the settings it
 * overrides. This service merges the two, one key at a time.
 *
 * This service has no HTTP types in its signature. A transport, such as the
 * JSON:API resource, is an adapter over it.
 */
final readonly class SettingsResolver {

  /**
   * The name of the override field on the consumer entity.
   */
  public const string OVERRIDE_FIELD = 'decoupled_settings_overrides';

  /**
   * Config keys that are plumbing, never settings, and are always dropped.
   */
  private const array INTERNAL_KEYS = ['_core', 'uuid'];

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private TypedConfigManagerInterface $typedConfigManager,
    private ThemeSettingsReader $themeSettingsReader,
    private SettingsMerger $settingsMerger,
    private ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Resolves every exposed setting for one consumer.
   *
   * @param \Drupal\consumers\Entity\ConsumerInterface|null $consumer
   *   The consumer to resolve for. Pass NULL to get the global values.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache tags and contexts of everything that is read.
   *
   * @return array
   *   Settings keyed by config object name, then by key.
   */
  public function resolve(?ConsumerInterface $consumer, CacheableMetadata $cacheability): array {
    $settings = $this->configFactory->get('decoupled_settings.settings');
    $cacheability->addCacheableDependency($settings);

    $globals = $this->globals(
      $settings->get('exposed_objects') ?: [],
      (bool) $settings->get('expose_theme_settings'),
      $settings->get('excluded_keys') ?: [],
      $cacheability
    );

    $overrides = $this->overridesFor($consumer, $cacheability);
    $resolved = [];
    foreach ($globals as $name => $values) {
      $resolved[$name] = $this->settingsMerger->merge($name, $values, $overrides);
    }

    return $resolved;
  }

  /**
   * Builds the global settings for an explicit exposure selection.
   *
   * The administrative form uses this to show the effect of selections that
   * are not saved yet. The hook runs here too, so the review matches what a
   * frontend would read.
   *
   * @param array $objects
   *   The config object names to expose.
   * @param bool $include_theme
   *   Whether to add the active theme's settings.
   * @param array $excluded_keys
   *   Keys never exposed, as "object:path" strings.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects the cache tags and contexts of everything that is read.
   *
   * @return array
   *   Global settings keyed by config object name, then by key.
   */
  public function globals(array $objects, bool $include_theme, array $excluded_keys, CacheableMetadata $cacheability): array {
    if ($include_theme) {
      $objects[] = $this->themeSettingsReader->activeThemeConfigName($cacheability);
    }
    $objects = array_values(array_unique(array_filter($objects)));

    $globals = [];
    foreach ($objects as $name) {
      $values = $this->readObject($name, $excluded_keys, $cacheability);
      if ($values !== []) {
        $globals[$name] = $values;
      }
    }

    // Let other modules contribute groups of settings that are not config
    // objects. The hook runs before the merge, not after, so a contributed
    // setting is overridable per consumer exactly like a config-derived one.
    $this->moduleHandler->alter('decoupled_settings_global', $globals, $cacheability);

    return $globals;
  }

  /**
   * Reads one config object, keeping only the keys its schema declares.
   *
   * Theme settings go through the theme reader instead, because core resolves
   * logo and favicon URLs there. Reading the raw config object would give an
   * unresolved path, which is the mistake jsonapi_site makes.
   */
  private function readObject(string $name, array $excluded_keys, CacheableMetadata $cacheability): array {
    if ($this->themeSettingsReader->isThemeSettings($name)) {
      return $this->removeExcluded($name, $excluded_keys, $this->themeSettingsReader->read($name, $cacheability));
    }

    $config = $this->configFactory->get($name);
    $cacheability->addCacheableDependency($config);

    $data = $config->get();
    if (!is_array($data) || $data === []) {
      return [];
    }

    return $this->removeExcluded($name, $excluded_keys, $this->filterBySchema($name, $data));
  }

  /**
   * Drops keys that are internal, or that an administrator excluded.
   *
   * Typed config schema bounds the shape, not the sensitivity. Some
   * schema-declared keys are plumbing, such as uuid and _core, and some are
   * real but unwanted, such as the site email address. Neither belongs in a
   * frontend payload by default.
   */
  private function removeExcluded(string $name, array $excluded, array $data): array {
    $flat = $this->settingsMerger->flatten($data);

    foreach (array_keys($flat) as $path) {
      $first = explode('.', (string) $path)[0];
      if (in_array($first, self::INTERNAL_KEYS, TRUE)) {
        unset($flat[$path]);
        continue;
      }
      if (in_array($name . ':' . $path, $excluded, TRUE)) {
        unset($flat[$path]);
      }
    }

    return $this->settingsMerger->expand($flat);
  }

  /**
   * Removes every key that the typed config schema does not declare.
   *
   * A key that a theme or a module never documented does not appear. This is
   * what removes the need for a key list inside each exposed object.
   */
  private function filterBySchema(string $name, array $data): array {
    if (!$this->typedConfigManager->hasConfigSchema($name)) {
      // With no schema there is nothing to bound the keys, so expose nothing.
      return [];
    }

    $element = $this->typedConfigManager->get($name);
    if (!$element instanceof ArrayElement) {
      return [];
    }

    return $this->filterElement($element, $data);
  }

  /**
   * Walks one schema element and keeps the values it declares.
   *
   * The getElements() method returns an element for every key in the data,
   * not only the keys the schema declares. A key the schema does not declare
   * comes back typed as "undefined". That type is the test for an
   * undocumented key.
   */
  private function filterElement(ArrayElement $element, array $data): array {
    $kept = [];

    foreach ($element->getElements() as $key => $child) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      if ($child->getDataDefinition()->getDataType() === 'undefined') {
        continue;
      }
      $value = $data[$key];

      if ($child instanceof ArrayElement && is_array($value)) {
        $nested = $this->filterElement($child, $value);
        if ($nested !== []) {
          $kept[$key] = $nested;
        }
        continue;
      }

      $kept[$key] = $value;
    }

    return $kept;
  }

  /**
   * Reads the overrides a consumer holds.
   *
   * Overrides are flat. The key is the config object name and the setting key
   * joined by a dot, for example "system.site:name".
   */
  private function overridesFor(?ConsumerInterface $consumer, CacheableMetadata $cacheability): array {
    if (!$consumer instanceof ConsumerInterface || !$consumer->hasField(self::OVERRIDE_FIELD)) {
      return [];
    }
    $cacheability->addCacheableDependency($consumer);

    $field = $consumer->get(self::OVERRIDE_FIELD);
    if ($field->isEmpty()) {
      return [];
    }

    $stored = $field->first()->getValue();
    return is_array($stored) ? $stored : [];
  }

}
