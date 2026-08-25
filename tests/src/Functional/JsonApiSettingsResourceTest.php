<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Functional;

use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\SettingsResolver;
use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Tests the JSON:API settings resource over real HTTP.
 *
 * The caching assertions are the point of this test running functionally:
 * both page caches are enabled, so a consumer being served another
 * consumer's cached settings would fail here and nowhere else.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\Resource\SettingsResource
 * @covers \Drupal\decoupled_settings\PageCache\DenyOnConsumerHeader
 */
class JsonApiSettingsResourceTest extends BrowserTestBase {

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

    $this->config('system.site')
      ->set('name', 'Global Site')
      ->set('slogan', 'Global slogan')
      ->save();

    Consumer::create([
      'client_id' => 'consumer_a',
      'label' => 'Consumer A',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Site A'],
    ])->save();
    Consumer::create([
      'client_id' => 'consumer_b',
      'label' => 'Consumer B',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Site B'],
    ])->save();
  }

  /**
   * Grants the read permission to anonymous users.
   */
  protected function grantAnonymousRead(): void {
    $this->grantPermissions(
      Role::load(RoleInterface::ANONYMOUS_ID),
      ['read decoupled settings']
    );
  }

  /**
   * Fetches the resource and decodes the document.
   */
  protected function fetch(string $query = '', array $headers = []): array {
    // A header set through drupalGet() persists on the Mink session, so a
    // later request without it would silently still be consumer-identified.
    // Restarting the session gives every fetch a clean client, while the
    // server side caches persist, which is what these tests measure.
    $this->getSession()->restart();

    $options = [];
    if ($query !== '') {
      parse_str($query, $params);
      $options['query'] = $params;
    }
    $content = $this->drupalGet('/jsonapi/decoupled/settings', $options, $headers);

    return json_decode($content, TRUE) ?? [];
  }

  /**
   * Without the permission, nothing is disclosed.
   */
  public function testPermissionIsRequired(): void {
    $this->drupalGet('/jsonapi/decoupled/settings');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Global Site');
  }

  /**
   * An anonymous client with the permission reads the global values.
   */
  public function testGlobalValuesWithoutConsumer(): void {
    $this->grantAnonymousRead();

    $document = $this->fetch();

    $attributes = $document['data']['attributes'];
    $this->assertSame('Global Site', $attributes['settings']['system.site']['name']);
    $this->assertNull($attributes['consumer']);
    // The excluded site email address must not ride along.
    $this->assertArrayNotHasKey('mail', $attributes['settings']['system.site']);
  }

  /**
   * A consumer named by query parameter reads its own values.
   */
  public function testQueryParameterConsumer(): void {
    $this->grantAnonymousRead();

    $document = $this->fetch('consumerId=consumer_a');

    $attributes = $document['data']['attributes'];
    $this->assertSame('Site A', $attributes['settings']['system.site']['name']);
    $this->assertSame('consumer_a', $attributes['consumer']);
    $this->assertSame('Global slogan', $attributes['settings']['system.site']['slogan']);
  }

  /**
   * A consumer named by header reads its own values.
   */
  public function testHeaderConsumer(): void {
    $this->grantAnonymousRead();

    $document = $this->fetch('', ['X-Consumer-ID' => 'consumer_b']);

    $this->assertSame('Site B', $document['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * An unknown consumer reads the global values, not another's overrides.
   */
  public function testUnknownConsumerReadsGlobals(): void {
    $this->grantAnonymousRead();

    $document = $this->fetch('consumerId=who_is_this');

    $attributes = $document['data']['attributes'];
    $this->assertSame('Global Site', $attributes['settings']['system.site']['name']);
    $this->assertNull($attributes['consumer']);
  }

  /**
   * Two query-identified consumers never share a cached response.
   */
  public function testQueryConsumersDoNotShareCache(): void {
    $this->grantAnonymousRead();

    $a1 = $this->fetch('consumerId=consumer_a');
    $b = $this->fetch('consumerId=consumer_b');
    $a2 = $this->fetch('consumerId=consumer_a');

    $this->assertSame('Site A', $a1['data']['attributes']['settings']['system.site']['name']);
    $this->assertSame('Site B', $b['data']['attributes']['settings']['system.site']['name']);
    $this->assertSame('Site A', $a2['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * Two header-identified consumers never share a cached response.
   *
   * This is the case the internal page cache would get wrong without the
   * request policy: the URL is identical for both consumers, so a cached
   * response for A would be a hit for B.
   */
  public function testHeaderConsumersDoNotShareCache(): void {
    $this->grantAnonymousRead();

    // Prime any cache with A, then read B, then A again.
    $a1 = $this->fetch('', ['X-Consumer-ID' => 'consumer_a']);
    $b = $this->fetch('', ['X-Consumer-ID' => 'consumer_b']);
    $a2 = $this->fetch('', ['X-Consumer-ID' => 'consumer_a']);

    $this->assertSame('Site A', $a1['data']['attributes']['settings']['system.site']['name']);
    $this->assertSame('Site B', $b['data']['attributes']['settings']['system.site']['name']);
    $this->assertSame('Site A', $a2['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * A header-identified request does not poison the anonymous URL cache.
   */
  public function testHeaderRequestDoesNotPoisonAnonymousCache(): void {
    $this->grantAnonymousRead();

    $this->fetch('', ['X-Consumer-ID' => 'consumer_a']);
    $anonymous = $this->fetch();

    $this->assertSame('Global Site', $anonymous['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * Editing global config is reflected without a cache clear.
   */
  public function testConfigChangeInvalidatesCachedResponse(): void {
    $this->grantAnonymousRead();

    $before = $this->fetch();
    $this->assertSame('Global Site', $before['data']['attributes']['settings']['system.site']['name']);

    $this->config('system.site')->set('name', 'Renamed Site')->save();

    $after = $this->fetch();
    $this->assertSame('Renamed Site', $after['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * Write attempts are rejected.
   */
  public function testWriteIsRejected(): void {
    $this->grantAnonymousRead();

    $driver = $this->getSession()->getDriver();
    if (!$driver instanceof BrowserKitDriver) {
      $this->fail('This test needs the BrowserKit driver.');
    }
    $driver->getClient()->request(
      'POST',
      $this->buildUrl('/jsonapi/decoupled/settings'),
      [],
      [],
      ['CONTENT_TYPE' => 'application/vnd.api+json'],
      '{}'
    );
    $status = $this->getSession()->getStatusCode();

    $this->assertContains($status, [403, 405], 'A write attempt is refused.');
  }

}
