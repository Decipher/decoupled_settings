<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Unit;

use Drupal\decoupled_settings\SettingsMerger;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the merge of consumer overrides over global values.
 *
 * Each test maps to a scenario in the consumer-overrides spec.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\SettingsMerger
 */
class SettingsMergerTest extends UnitTestCase {

  /**
   * The merger under test.
   */
  protected SettingsMerger $merger;

  /**
   * Global values, shaped as system.site stores them.
   */
  protected array $global = [
    'name' => 'Global Site',
    'slogan' => 'Global slogan',
    'page' => ['front' => '/node', '403' => '', '404' => '/not-found'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->merger = new SettingsMerger();
  }

  /**
   * An unset setting resolves to the global value.
   */
  public function testUnsetSettingInheritsGlobal(): void {
    $resolved = $this->merger->merge('system.site', $this->global, []);

    $this->assertSame('Global Site', $resolved['name']);
    $this->assertSame('/node', $resolved['page']['front']);
  }

  /**
   * An overridden setting wins, and the global values are not mutated.
   */
  public function testOverrideWins(): void {
    $resolved = $this->merger->merge('system.site', $this->global, [
      'system.site:name' => 'Consumer A Site',
    ]);

    $this->assertSame('Consumer A Site', $resolved['name']);
    $this->assertSame('Global Site', $this->global['name']);
  }

  /**
   * One consumer's override does not reach another consumer.
   */
  public function testOverridesAreNotShared(): void {
    $a = $this->merger->merge('system.site', $this->global, ['system.site:name' => 'A']);
    $b = $this->merger->merge('system.site', $this->global, []);

    $this->assertSame('A', $a['name']);
    $this->assertSame('Global Site', $b['name']);
  }

  /**
   * Overriding one key leaves the other keys of the object inherited.
   */
  public function testMergeIsPerKeyNotPerObject(): void {
    $resolved = $this->merger->merge('system.site', $this->global, [
      'system.site:name' => 'A',
    ]);

    $this->assertSame('Global slogan', $resolved['slogan']);
    $this->assertSame('/not-found', $resolved['page']['404']);
  }

  /**
   * An override set to an empty value is served, not treated as absent.
   *
   * This is the distinction the whole design turns on. isset() would collapse
   * these back to the global value.
   *
   * @dataProvider providerEmptyValues
   */
  public function testOverriddenToEmptyIsServed(mixed $value): void {
    $resolved = $this->merger->merge('system.site', $this->global, [
      'system.site:name' => $value,
    ]);

    $this->assertSame($value, $resolved['name']);
  }

  /**
   * Values that are empty but deliberate.
   */
  public static function providerEmptyValues(): array {
    return [
      'empty string' => [''],
      'zero' => [0],
      'null' => [NULL],
      'false' => [FALSE],
    ];
  }

  /**
   * Removing an override restores inheritance.
   */
  public function testClearingOverrideRestoresInheritance(): void {
    $overrides = ['system.site:slogan' => ''];
    unset($overrides['system.site:slogan']);

    $resolved = $this->merger->merge('system.site', $this->global, $overrides);

    $this->assertSame('Global slogan', $resolved['slogan']);
  }

  /**
   * A nested setting can be overridden without disturbing its siblings.
   */
  public function testNestedOverride(): void {
    $resolved = $this->merger->merge('system.site', $this->global, [
      'system.site:page.front' => '/home',
    ]);

    $this->assertSame([
      'front' => '/home',
      '403' => '',
      '404' => '/not-found',
    ], $resolved['page']);
  }

  /**
   * An override belonging to another config object is ignored.
   */
  public function testOverridesDoNotLeakAcrossObjects(): void {
    $resolved = $this->merger->merge('system.site', $this->global, [
      'olivero.settings:logo.url' => '/themes/a.png',
    ]);

    $this->assertSame($this->global, $resolved);
  }

  /**
   * A list value is replaced whole rather than merged per index.
   */
  public function testListIsReplacedWhole(): void {
    $resolved = $this->merger->merge(
      'system.site',
      ['roles' => ['a', 'b', 'c']],
      ['system.site:roles' => ['z']]
    );

    $this->assertSame(['z'], $resolved['roles']);
  }

  /**
   * An override for a setting that is not exposed is dropped.
   */
  public function testUnexposedOverridesArePruned(): void {
    $stored = [
      'system.site:name' => 'Kept',
      'system.mail:interface.default' => 'Leaked',
    ];

    $pruned = $this->merger->pruneUnexposed($stored, ['system.site' => $this->global]);

    $this->assertSame(['system.site:name' => 'Kept'], $pruned);
  }

  /**
   * The override keys belonging to one config object can be listed.
   */
  public function testKeysForOneObject(): void {
    $overrides = [
      'system.site:name' => 'A',
      'system.site:page.front' => '/home',
      'olivero.settings:logo.url' => '/a.png',
    ];

    $this->assertSame(
      ['system.site:name', 'system.site:page.front'],
      $this->merger->keysFor('system.site', $overrides)
    );
  }

  /**
   * An object with no overrides lists no keys.
   */
  public function testKeysForObjectWithNoOverrides(): void {
    $this->assertSame([], $this->merger->keysFor('system.date', [
      'system.site:name' => 'A',
    ]));
  }

  /**
   * A name that is only a prefix of another is not treated as a match.
   */
  public function testKeysForDoesNotMatchPartialObjectName(): void {
    $this->assertSame([], $this->merger->keysFor('system.sit', [
      'system.site:name' => 'A',
    ]));
  }

  /**
   * Flattening and expanding a structure returns it unchanged.
   */
  public function testFlattenExpandRoundTrip(): void {
    $this->assertSame($this->global, $this->merger->expand($this->merger->flatten($this->global)));
  }

}
