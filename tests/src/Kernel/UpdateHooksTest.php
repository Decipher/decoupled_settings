<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\consumers\Entity\ConsumerInterface;
use Drupal\decoupled_settings\ThemeManifest;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the update hooks that bring a 1.0.0-beta2 site up to date.
 *
 * Every existing site runs these once, on upgrade, so each one is run here
 * against the state a beta2 site is in.
 *
 * @group decoupled_settings
 */
class UpdateHooksTest extends KernelTestBase {

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
    'decoupled_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'decoupled_settings']);
    $this->container->get('module_handler')->loadInclude('decoupled_settings', 'install');
  }

  /**
   * A beta2 site gets the manifest flag, switched off.
   *
   * Beta2 had no such key. Exposure stays a decision an administrator makes,
   * so the update adds it as FALSE rather than turning the manifest on.
   */
  public function testManifestFlagIsAddedSwitchedOff(): void {
    $this->config('decoupled_settings.settings')->clear('expose_theme_manifest')->save();
    $this->assertNull($this->config('decoupled_settings.settings')->get('expose_theme_manifest'));

    $message = decoupled_settings_update_10002();

    $this->assertFalse($this->config('decoupled_settings.settings')->get('expose_theme_manifest'));
    $this->assertSame('The theme manifest is available on the settings form, switched off.', $message);
  }

  /**
   * A flag that is already set is left as it is.
   *
   * A site that installed a development snapshot may already have switched
   * the manifest on. Running the update must not switch it off again.
   */
  public function testManifestFlagThatIsSetIsKept(): void {
    $this->config('decoupled_settings.settings')->set('expose_theme_manifest', TRUE)->save();

    $message = decoupled_settings_update_10002();

    $this->assertTrue($this->config('decoupled_settings.settings')->get('expose_theme_manifest'));
    $this->assertSame('The theme manifest flag was already set.', $message);
  }

  /**
   * The theme field is installed, and an existing consumer follows the site.
   *
   * A beta2 site has consumers but no theme column. After the update the
   * column exists, an existing consumer holds no theme, which means it
   * follows the site default, and a theme can be stored on it.
   */
  public function testThemeFieldIsInstalledForExistingConsumers(): void {
    $update_manager = $this->container->get('entity.definition_update_manager');
    $update_manager->uninstallFieldStorageDefinition(
      $update_manager->getFieldStorageDefinition(ThemeManifest::THEME_FIELD, 'consumer')
    );
    $this->assertFalse($this->container->get('database')->schema()
      ->fieldExists('consumer_field_data', ThemeManifest::THEME_FIELD));

    $consumer = Consumer::create([
      'client_id' => 'existing_app',
      'label' => 'Existing app',
    ]);
    $consumer->save();

    $message = decoupled_settings_update_10003();

    $this->assertSame('Consumers can now select the theme they render as.', $message);
    $this->assertTrue($this->container->get('database')->schema()
      ->fieldExists('consumer_field_data', ThemeManifest::THEME_FIELD));

    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache();
    $consumer = $storage->load($consumer->id());
    $this->assertInstanceOf(ConsumerInterface::class, $consumer);
    $this->assertTrue($consumer->get(ThemeManifest::THEME_FIELD)->isEmpty());

    $consumer->set(ThemeManifest::THEME_FIELD, 'stark')->save();
    $storage->resetCache();
    $reloaded = $storage->load($consumer->id());
    $this->assertInstanceOf(ConsumerInterface::class, $reloaded);
    $this->assertSame('stark', $reloaded->get(ThemeManifest::THEME_FIELD)->value);
  }

  /**
   * The installed field matches the code, so nothing is left to update.
   *
   * The update hook carries its own copy of the field definition. If it
   * drifted from the one in hook_entity_base_field_info(), the status report
   * would show a mismatched entity definition after every upgrade.
   */
  public function testThemeFieldUpdateLeavesNoMismatch(): void {
    $update_manager = $this->container->get('entity.definition_update_manager');
    $update_manager->uninstallFieldStorageDefinition(
      $update_manager->getFieldStorageDefinition(ThemeManifest::THEME_FIELD, 'consumer')
    );
    $this->assertArrayHasKey(ThemeManifest::THEME_FIELD, $this->consumerFieldChanges());

    decoupled_settings_update_10003();

    $this->assertArrayNotHasKey(ThemeManifest::THEME_FIELD, $this->consumerFieldChanges());
  }

  /**
   * Lists the consumer fields whose stored definition differs from the code.
   *
   * Only the consumer entity type is checked. A kernel test leaves other
   * core entity types uninstalled, so the whole change list is never empty.
   *
   * @return array
   *   Pending changes keyed by field name.
   */
  protected function consumerFieldChanges(): array {
    $changes = $this->container->get('entity.definition_update_manager')->getChangeList();

    return $changes['consumer']['field_storage_definitions'] ?? [];
  }

}
