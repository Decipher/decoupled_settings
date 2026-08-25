<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Functional;

use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the exposure list form and the per-consumer override form.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\Form\SettingsForm
 * @covers \Drupal\decoupled_settings\Form\ConsumerOverridesForm
 */
class AdminUiTest extends BrowserTestBase {

  use BlockCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'help',
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
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // hook_help() output only renders where the help block is placed.
    $this->placeBlock('help_block');

    $this->config('system.site')
      ->set('name', 'Global Site')
      ->set('slogan', 'Global slogan')
      ->save();

    $this->drupalLogin($this->drupalCreateUser([
      'administer decoupled settings',
      'administer consumer entities',
    ]));
  }

  /**
   * The settings form is reachable and shows what is exposed.
   */
  public function testSettingsFormShowsExposedSettings(): void {
    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('What a frontend will read');
    $this->assertSession()->pageTextContains('system.site');
    $this->assertSession()->pageTextContains('Global Site');
  }

  /**
   * Both admin pages carry module help text.
   */
  public function testHelpIsShown(): void {
    $consumer = $this->createConsumer();

    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->assertSession()->pageTextContains('Decoupled Settings shows site and theme settings to a decoupled frontend');

    $this->drupalGet('/admin/config/services/consumer/' . $consumer->id() . '/decoupled-settings');
    $this->assertSession()->pageTextContains('A consumer overrides only the settings it names');
  }

  /**
   * The site email address is not previewed, because it is excluded.
   *
   * The assertion is on the address itself, not on the key name: the key
   * appears in the exclusions field by design, and it is the value leaking
   * into the exposed set that would be the bug.
   */
  public function testExcludedKeyIsNotPreviewed(): void {
    $this->config('system.site')->set('mail', 'leaky@example.com')->save();

    $this->drupalGet('/admin/config/services/decoupled-settings');

    $this->assertSession()->pageTextContains('Global Site');
    $this->assertSession()->pageTextNotContains('leaky@example.com');
  }

  /**
   * A config object can be added from the list, reviewed, then saved.
   */
  public function testExposureListCanBeSaved(): void {
    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->submitForm(['add_object' => 'system.date'], 'Add');

    // Added but not saved: the review already shows it.
    $this->assertSession()->pageTextContains('system.date');
    $this->assertNotContains(
      'system.date',
      $this->config('decoupled_settings.settings')->get('exposed_objects')
    );

    $this->submitForm([], 'Save configuration');
    $this->assertSame(
      ['system.site', 'system.date'],
      $this->config('decoupled_settings.settings')->get('exposed_objects')
    );
  }

  /**
   * A config object can be removed from the exposure list.
   */
  public function testExposedObjectCanBeRemoved(): void {
    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->submitForm([], 'Remove');
    $this->submitForm([], 'Save configuration');

    $this->assertSame(
      [],
      $this->config('decoupled_settings.settings')->get('exposed_objects')
    );
  }

  /**
   * A malformed exclusion is rejected.
   */
  public function testMalformedExclusionIsRejected(): void {
    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->submitForm([
      'excluded_keys' => 'no_colon_here',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('must be written as object:path');
  }

  /**
   * The override form lists every exposed setting with its inherited value.
   */
  public function testOverrideFormShowsInheritedValues(): void {
    $consumer = $this->createConsumer();

    $this->drupalGet('/admin/config/services/consumer/' . $consumer->id() . '/decoupled-settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('system.site:name');
    $this->assertSession()->pageTextContains('Global Site');
    $this->assertSession()->pageTextContains('Anything left unticked is inherited');
  }

  /**
   * Ticking a setting and saving stores an override for that consumer.
   */
  public function testOverrideCanBeSaved(): void {
    $consumer = $this->createConsumer();
    $path = '/admin/config/services/consumer/' . $consumer->id() . '/decoupled-settings';

    $this->drupalGet($path);
    $this->submitForm([
      'settings[system.site:name][enabled]' => TRUE,
      'settings[system.site:name][value]' => 'Consumer Site',
    ], 'Save overrides');

    $this->assertSession()->pageTextContains('Saved 1 overrides');

    $stored = $this->reloadOverrides($consumer->id());
    $this->assertSame(['system.site:name' => 'Consumer Site'], $stored);
  }

  /**
   * Unticking a setting removes the override rather than blanking it.
   */
  public function testUntickingClearsTheOverride(): void {
    $consumer = $this->createConsumer([
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Consumer Site'],
    ]);
    $path = '/admin/config/services/consumer/' . $consumer->id() . '/decoupled-settings';

    $this->drupalGet($path);
    $this->assertSession()->checkboxChecked('settings[system.site:name][enabled]');

    $this->submitForm([
      'settings[system.site:name][enabled]' => FALSE,
    ], 'Save overrides');

    $this->assertSame([], $this->reloadOverrides($consumer->id()));
  }

  /**
   * The consumer list shows how many settings each consumer overrides.
   */
  public function testConsumerListCountsOverrides(): void {
    $this->createConsumer([
      'client_id' => 'counted_consumer',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Consumer Site'],
    ]);

    $this->drupalGet('/admin/config/services/consumer');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Overrides');

    // Find the column by its header, not by position: the list builder
    // appends its operations column after the altered ones.
    $headers = $this->xpath('//table//th');
    $index = NULL;
    foreach ($headers as $i => $header) {
      if (trim($header->getText()) === 'Overrides') {
        $index = $i + 1;
      }
    }
    $this->assertNotNull($index, 'The overrides column is present.');

    $cell = $this->xpath('//tr[contains(., "counted_consumer")]//td[' . $index . ']');
    $this->assertCount(1, $cell);
    $this->assertSame('1', trim($cell[0]->getText()));
  }

  /**
   * A user without the permission cannot reach either form.
   */
  public function testPermissionIsRequired(): void {
    $consumer = $this->createConsumer();
    $this->drupalLogin($this->drupalCreateUser([]));

    $this->drupalGet('/admin/config/services/decoupled-settings');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/admin/config/services/consumer/' . $consumer->id() . '/decoupled-settings');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Creates a consumer.
   */
  protected function createConsumer(array $values = []): Consumer {
    $consumer = Consumer::create($values + [
      'client_id' => 'test_consumer',
      'label' => 'Test consumer',
    ]);
    $consumer->save();

    return $consumer;
  }

  /**
   * Reads the overrides stored on a consumer, from fresh.
   */
  protected function reloadOverrides(int|string $id): array {
    $storage = \Drupal::entityTypeManager()->getStorage('consumer');
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
