<?php

namespace Drupal\task_job\Event;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event to collect trigger access.
 *
 * @package Drupal\task_job\Event
 */
class TriggerAccessEvent extends Event {

  /**
   * The access result.
   *
   * @var \Drupal\Core\Access\AccessResultInterface
   */
  protected $accessResult;

  /**
   * The job trigger.
   *
   * @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface
   */
  protected $trigger;

  /**
   * TriggerAccessEvent constructor.
   *
   * @param \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface $trigger
   *   The job trigger.
   */
  public function __construct(JobTriggerInterface $trigger) {
    $this->trigger = $trigger;
  }

  /**
   * Get the trigger.
   *
   * @return \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface
   *   The trigger.
   */
  public function getTrigger() : JobTriggerInterface {
    return $this->trigger;
  }

  /**
   * Get the access result so far.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function getAccessResult() : AccessResultInterface {
    return $this->accessResult ?? AccessResult::neutral();
  }

  /**
   * Whether this event has an access result.
   *
   * @return bool
   *   Whether or not an access result has been collected.
   */
  public function hasAccessResult() : bool {
    return !is_null($this->accessResult);
  }

  /**
   * Set the access result.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access_result
   *   The access result.
   *
   * @return $this
   */
  public function setAccessResult(AccessResultInterface $access_result) : TriggerAccessEvent {
    $this->accessResult = $access_result;
    return $this;
  }

  /**
   * Apply andIf to the access result.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access_result
   *   The other access result.
   *
   * @return $this
   */
  public function andIf(AccessResultInterface $access_result) : TriggerAccessEvent {
    $this->accessResult = $this->getAccessResult()->andIf($access_result);
    return $this;
  }

  /**
   * Apply orIf to the access result.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access_result
   *   The other access result.
   *
   * @return $this
   */
  public function orIf(AccessResultInterface $access_result) : TriggerAccessEvent {
    $this->accessResult = $this->getAccessResult()->orIf($access_result);
    return $this;
  }

}
