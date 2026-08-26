<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the settings resource behind a language path prefix.
 *
 * The page cache stores responses by URL. A language prefix gives the
 * endpoint a second URL, so the request policy must match the prefixed
 * path too. If it does not, a header-identified response is cached under
 * the shared URL and served to requests that name no consumer.
 *
 * @group decoupled_settings
 */
class JsonApiSettingsResourceLanguagePrefixTest extends BrowserTestBase {

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
    'jsonapi_resources',
    'consumers',
    'decoupled_settings',
    'language',
    'page_cache',
    'dynamic_page_cache',
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

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->config('system.site')
      ->set('name', 'Global Site')
      ->save();

    Consumer::create([
      'client_id' => 'consumer_a',
      'label' => 'Consumer A',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Site A'],
    ])->save();

    $this->grantPermissions(
      Role::load(RoleInterface::ANONYMOUS_ID),
      ['read decoupled settings']
    );
  }

  /**
   * Fetches the resource on a path and decodes the document.
   */
  protected function fetch(string $path, array $headers = []): array {
    // A header set through drupalGet() persists on the Mink session. A
    // restart gives every fetch a clean client while the server side
    // caches persist, which is what this test measures.
    $this->getSession()->restart();

    $content = $this->drupalGet($path, [], $headers);

    return json_decode($content, TRUE) ?? [];
  }

  /**
   * A header response on any spelling of the path is not served to anonymous.
   *
   * @dataProvider providerPaths
   */
  public function testHeaderRequestDoesNotPoisonSharedCache(string $path): void {
    $identified = $this->fetch($path, ['X-Consumer-ID' => 'consumer_a']);
    $this->assertSame('consumer_a', $identified['data']['attributes']['consumer']);
    $this->assertSame('Site A', $identified['data']['attributes']['settings']['system.site']['name']);

    // The same URL with no consumer must answer with the global values,
    // not a cached copy of the identified response.
    $anonymous = $this->fetch($path);
    $this->assertNull($anonymous['data']['attributes']['consumer']);
    $this->assertSame('Global Site', $anonymous['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * Spellings of the endpoint the router accepts.
   *
   * A percent-encoded spelling is covered by the unit test only: the test
   * client re-encodes the percent sign, so it cannot reach the router here.
   */
  public static function providerPaths(): array {
    return [
      'language prefix' => ['/fr/jsonapi/decoupled/settings'],
      'trailing slash' => ['/jsonapi/decoupled/settings/'],
      'prefixed trailing slash' => ['/fr/jsonapi/decoupled/settings/'],
      'upper case' => ['/JSONAPI/decoupled/settings'],
    ];
  }

}
