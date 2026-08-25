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
   * A header-identified request for the settings resource is denied.
   */
  public function testDeniesTheSettingsResourceWithHeader(): void {
    $policy = new DenyOnConsumerHeader('/jsonapi');

    $this->assertSame(
      RequestPolicyInterface::DENY,
      $policy->check($this->request('/jsonapi/decoupled/settings', 'app'))
    );
  }

  /**
   * Without the header the policy stays silent, settings path or not.
   */
  public function testAllowsWithoutHeader(): void {
    $policy = new DenyOnConsumerHeader('/jsonapi');

    $this->assertNull($policy->check($this->request('/jsonapi/decoupled/settings')));
    $this->assertNull($policy->check($this->request('/node/1')));
  }

  /**
   * The header on any other path keeps its page cache.
   */
  public function testAllowsOtherPathsWithHeader(): void {
    $policy = new DenyOnConsumerHeader('/jsonapi');

    $this->assertNull($policy->check($this->request('/node/1', 'app')));
    $this->assertNull($policy->check($this->request('/jsonapi/node/article', 'app')));
  }

  /**
   * A configured JSON:API base path moves the guarded path with it.
   */
  public function testRespectsTheConfiguredBasePath(): void {
    $policy = new DenyOnConsumerHeader('/api');

    $this->assertSame(
      RequestPolicyInterface::DENY,
      $policy->check($this->request('/api/decoupled/settings', 'app'))
    );
    $this->assertNull($policy->check($this->request('/jsonapi/decoupled/settings', 'app')));
  }

}
