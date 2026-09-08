<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\simple_oauth\Functional\SimpleOauthTestTrait;
use Drupal\consumers\Entity\Consumer;
use Drupal\decoupled_settings\SettingsResolver;
use Drupal\simple_oauth\Entity\Oauth2Scope;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Pins the Simple OAuth interop the README relies on.
 *
 * Simple OAuth sets the X-Consumer-ID header from the token's consumer, so a
 * client_credentials token identifies the app with no extra setup. If that
 * ever changes, the resource does not error, it silently answers with the
 * global values. This test turns that silence into a failure.
 *
 * @group decoupled_settings
 */
class JsonApiSettingsResourceSimpleOauthTest extends BrowserTestBase {

  use SimpleOauthTestTrait;

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
    'options',
    'path_alias',
    'simple_oauth',
    'decoupled_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The client secret.
   */
  protected string $clientSecret = 'partner-secret-for-tests-only';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setUpKeys();

    $this->config('system.site')->set('name', 'Global Site')->save();

    // The token acts as this role. It is the only holder of the permission,
    // so a request that reads settings at all has proven the token worked.
    Role::create(['id' => 'settings_reader', 'label' => 'Settings reader'])->save();
    $this->grantPermissions(Role::load('settings_reader'), ['read decoupled settings']);

    $bot = User::create([
      'name' => 'partner_bot',
      'status' => 1,
      'roles' => ['settings_reader'],
    ]);
    $bot->save();

    Oauth2Scope::create([
      'name' => 'frontend_app',
      'description' => 'Reads the settings of the frontend app.',
      'grant_types' => [
        'client_credentials' => ['status' => TRUE, 'description' => 'Machine access.'],
      ],
      'umbrella' => FALSE,
      'granularity_id' => Oauth2ScopeInterface::GRANULARITY_ROLE,
      'granularity_configuration' => ['role' => 'settings_reader'],
    ])->save();

    Consumer::create([
      'client_id' => 'partner_frontend',
      'label' => 'Partner frontend',
      'secret' => $this->clientSecret,
      'grant_types' => ['client_credentials'],
      'scopes' => ['frontend_app'],
      'user_id' => $bot->id(),
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Partner Portal'],
    ])->save();
  }

  /**
   * Fetches the resource with optional headers and decodes it.
   */
  protected function fetch(array $headers = []): array {
    $this->getSession()->restart();
    $content = $this->drupalGet('/jsonapi/decoupled/settings', [], $headers);

    return Json::decode($content) ?? [];
  }

  /**
   * A client_credentials token identifies the consumer by itself.
   */
  public function testTokenIdentifiesTheConsumer(): void {
    // Without a token there is no permission, so nothing is disclosed.
    $this->fetch();
    $this->assertSession()->statusCodeEquals(403);

    $client = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl, 'http_errors' => FALSE]);
    $response = $client->post(Url::fromRoute('oauth2_token.token')->toString(), [
      'form_params' => [
        'grant_type' => 'client_credentials',
        'client_id' => 'partner_frontend',
        'client_secret' => $this->clientSecret,
        'scope' => 'frontend_app',
      ],
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $token = Json::decode((string) $response->getBody())['access_token'] ?? '';
    $this->assertNotEmpty($token);

    // No X-Consumer-ID header and no consumerId query: the token is all.
    $document = $this->fetch(['Authorization' => 'Bearer ' . $token]);
    $this->assertSame('partner_frontend', $document['data']['attributes']['consumer']);
    $this->assertSame('Partner Portal', $document['data']['attributes']['settings']['system.site']['name']);
  }

  /**
   * A token reads its own consumer, whatever the request asks for.
   *
   * Simple OAuth overwrites X-Consumer-ID from the token, and the header
   * beats the query parameter, so a token holder naming another consumer
   * reads its own rather than being refused. The access check never has to
   * answer for this path, which is why the session-authenticated cases in
   * JsonApiSettingsResourceTest carry the refusal contract.
   */
  public function testTokenReadsItsOwnConsumerWhateverIsNamed(): void {
    Consumer::create([
      'client_id' => 'other_app',
      'label' => 'Other app',
      SettingsResolver::OVERRIDE_FIELD => ['system.site:name' => 'Other Portal'],
    ])->save();

    $client = $this->container->get('http_client_factory')
      ->fromOptions(['base_uri' => $this->baseUrl, 'http_errors' => FALSE]);
    $response = $client->post(Url::fromRoute('oauth2_token.token')->toString(), [
      'form_params' => [
        'grant_type' => 'client_credentials',
        'client_id' => 'partner_frontend',
        'client_secret' => $this->clientSecret,
        'scope' => 'frontend_app',
      ],
    ]);
    $token = Json::decode((string) $response->getBody())['access_token'] ?? '';
    $this->assertNotEmpty($token);

    $own = $this->fetch(['Authorization' => 'Bearer ' . $token]);
    $this->assertSame('partner_frontend', $own['data']['attributes']['consumer']);

    // The same token, naming another consumer explicitly. The token wins.
    $this->getSession()->restart();
    $content = $this->drupalGet('/jsonapi/decoupled/settings', ['query' => ['consumerId' => 'other_app']], [
      'Authorization' => 'Bearer ' . $token,
    ]);
    $other = Json::decode($content) ?? [];
    // Assert the shape, not the absence of a value: an error document, or a
    // body that does not decode, would satisfy a negative assertion while
    // the branch this test exists for was broken.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('data', $other, 'The response is a settings document, not an error.');
    $this->assertSame('partner_frontend', $other['data']['attributes']['consumer']);
    $this->assertSame('Partner Portal', $other['data']['attributes']['settings']['system.site']['name']);

    // And the header, which is the channel the resource reads first. Simple
    // OAuth sets it from the token during authentication, so a value the
    // client supplied is replaced rather than honoured. Pinned here because
    // nothing else in this repository can settle that, and the docblock
    // above depends on it.
    $this->getSession()->restart();
    $spoofed = Json::decode($this->drupalGet('/jsonapi/decoupled/settings', [], [
      'Authorization' => 'Bearer ' . $token,
      'X-Consumer-ID' => 'other_app',
    ])) ?? [];

    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayHasKey('data', $spoofed, 'The response is a settings document, not an error.');
    $this->assertSame('partner_frontend', $spoofed['data']['attributes']['consumer']);
    $this->assertSame('Partner Portal', $spoofed['data']['attributes']['settings']['system.site']['name']);
  }

}
