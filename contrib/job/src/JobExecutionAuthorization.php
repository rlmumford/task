<?php

namespace Drupal\task_job;

use Drupal\checklist\ChecklistContextCollectorInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\checklist\Event\ChecklistExecutionAuthorizationEvent;
use Drupal\checklist\Execution\ChecklistExecutionAuthorization;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\task\Entity\Task;
use Drupal\task_job\Entity\Job;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Locally approves job definitions, never trusting authorizer IDs in config.
 */
class JobExecutionAuthorization implements EventSubscriberInterface {

  public const PERMISSION = 'authorize delegated checklist execution';

  public function __construct(
    protected KeyValueFactoryInterface $keyValue,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entities,
    protected StorageInterface $configStorage,
    protected TaskJobResolver $jobs,
    protected ChecklistContextCollectorInterface $contexts,
    protected ContextHandlerInterface $contextHandler,
    protected UuidInterface $uuid,
    protected TimeInterface $time,
  ) {}

  /**
   * Content grants belong to immutable revisions; config grants retain IDs.
   */
  protected function key(JobInterface $job): string {
    return $job instanceof RevisionableInterface
      ? $job->getEntityTypeId() . ':' . $job->id() . ':' . $job->getRevisionId()
      : $job->id();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ChecklistExecutionAuthorizationEvent::NAME => 'authorize'];
  }

  /**
   * Whether any authored definition requests delegated execution.
   */
  public function hasDelegation(JobInterface $job): bool {
    $groups = [$job->getChecklistItems()];
    foreach ($job->getDefinitionValue('checklist_templates') ?: [] as $template) {
      $groups[] = $template['items'] ?? [];
    }
    $delegated = FALSE;
    foreach ($groups as $items) {
      foreach ($items as $item) {
        $mode = $item['execution']['mode'] ?? 'self';
        if (!in_array($mode, ['self', 'context'], TRUE)) {
          throw new \InvalidArgumentException('Unknown checklist execution policy.');
        }
        if ($mode === 'context') {
          $delegated = TRUE;
        }
      }
    }
    return $delegated;
  }

  /**
   * Hashes the definition and transitive declared configuration dependencies.
   */
  public function fingerprint(JobInterface $job): string {
    $configuration = $job;
    if (!$job instanceof ConfigEntityInterface) {
      // Reuse the config job's dependency discovery without persisting config.
      $values = ['id' => 'content_job', 'label' => $job->label()];
      foreach ([
        'context', 'description', 'resources', 'default_checklist',
        'checklist_templates', 'checklist_includes', 'triggers', 'assignment_rules',
      ] as $name) {
        $values[$name] = $job->getDefinitionValue($name);
      }
      $configuration = Job::create($values);
    }
    $configuration->calculateDependencies();
    // SQL field storage normalizes IDs, booleans and revision metadata. Hash
    // the executable definition for content jobs, not those storage details.
    // The grant key binds its entity type, ID and immutable revision.
    $definition = $job instanceof ConfigEntityInterface
      ? Job::definitionHash($job->toArray())
      : hash('sha256', serialize([$job->getActiveHash(), (bool) $job->status()]));
    $pending = $configuration->getDependencies()['config'] ?? [];
    $dependencies = [];
    while ($name = array_pop($pending)) {
      if (array_key_exists($name, $dependencies)) {
        continue;
      }
      $data = $this->configStorage->read($name);
      $dependencies[$name] = $data;
      $pending = array_merge($pending, $data['dependencies']['config'] ?? []);
    }
    ksort($dependencies);
    return hash('sha256', serialize([$definition, $dependencies]));
  }

  /**
   * Checks every entity save, including changes to dirty working versions.
   */
  public function checkSave(JobInterface $job): void {
    if ($job instanceof ConfigEntityInterface && ($job->isSyncing() || $job->hasTrustedData())) {
      // Imports and default configuration installs may declare policy, but
      // neither manufactures consent from the account running the import.
      return;
    }
    $original = $job->id() === NULL ? NULL : $this->entities->getStorage($job->getEntityTypeId())->loadUnchanged($job->id());
    if (!$this->hasDelegation($job) && (!$original || !$this->hasDelegation($original))) {
      return;
    }
    if (!$this->currentUser->hasPermission(self::PERMISSION)) {
      if (!$job instanceof ConfigEntityInterface || !$original || $this->fingerprint($job) !== $this->fingerprint($original)) {
        throw new AccessDeniedHttpException('Changing a job with delegated execution requires permission to authorize delegated checklist execution.');
      }
    }
  }

  /**
   * Approves a saved definition using the actual saving account, not form data.
   */
  public function saved(JobInterface $job): void {
    $grants = $this->keyValue->get('task_job.execution_authorization');
    if (($job instanceof ConfigEntityInterface && ($job->isSyncing() || $job->hasTrustedData())) || !$this->hasDelegation($job)) {
      $grants->delete($this->key($job));
      return;
    }
    $fingerprint = $this->fingerprint($job);
    $existing = $grants->get($this->key($job));
    if ($existing && $existing['fingerprint'] === $fingerprint && $this->activeAuthorizer($existing['authorizer'], $job)) {
      return;
    }
    if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission(self::PERMISSION)) {
      throw new AccessDeniedHttpException('Only an authorized account can approve delegated job execution.');
    }
    $grants->set($this->key($job), [
      'source' => 'task_job',
      'grant' => $this->uuid->generate(),
      'approved' => $this->time->getCurrentTime(),
      'job' => $this->key($job),
      'authorizer' => (int) $this->currentUser->id(),
      'fingerprint' => $fingerprint,
    ]);
  }

  /**
   * Rechecks the approving user's current account and permissions.
   */
  protected function activeAuthorizer(int $uid, JobInterface $job): bool {
    $this->entities->getStorage('user_role')->resetCache();
    $account = $this->entities->getStorage('user')->loadUnchanged($uid);
    if (!$account || !$account->isActive() || !$account->hasPermission(self::PERMISSION)) {
      return FALSE;
    }
    if ($job instanceof ConfigEntityInterface) {
      return TRUE;
    }
    // Entity access caches survive in a worker even for max-age-zero results.
    // Recheck content ownership and permissions against the refreshed account.
    $this->entities->getAccessControlHandler($job->getEntityTypeId())->resetCache();
    return $job->access('update', $account);
  }

  /**
   * Requires local approval before creating or executing delegated work.
   */
  public function approvedGrant(JobInterface $job): array {
    $grant = $this->keyValue->get('task_job.execution_authorization')->get($this->key($job));
    if (!$grant || $grant['fingerprint'] !== $this->fingerprint($job)) {
      throw new AccessDeniedHttpException('This job definition needs local approval for delegated execution.');
    }
    if (!$this->activeAuthorizer($grant['authorizer'], $job)) {
      throw new AccessDeniedHttpException('The job execution authorizer is no longer authorized.');
    }
    return $grant;
  }

  /**
   * Authorizes only an item slot resolved from the task's saved job version.
   */
  public function authorize(ChecklistExecutionAuthorizationEvent $event): void {
    $task = $event->checklist->getEntity();
    $type = $event->checklist->getType();
    if (!$task instanceof Task || $type->getPluginId() !== 'job') {
      return;
    }
    $configuration = $type->getConfiguration();
    if (isset($configuration['default_items'])) {
      return;
    }
    // Resolve the task's actual job, not a client-supplied checklist config ID.
    $this->entities->getStorage('task_job')->resetCache();
    $job = $this->jobs->resolve($task);
    if (!$job) {
      return;
    }
    $definition = $type->getItemDefinitions($task, $job, $event->checklist->getKey())[$event->item->getName()] ?? NULL;
    if (!$definition || ($definition['execution']['mode'] ?? 'self') !== 'context') {
      return;
    }
    if (!$type->getJob() || $this->key($type->getJob()) !== $this->key($job)) {
      throw new AccessDeniedHttpException('The checklist source does not match the authorized job.');
    }
    // JobChecklist replaces unfinished item definitions with the source. Check
    // that invariant here as well, rather than trusting a copied item setting.
    $handler = $event->item->getHandler();
    $expected = $this->entities->getStorage('checklist_item')->create([
      'checklist_type' => 'job',
      'name' => $event->item->getName(),
      'handler' => ['id' => $definition['handler'], 'configuration' => $definition['handler_configuration']],
    ])->getHandler();
    if ($handler->getPluginId() !== $expected->getPluginId() || $handler->getConfiguration() !== $expected->getConfiguration()) {
      throw new AccessDeniedHttpException('The item differs from the authorized job definition.');
    }
    $grant = $this->approvedGrant($job);
    if ($event->attempt) {
      // Continuations retain the original identity after reassignment.
      $executor = $event->attempt->executor;
    }
    else {
      $rule = new ExecutionRule($definition['execution']);
      $this->contextHandler->applyContextMapping($rule, $this->contexts->collectRuntimeContexts($event->checklist, $event->item));
      $account = $rule->getContextValue('executor');
      if (!$account instanceof UserInterface || $account->isNew()) {
        throw new AccessDeniedHttpException('The execution rule must select an existing user.');
      }
      $executor = (int) $account->id();
    }
    $event->authorization = new ChecklistExecutionAuthorization($executor, $grant);
  }

}
