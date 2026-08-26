<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Unit;

use Drupal\decoupled_settings\PageCache\DenyOnConsumerHeader;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the page cache policy for header-identified consumers.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\PageCache\DenyOnConsumerHeader
 */
class DenyOnConsumerHeaderTest extends UnitTestCase {

  /**
   * Builds a request.
   */
  protected function request(string $path, ?string $consumer = NULL): Request {
    $request = Request::create($path);
    if ($consumer !== NULL) {
      $request->headers->set('X-Consumer-ID', $consumer);
    }

    return $request;
  }

  /**
   * Every spelling of the settings path the router accepts is denied.
   *
   * The router lowercases, decodes, collapses and trims slashes, and follows
   * aliases before it matches. A policy that compared the raw path would
   * miss these, and each miss is a cached response served to the wrong
   * consumer.
   *
   * @dataProvider providerSettingsPaths
   */
  public function testDeniesTheHeaderOnEverySpellingOfThePath(string $path): void {
    $policy = new DenyOnConsumerHeader();

    $this->assertSame(RequestPolicyInterface::DENY, $policy->check($this->request($path, 'app')));
  }

  /**
   * Paths the router resolves to the settings resource.
   */
  public static function providerSettingsPaths(): array {
    return [
      'plain' => ['/jsonapi/decoupled/settings'],
      'configured base path' => ['/api/decoupled/settings'],
      'language prefix' => ['/fr/jsonapi/decoupled/settings'],
      'subdirectory' => ['/subdir/jsonapi/decoupled/settings'],
      'trailing slash' => ['/jsonapi/decoupled/settings/'],
      'prefixed trailing slash' => ['/fr/jsonapi/decoupled/settings/'],
      'upper case' => ['/JSONAPI/decoupled/settings'],
      'percent encoded' => ['/jsonapi/decoupled/setting%73'],
      'repeated slashes' => ['//jsonapi//decoupled//settings'],
      'path alias' => ['/anything-an-admin-aliased'],
    ];
  }

  /**
   * Without the header the policy stays silent, settings path or not.
   */
  public function testAllowsWithoutHeader(): void {
    $policy = new DenyOnConsumerHeader();

    $this->assertNull($policy->check($this->request('/jsonapi/decoupled/settings')));
    $this->assertNull($policy->check($this->request('/jsonapi/decoupled/settings/')));
    $this->assertNull($policy->check($this->request('/node/1')));
  }

  /**
   * The header on any other path is denied too: the cost of a safe test.
   */
  public function testDeniesOtherPathsWithHeader(): void {
    $policy = new DenyOnConsumerHeader();

    $this->assertSame(RequestPolicyInterface::DENY, $policy->check($this->request('/node/1', 'app')));
    $this->assertSame(RequestPolicyInterface::DENY, $policy->check($this->request('/jsonapi/node/article', 'app')));
  }

}
