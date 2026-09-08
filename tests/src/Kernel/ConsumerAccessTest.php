<?php

declare(strict_types=1);

namespace Drupal\Tests\decoupled_settings\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\decoupled_settings\ConsumerAccess;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests who may read a named consumer's settings.
 *
 * Naming a consumer asks for its overrides rather than the site's values, so
 * these are the rules that stop one frontend reading another's config.
 *
 * @group decoupled_settings
 *
 * @covers \Drupal\decoupled_settings\ConsumerAccess
 */
class ConsumerAccessTest extends KernelTestBase {

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
    // Supplies the user_id field Simple OAuth adds, so the acts-as branch is
    // covered without an OAuth server.
    'decoupled_settings_acts_as',
    'decoupled_settings',
  ];

  /**
   * The consumer under test.
   *
   * @var \Drupal\consumers\Entity\ConsumerInterface
   */
  protected $consumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('consumer');
    $this->installConfig(['system', 'user']);

    // User 1 exists on a real site and is not what these tests measure.
    User::create(['uid' => 1, 'name' => 'admin'])->save();

    $this->consumer = Consumer::create([
      'client_id' => 'other_app',
      'label' => 'Other app',
    ]);
    $this->consumer->save();
  }

  /**
   * Runs the check as one account.
   */
  protected function mayRead(AccountInterface $account, ?CacheableMetadata $cacheability = NULL): bool {
    $this->container->get('current_user')->setAccount($account);

    return (new ConsumerAccess($account))
      ->mayRead($this->consumer, $cacheability ?? new CacheableMetadata());
  }

  /**
   * Creates an account with the given permissions.
   */
  protected function account(array $permissions = []) {
    $role = Role::create(['id' => 'reader_' . bin2hex(random_bytes(4)), 'label' => 'Reader']);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $user = User::create(['name' => $this->randomMachineName(), 'status' => 1]);
    $user->addRole((string) $role->id());
    $user->save();

    return $user;
  }

  /**
   * An anonymous caller reads any consumer it names.
   *
   * There is no identity to match a consumer against. What anonymous may
   * read is decided by the read permission and the exposure list instead.
   */
  public function testAnonymousMayReadAnyConsumer(): void {
    $this->assertTrue($this->mayRead(User::getAnonymousUser()));
  }

  /**
   * An authenticated account with no relationship to the consumer is refused.
   *
   * This is the case the issue was filed for: one frontend's credentials
   * reading another frontend's overrides.
   */
  public function testUnrelatedAccountIsRefused(): void {
    $this->assertFalse($this->mayRead($this->account()));
  }

  /**
   * The cross-consumer permission lifts the check.
   */
  public function testReadAnyPermissionAllowsIt(): void {
    $account = $this->account([ConsumerAccess::READ_ANY]);

    $this->assertTrue($this->mayRead($account));
  }

  /**
   * The account a consumer's token acts as reads that consumer.
   *
   * The user_id field is the one Simple OAuth adds. It is not owner_id,
   * which is who created the consumer, so both are asked.
   */
  public function testTheAccountTheConsumerActsAsMayReadIt(): void {
    $account = $this->account();
    $this->consumer->set('user_id', $account->id())->save();

    $this->assertTrue($this->mayRead($account));
  }

  /**
   * A different account than the one the consumer acts as is still refused.
   *
   * Pins that the acts-as branch compares identities rather than merely
   * checking the field is set.
   */
  public function testAnotherAccountThanActsAsIsRefused(): void {
    $owner = $this->account();
    $this->consumer->set('user_id', $owner->id())->save();

    $this->assertFalse($this->mayRead($this->account()));
  }

  /**
   * A consumer acting as nobody does not match an account by accident.
   *
   * An empty user_id must not compare equal to a real account id.
   */
  public function testEmptyActsAsDoesNotMatch(): void {
    $account = $this->account();

    $this->assertFalse($this->mayRead($account));
  }

  /**
   * The decision varies by user, and says so.
   *
   * Without these contexts one account's answer would be served to another.
   */
  public function testTheDecisionVariesByUser(): void {
    $cacheability = new CacheableMetadata();
    $this->mayRead($this->account(), $cacheability);

    $contexts = $cacheability->getCacheContexts();
    $this->assertContains('user', $contexts);
    $this->assertContains('user.permissions', $contexts);
  }

  /**
   * An anonymous answer still varies by user.
   *
   * Anonymous returns early, so the user context has to be added before that
   * return or an anonymous answer could be cached for everyone.
   */
  public function testAnonymousAnswerStillVariesByUser(): void {
    $cacheability = new CacheableMetadata();
    $this->mayRead(User::getAnonymousUser(), $cacheability);

    $this->assertContains('user', $cacheability->getCacheContexts());
  }

}
