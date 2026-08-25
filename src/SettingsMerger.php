<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings;

/**
 * Merges a consumer's overrides over the global values.
 *
 * This class has no Drupal dependencies on purpose. The merge is the part of
 * the module that has to be exactly right, so it is kept where it can be
 * tested on its own.
 *
 * Overrides are flat and sparse. The key is the config object name and the
 * setting path joined by a colon, for example "system.site:name" or
 * "system.site:page.front". A key that is absent is inherited.
 */
final class SettingsMerger {

  /**
   * Applies the overrides of one config object over its global values.
   *
   * @param string $name
   *   The config object name, used to build override keys.
   * @param array $global
   *   The global values, nested as the config object stores them.
   * @param array $overrides
   *   Every override the consumer holds, flat and across all objects.
   *
   * @return array
   *   The resolved values, nested like the input.
   */
  public function merge(string $name, array $global, array $overrides): array {
    $resolved = [];

    foreach ($this->flatten($global) as $path => $value) {
      $key = $name . ':' . $path;
      // array_key_exists(), not isset(). A consumer can override a setting to
      // an empty string, to zero, or to NULL. That is a deliberate value and
      // it must not fall back to the global one.
      $resolved[$path] = array_key_exists($key, $overrides) ? $overrides[$key] : $value;
    }

    return $this->expand($resolved);
  }

  /**
   * Lists the override keys that belong to one config object.
   *
   * Used to drop overrides for settings that are no longer exposed.
   */
  public function keysFor(string $name, array $overrides): array {
    $prefix = $name . ':';

    return array_keys(array_filter(
      $overrides,
      static fn (string $key): bool => str_starts_with($key, $prefix),
      ARRAY_FILTER_USE_KEY
    ));
  }

  /**
   * Removes every override whose setting is not in the resolved set.
   *
   * @param array $overrides
   *   Every override the consumer holds.
   * @param array $exposed
   *   The resolved settings, keyed by config object name.
   *
   * @return array
   *   The overrides that are still exposed.
   */
  public function pruneUnexposed(array $overrides, array $exposed): array {
    $allowed = [];
    foreach ($exposed as $name => $values) {
      foreach (array_keys($this->flatten($values)) as $path) {
        $allowed[$name . ':' . $path] = TRUE;
      }
    }

    return array_intersect_key($overrides, $allowed);
  }

  /**
   * Turns a nested array into colon free, dot separated paths.
   *
   * A list, such as a sequence of strings, is treated as one value. Merging
   * into a list per index would let a consumer hold half a list, which is
   * harder to reason about than replacing it whole.
   */
  public function flatten(array $data, string $prefix = ''): array {
    $flat = [];

    foreach ($data as $key => $value) {
      $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

      if (is_array($value) && $value !== [] && !array_is_list($value)) {
        $flat += $this->flatten($value, $path);
        continue;
      }

      $flat[$path] = $value;
    }

    return $flat;
  }

  /**
   * Turns dot separated paths back into a nested array.
   */
  public function expand(array $flat): array {
    $nested = [];

    foreach ($flat as $path => $value) {
      $parts = explode('.', (string) $path);
      $leaf = array_pop($parts);

      $cursor = &$nested;
      foreach ($parts as $part) {
        if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
          $cursor[$part] = [];
        }
        $cursor = &$cursor[$part];
      }
      $cursor[$leaf] = $value;
      unset($cursor);
    }

    return $nested;
  }

}
