<?php

declare(strict_types=1);

namespace Drupal\decoupled_settings\Plugin\Field\FieldType;

use Drupal\Core\Field\Plugin\Field\FieldType\MapItem;

/**
 * A map that persists even when it holds nothing.
 *
 * Drupal 10's shared-table storage unserializes a serialized column without
 * a null guard, so a plain map field that was never set deprecation-warns on
 * every entity load. An item of this type never reports empty, so an empty
 * override set is stored as an empty array rather than as NULL.
 *
 * @FieldType(
 *   id = "decoupled_settings_overrides",
 *   label = @Translation("Setting overrides"),
 *   description = @Translation("Sparse per-consumer setting overrides."),
 *   no_ui = TRUE,
 *   list_class = "\Drupal\Core\Field\MapFieldItemList",
 * )
 */
class OverridesItem extends MapItem {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return FALSE;
  }

}
