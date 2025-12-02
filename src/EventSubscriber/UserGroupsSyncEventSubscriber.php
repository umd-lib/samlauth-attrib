<?php

namespace Drupal\samlauth_attrib\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Drupal\samlauth\EventSubscriber\UserSyncEventSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber that catches samlauth  user_sync events to provision roles.
 */
class UserGroupsSyncEventSubscriber implements EventSubscriberInterface {

  const SETTINGS = 'samlauth_attrib.settings';
  const EVENT = 'samlauth.user_sync';

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * A configuration object containing samlauth settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Construct a new UserGroupsSyncEventSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->logger = $logger;
    $this->config = $config_factory->get(static::SETTINGS);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[static::EVENT][] = ['onUserGroupsSync'];
    return $events;
  }

  /**
   * Performs actions to synchronize roles with SAML data on login.
   *
   * @param \Drupal\samlauth_attrib\Event\SamlauthUserSyncEvent $event
   *   The event.
   */
  public function onUserGroupsSync(SamlauthUserSyncEvent $event) {
    $this->logger->notice('samlauth_attrib.');
    $attributes = $event->getAttributes();
    if (!$grouper_attrib = $this->config->get('grouper_attrib')) {
      $this->logger->warning(t('Grouper Attribute not set'));
      return;
    }
    $account = $event->getAccount();
    if (empty($attributes[$grouper_attrib][0])) {
      $this->resetRoles($account);
      $account->block();
    } else {
      $groups = $attributes[$grouper_attrib];

      if ($account->isNew()) {
        $this->logger->notice('Account is new.');
      }

      if (!empty($account->id()) && $account->id() > 2) {
        $this->logger->notice('Account number:' . $account->id());
      }

      if (!$account->isNew() && $account->id() < 2) {
        $this->logger->notice('Bad or anonymous account. No roles provisioned.');
        return;
      }

      // Can't prevent account creation (as the samlauth module handles this)  but can block the account
      // if not set to autoprovision.
      if ($account->isNew() && empty($this->config->get('should_autoprovision'))) {
        $account->block();
        $this->logger->notice('Blocking account as autoprovisioning is not enabled.');
        // Will need a means for purging these blocked accounts.
        return;
      }

      $groups = array_map('strtolower', $groups);

      if (empty($this->config->get('autoprovision_role'))) {
        $this->logger->warning('No autoprovisioning role set. This is a grouper role which, when present, indicates whether or not a user should be added to the Drupal site. If other grouper groups are set, these will be updated, but there will be no blocking of users.');
      } else {
        $auto_role = strtolower($this->config->get('autoprovision_role'));
        if (!in_array($auto_role, $groups)) {
          $this->logger->notice('Account ID: ' . $account->id() . ' missing autoprovision role and will be blocked.');
          $account->block();
          return; 
        }
      }

      $roles = $this->config->get('grouper_map');

      $updated_roles = [];
      foreach ($groups as $group) {
        $this->logger->notice('Group: ' . $group);
        if (!empty($roles[$group]) && $role = $roles[$group]) {
          array_push($updated_roles, $role);
        }
      }
      if (count($updated_roles) > 0) {
        $this->resetRoles($account);
        foreach ($updated_roles as $updated_role) {
          $account->addRole($updated_role);
        }
      } else {
        $this->logger->notice('User @user has no roles', [
          '@user' => $account->getAccountName(),
        ]);
        $this->resetRoles($account);
        $account->block();
      }
      if (!$account->isNew()) {
        $account->save();
      }
    }
  }

  /**
   * Remove all current roles.
   *
   * @param \Drupal\Core\Session\Account $account
   *   A valid account
   */
  protected function resetRoles($account) {
    if ($account->getRoles() > 0) {
      foreach ($account->getRoles() as $role) {
        $account->removeRole($role);
      }
    }
  }

}
