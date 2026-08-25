<?php

/**
 * @file
 * Hooks provided by the Decoupled Settings module.
 */

declare(strict_types=1);

use Drupal\Core\Cache\CacheableMetadata;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Contribute or change the global settings, before consumer overrides.
 *
 * Use this to expose settings that are not simple config, such as values a
 * module computes. A contributed group is keyed like a config object, and its
 * keys are not filtered through typed config schema, so the module that adds
 * it is responsible for exposing only what is safe.
 *
 * This hook runs before the consumer overrides are merged. A setting added
 * here is therefore overridable per consumer exactly like one read from
 * config, with no extra work. Altering the resolved values after the merge is
 * deliberately not possible, because that would let a module silently discard
 * a consumer's override.
 *
 * Anything read while building the values must be declared on $cacheability,
 * or a response can be served from cache after the underlying data changed.
 *
 * @param array $globals
 *   The global settings, keyed by group name and then by setting path.
 * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
 *   Collects the cache tags and contexts the contributed values depend on.
 *
 * @see \Drupal\decoupled_settings\SettingsResolver::resolve()
 */
function hook_decoupled_settings_global_alter(array &$globals, CacheableMetadata $cacheability): void {
  // Expose the region order of the active theme, which is not simple config.
  $theme = \Drupal::config('system.theme')->get('default');
  $cacheability->addCacheTags(['config:system.theme', 'config:core.extension']);

  $globals['example_regions'] = [
    'order' => ['header', 'primary_menu', 'content', 'footer'],
    'theme' => $theme,
  ];

  // A consumer can now override "example_regions:order" to render the same
  // site with a different region order, without a copy of anything else.
}

/**
 * @} End of "addtogroup hooks".
 */
