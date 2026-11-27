<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\ThemeManifest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the theme select on the consumer edit form.
 *
 * The field stores a machine name, so the form offers the installed themes
 * instead of a free-text box that could name a theme with no regions.
 *
 * @group decoupled_settings
 */
class ConsumerFormThemeTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('file');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'consumers', 'decoupled_settings']);
    $this->container->get('theme_installer')->install(['stark', 'claro']);
  }

  /**
   * The theme field is a select of the installed themes.
   */
  public function testThemeFieldOffersTheInstalledThemes(): void {
    $this->setUpCurrentUser([], [
      'administer consumer entities',
      'administer decoupled settings',
    ]);

    $element = $this->themeElement();

    $this->assertSame('select', $element['#type']);
    $this->assertArrayNotHasKey('#size', $element);
    $this->assertSame(['claro' => 'Claro', 'stark' => 'Stark'], $this->themeOptions($element));
  }

  /**
   * Choosing nothing follows the site default, and the select says so.
   */
  public function testEmptyChoiceFollowsTheSiteDefault(): void {
    $this->setUpCurrentUser([], [
      'administer consumer entities',
      'administer decoupled settings',
    ]);

    $element = $this->themeElement();

    $this->assertSame('- Follow the site default -', (string) $element['#empty_option']);
    $this->assertSame('', $element['#empty_value']);
    // Form processing adds the empty choice to the options, first.
    $this->assertSame('', array_key_first($element['#options']));
  }

  /**
   * A theme that is not installed is not offered.
   *
   * An uninstalled theme has no regions or settings to read, so a consumer
   * cannot be pointed at one.
   */
  public function testUninstalledThemeIsNotOffered(): void {
    $this->container->get('theme_installer')->uninstall(['claro']);
    $this->setUpCurrentUser([], [
      'administer consumer entities',
      'administer decoupled settings',
    ]);

    $element = $this->themeElement();

    $this->assertSame(['stark' => 'Stark'], $this->themeOptions($element));
  }

  /**
   * A consumer's owner chooses its theme from the same select.
   *
   * Choosing the theme a consumer renders as is the consumer's own decision,
   * so an owner who cannot administer this module still gets the select,
   * not a free-text box.
   */
  public function testOwnerWithoutModulePermissionGetsTheSelect(): void {
    $this->setUpCurrentUser([], ['update own consumer entities']);

    $element = $this->themeElement();

    $this->assertSame('select', $element['#type']);
    $this->assertSame(['claro' => 'Claro', 'stark' => 'Stark'], $this->themeOptions($element));
  }

  /**
   * The add form offers the select too.
   */
  public function testNewConsumerGetsTheSelect(): void {
    $this->setUpCurrentUser([], ['administer consumer entities']);

    $consumer = Consumer::create(['client_id' => 'new_app', 'label' => 'New app']);
    $form = $this->container->get('entity.form_builder')->getForm($consumer, 'add');

    $this->assertSame('select', $form[ThemeManifest::THEME_FIELD]['widget'][0]['value']['#type']);
  }

  /**
   * Only the themes the site lists are offered, once it lists any.
   */
  public function testOnlyListedThemesAreOffered(): void {
    $this->config('decoupled_settings.settings')->set('selectable_themes', ['stark'])->save();
    $this->setUpCurrentUser([], ['update own consumer entities']);

    $element = $this->themeElement();

    $this->assertSame(['stark' => 'Stark'], $this->themeOptions($element));
  }

  /**
   * Returns the theme choices, without the empty one form processing adds.
   *
   * @param array $element
   *   The processed select element.
   *
   * @return array
   *   Theme machine name to human name.
   */
  protected function themeOptions(array $element): array {
    $options = $element['#options'];
    unset($options['']);

    return $options;
  }

  /**
   * Builds the edit form of a saved consumer and returns its theme element.
   *
   * @return array
   *   The render element for the theme field's value.
   */
  protected function themeElement(): array {
    $consumer = Consumer::create([
      'client_id' => 'form_app',
      'label' => 'Form app',
      'owner_id' => $this->container->get('current_user')->id(),
    ]);
    $consumer->save();

    $form = $this->container->get('entity.form_builder')->getForm($consumer, 'edit');
    $this->assertArrayHasKey(ThemeManifest::THEME_FIELD, $form);

    return $form[ThemeManifest::THEME_FIELD]['widget'][0]['value'];
  }

}
