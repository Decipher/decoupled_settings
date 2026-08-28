<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Form\FormState;
use Drupal\decoupled_settings\Form\ConsumerOverridesForm;
use Drupal\decoupled_settings\Form\SettingsForm;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the form logic in process.
 *
 * The functional test drives these forms through a browser, which proves the
 * routes, permissions and markup. It cannot measure coverage, because the
 * form code runs in the webserver process rather than the test one. These
 * tests exercise the same logic in process, so the branches are covered.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\Form\SettingsForm
 * @covers \Drupal\decoupled_settings\Form\ConsumerOverridesForm
 */
class FormLogicTest extends KernelTestBase {

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

    $this->config('system.site')
      ->set('name', 'Global Site')
      ->set('slogan', 'Global slogan')
      ->save();
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site'])
      ->set('expose_theme_settings', FALSE)
      ->save();
  }

  /**
   * The settings form previews every exposed setting.
   */
  public function testSettingsFormPreview(): void {
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(SettingsForm::class, $form_state);

    $rows = $form['review']['table']['#rows'];
    $settings = array_map(static fn (array $row): string => $row[0] . ':' . $row[1], $rows);

    $this->assertContains('system.site:name', $settings);
    // Excluded by default, so it must not be previewed.
    $this->assertNotContains('system.site:mail', $settings);
  }

  /**
   * The add list offers the site's config objects, minus what is exposed.
   */
  public function testAddListOffersConfigObjects(): void {
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(SettingsForm::class, $form_state);

    $options = $form['exposed']['objects']['_new']['name']['data']['add_object']['#options'];
    $flat = [];
    foreach ($options as $group) {
      $flat += is_array($group) ? $group : [];
    }

    $this->assertArrayHasKey('system.date', $flat);
    // Already exposed, so it must not be offered again.
    $this->assertArrayNotHasKey('system.site', $flat);
    // Config entities are JSON:API-native already, so they are not offered.
    $this->assertEmpty(array_filter(
      array_keys($flat),
      static fn (string $name): bool => str_starts_with($name, 'user.role.')
    ));
  }

  /**
   * The settings form rejects an exclusion that is not object:path.
   */
  public function testSettingsFormRejectsMalformedExclusion(): void {
    $form_state = new FormState();
    $form_state->setValues([
      'expose_theme_settings' => FALSE,
      'excluded_keys' => 'no_colon_here',
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);

    $this->assertNotEmpty($form_state->getErrors());
  }

  /**
   * A valid submission is saved, and blank lines are discarded.
   */
  public function testSettingsFormSaves(): void {
    $form_state = new FormState();
    $form_state->set('exposed_working', ['system.site', 'system.date']);
    $form_state->setValues([
      'expose_theme_settings' => TRUE,
      'excluded_keys' => "system.site:mail\n",
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('decoupled_settings.settings');
    $this->assertSame(['system.site', 'system.date'], $config->get('exposed_objects'));
    $this->assertTrue($config->get('expose_theme_settings'));
    $this->assertSame(['system.site:mail'], $config->get('excluded_keys'));
  }

  /**
   * The override form lists each exposed setting with its inherited value.
   */
  public function testOverrideFormListsInheritedValues(): void {
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $this->assertArrayHasKey('system.site:name', $form['settings']);
    $this->assertSame('Global Site', $form['settings']['system.site:name']['inherited']['#markup']);
    $this->assertFalse((bool) $form['settings']['system.site:name']['enabled']['#default_value']);
  }

  /**
   * A setting with no value is listed with an empty inherited cell.
   *
   * The notification address is NULL on a fresh site. It is excluded by
   * default, so this exposes it on purpose to render the NULL path.
   */
  public function testOverrideFormListsNullValueAsEmpty(): void {
    $this->config('decoupled_settings.settings')
      ->set('excluded_keys', [])
      ->save();
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $this->assertArrayHasKey('system.site:mail_notification', $form['settings']);
    $this->assertSame('', $form['settings']['system.site:mail_notification']['inherited']['#markup']);
  }

  /**
   * An already overridden setting is shown as ticked, with its own value.
   */
  public function testOverrideFormShowsExistingOverride(): void {
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Consumer Site'],
    ]);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $this->assertTrue((bool) $form['settings']['system.site:name']['enabled']['#default_value']);
    $this->assertSame('Consumer Site', $form['settings']['system.site:name']['value']['#default_value']);
  }

  /**
   * A ticked setting is stored, and an unticked one is left out entirely.
   */
  public function testOverrideFormStoresOnlyTickedSettings(): void {
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form_state->setValues([
      'settings' => [
        'system.site:name' => ['enabled' => 1, 'value' => 'Consumer Site'],
        'system.site:slogan' => ['enabled' => 0, 'value' => 'ignored'],
      ],
    ]);
    $consumer_id = $consumer->id();
    $this->container->get('form_builder')
      ->submitForm(ConsumerOverridesForm::class, $form_state, $consumer);

    $stored = $this->reloadOverrides($consumer_id);
    $this->assertSame(['system.site:name' => 'Consumer Site'], $stored);
  }

  /**
   * A key the resolved URL is derived from cannot be overridden.
   *
   * Core resolves logo.url before a consumer's overrides are merged, so an
   * override of logo.path would take and change nothing a client reads.
   */
  public function testDerivedThemeKeysAreNotOverridable(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_settings', TRUE)
      ->save();
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $this->assertArrayHasKey('stark.settings:logo.path', $form['settings']);
    $row = $form['settings']['stark.settings:logo.path'];
    $this->assertTrue($row['enabled']['#disabled']);
    $this->assertStringContainsString('Derived', (string) $row['enabled']['#description']);
    // A field nobody can type into shows the value it holds, rather than the
    // empty box that means "inherited" on an editable row.
    $this->assertSame(
      $form['settings']['stark.settings:logo.path']['inherited']['#markup'],
      (string) $row['value']['#default_value']
    );

    // The URL is the one to override, so it stays editable.
    $this->assertArrayHasKey('stark.settings:logo.url', $form['settings']);
    $this->assertFalse((bool) ($form['settings']['stark.settings:logo.url']['enabled']['#disabled'] ?? FALSE));
    $this->assertNull($form['settings']['stark.settings:logo.url']['enabled']['#description']);
  }

  /**
   * A locked row ignores a submitted value, whatever it says.
   *
   * A browser never sends a disabled input, and core assigns the element
   * its default instead, which AdminUiTest pins over HTTP. This covers the
   * case core does not: values that name the locked row anyway, whether
   * from a rebuild, a forged post or a future change to that handling.
   */
  public function testStoredOverrideOnLockedKeySurvivesSave(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('decoupled_settings.settings')
      ->set('expose_theme_settings', TRUE)
      ->save();
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => [
        'stark.settings:logo.path' => 'themes/custom/brand/logo.svg',
        'system.site:name' => 'Consumer Site',
      ],
    ]);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form_state->setValues([
      'settings' => [
        'system.site:name' => ['enabled' => 1, 'value' => 'Renamed'],
        // Whatever Form API does with a disabled checkbox, the stored value
        // is the one that must come back.
        'stark.settings:logo.path' => ['enabled' => 0, 'value' => ''],
      ],
    ]);
    $consumer_id = $consumer->id();
    $this->container->get('form_builder')
      ->submitForm(ConsumerOverridesForm::class, $form_state, $consumer);

    $stored = $this->reloadOverrides($consumer_id);
    $this->assertSame('themes/custom/brand/logo.svg', $stored['stark.settings:logo.path'] ?? NULL);
    $this->assertSame('Renamed', $stored['system.site:name'] ?? NULL);
  }

  /**
   * A row that cannot take a single-field value says so on the checkbox.
   */
  public function testDisabledRowExplainsItself(): void {
    // The test module contributes a sequence, which has no single-field
    // widget and so is the case this description exists for.
    $this->enableModules(['decoupled_settings_test']);
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $rows = array_filter(
      $form['settings'],
      static fn ($row, $key): bool => is_array($row) && !str_starts_with((string) $key, '#') && ($row['enabled']['#disabled'] ?? FALSE),
      ARRAY_FILTER_USE_BOTH
    );
    $this->assertNotEmpty($rows, 'A non-scalar setting is present to disable.');
    foreach ($rows as $key => $row) {
      $this->assertNotNull($row['enabled']['#description'], "$key explains itself");
      $this->assertNotSame('', (string) ($row['value']['#default_value'] ?? ''), "$key shows its value");
    }
  }

  /**
   * An override for a setting that is not exposed survives a save.
   *
   * The exposure list can shrink, and a theme switch renames every
   * theme-settings key. Neither renders a row for the stored override, and
   * a save of unrelated rows must not delete it.
   */
  public function testUnexposedOverrideSurvivesSave(): void {
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => [
        'system.site:name' => 'Consumer Site',
        'olivero.settings:logo.url' => '/old-theme-logo.svg',
      ],
    ]);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form_state->setValues([
      'settings' => [
        'system.site:name' => ['enabled' => 1, 'value' => 'Renamed Site'],
      ],
    ]);
    $consumer_id = $consumer->id();
    $this->container->get('form_builder')
      ->submitForm(ConsumerOverridesForm::class, $form_state, $consumer);

    $this->assertSame([
      'system.site:name' => 'Renamed Site',
      'olivero.settings:logo.url' => '/old-theme-logo.svg',
    ], $this->reloadOverrides($consumer_id));
  }

  /**
   * The form names stored overrides that have no exposed setting.
   */
  public function testOrphanedOverrideIsAnnounced(): void {
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => [
        'olivero.settings:logo.url' => '/old-theme-logo.svg',
      ],
    ]);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $warnings = $this->container->get('messenger')->messagesByType('warning');
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('olivero.settings:logo.url', (string) $warnings[0]);
  }

  /**
   * Clearing every override still stores an empty map, never NULL.
   *
   * A field with no items persists NULL, which Drupal 10 unserializes without a
   * null guard on every following load of the consumer.
   */
  public function testClearingAllOverridesKeepsTheColumn(): void {
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Was set'],
    ]);

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form_state->setValues([
      'settings' => [
        'system.site:name' => ['enabled' => 0, 'value' => ''],
      ],
    ]);
    $consumer_id = $consumer->id();
    $this->container->get('form_builder')
      ->submitForm(ConsumerOverridesForm::class, $form_state, $consumer);

    $this->assertSame([], $this->reloadOverrides($consumer_id));

    // The column itself is what Drupal 10 unserializes without a null
    // guard, so that is what must never be NULL.
    $value = $this->container->get('database')
      ->select('consumer_field_data', 'c')
      ->fields('c', [SettingsResolver::OVERRIDE_FIELD])
      ->condition('id', $consumer_id)
      ->execute()->fetchField();
    $this->assertSame(serialize([]), $value);
  }

  /**
   * A submitted value is cast back to the type of the value it overrides.
   *
   * A boolean setting submitted as "0" must be stored as FALSE, not as the
   * non-empty string "0", or the frontend receives the wrong type.
   */
  public function testSubmittedValueIsCastToTheGlobalType(): void {
    $this->exposeTestSettings();
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form_state->setValues([
      'settings' => [
        'decoupled_settings_test.settings:flag' => ['enabled' => 1, 'value' => '0'],
        'system.site:weight_select_max' => ['enabled' => 1, 'value' => '42'],
        'system.site:name' => ['enabled' => 1, 'value' => 'Consumer Site'],
      ],
    ]);
    $consumer_id = $consumer->id();
    $this->container->get('form_builder')
      ->submitForm(ConsumerOverridesForm::class, $form_state, $consumer);

    $stored = $this->reloadOverrides($consumer_id);
    $this->assertFalse($stored['decoupled_settings_test.settings:flag']);
    $this->assertSame(42, $stored['system.site:weight_select_max']);
    $this->assertSame('Consumer Site', $stored['system.site:name']);
  }

  /**
   * The widget matches the type of the setting it overrides.
   *
   * A boolean setting gets a checkbox and a numeric one a number field, so a
   * value of the wrong type cannot be entered at all.
   */
  public function testWidgetsMatchSettingTypes(): void {
    $this->exposeTestSettings();
    $consumer = $this->createConsumer();

    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$consumer]);
    $form = $this->container->get('form_builder')
      ->buildForm(ConsumerOverridesForm::class, $form_state);

    $this->assertSame('checkbox', $form['settings']['decoupled_settings_test.settings:flag']['value']['#type']);
    $this->assertSame('number', $form['settings']['system.site:weight_select_max']['value']['#type']);
    $this->assertSame(1, $form['settings']['system.site:weight_select_max']['value']['#step']);
    $this->assertSame('textfield', $form['settings']['system.site:name']['value']['#type']);
  }

  /**
   * Exposes the test module's settings beside system.site.
   *
   * The test module owns a boolean, so a core config change cannot remove the
   * fixture. Drupal 11.5 removed system.site:admin_compact_mode.
   */
  protected function exposeTestSettings(): void {
    $this->enableModules(['decoupled_settings_test']);
    $this->installConfig(['decoupled_settings_test']);
    $this->config('decoupled_settings.settings')
      ->set('exposed_objects', ['system.site', 'decoupled_settings_test.settings'])
      ->save();
  }

  /**
   * Creates a consumer.
   */
  protected function createConsumer(array $values = []): Consumer {
    $consumer = Consumer::create($values + [
      'client_id' => 'form_consumer',
      'label' => 'Form consumer',
    ]);
    $consumer->save();

    return $consumer;
  }

  /**
   * Reads the overrides stored on a consumer, from fresh.
   */
  protected function reloadOverrides(int|string $id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('consumer');
    $storage->resetCache([$id]);
    /** @var \Drupal\consumers\Entity\ConsumerInterface $consumer */
    $consumer = $storage->load($id);

    $field = $consumer->get(SettingsResolver::OVERRIDE_FIELD);
    if ($field->isEmpty()) {
      return [];
    }
    $stored = $field->first()->getValue();

    return is_array($stored) ? $stored : [];
  }

}
