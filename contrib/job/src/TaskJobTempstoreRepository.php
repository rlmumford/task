<?php

namespace Drupal\task_job;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Url;
use Drupal\entity_template\BlueprintTempstoreRepository;
use Drupal\entity_template\TemplateBlueprintProviderManager;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintStorageJobTriggerAdaptor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * One owner-protected working copy for all job configuration tabs and dialogs.
 */
class TaskJobTempstoreRepository {

  /**
   * Constructs the repository.
   */
  public function __construct(protected SharedTempStoreFactory $tempStoreFactory, protected EntityTypeManagerInterface $entities, protected BlueprintTempstoreRepository $blueprints, protected TemplateBlueprintProviderManager $providers) {}

  /**
   * Gets the draft, folding nested Entity Template edits into the same job.
   */
  public function get(JobInterface $job): JobInterface {
    $entry = $this->entry($job);
    if ($entry === NULL && $job->isVersioned() && !$job->isDirty()) {
      $dirty = $this->entities->getStorage('task_job')->load(JobVersionId::buildDirty($job->getBaseJobId(), $job->getVersion()));
      if ($dirty instanceof JobInterface) {
        $job = $dirty;
        $entry = $this->entry($job);
      }
    }
    if ($entry === NULL) {
      $this->set($job);
    }
    $job = $entry['job'] ?? $job;
    $configuration = $job->getTriggersConfiguration();
    $changed = FALSE;
    foreach ($job->getTriggerCollection() as $key => $trigger) {
      $storage = $this->blueprintStorage($job, $trigger);
      if ($this->blueprints->has($storage)) {
        $original = $storage->getTemplate('default')->getConfiguration();
        $storage = $this->blueprints->get($storage);
        $updated = $storage->getTemplate('default')->getConfiguration();
        if ($original !== $updated) {
          $configuration[$key]['template'] = $updated;
          $changed = TRUE;
        }
        $this->blueprints->delete($storage);
      }
    }
    if ($changed) {
      $job->set('triggers', $configuration);
      $job->getTriggerCollection()->setConfiguration($configuration);
      $this->set($job);
    }
    return $job;
  }

  /**
   * Checks whether the current user has a working copy.
   */
  public function has(JobInterface $job): bool {
    return $this->entry($job) !== NULL;
  }

  /**
   * Retains the baseline and active tab while updating the draft.
   */
  public function set(JobInterface $job, ?string $section = NULL, ?string $template = NULL): void {
    $entry = $this->entry($job);
    $new = $entry === NULL;
    $entry ??= [
      'original' => $this->entities->getStorage('task_job')->loadUnchanged($job->id())?->toArray(),
      'section' => 'checklist',
    ];
    $entry['job'] = $job;
    $entry['section'] = $section ?? $entry['section'];
    if ($section === 'templates') {
      $entry['template'] = $template;
    }
    if (!$this->store()->setIfOwner($job->id(), $entry)) {
      throw new AccessDeniedHttpException('This job is being edited by another user.');
    }
    if ($new) {
      // A nested draft can outlive an expired parent. It must never be adopted
      // by the next owner of this job's editing session.
      foreach ($job->getTriggerCollection() as $trigger) {
        $this->blueprints->delete($this->blueprintStorage($job, $trigger));
      }
    }
  }

  /**
   * Checks for imports or other saves since the draft was opened.
   */
  public function isCurrent(JobInterface $job): bool {
    $entry = $this->entry($job);
    $current = $this->entities->getStorage('task_job')->loadUnchanged($job->id());
    return $entry !== NULL && array_key_exists('original', $entry) && $current?->toArray() === $entry['original'];
  }

  /**
   * Checks whether draft configuration differs from its saved baseline.
   */
  public function isChanged(JobInterface $job): bool {
    return $job->toArray() !== ($this->entry($job)['original'] ?? NULL);
  }

  /**
   * Discards every nested working copy as well as the job draft.
   */
  public function delete(JobInterface $job): void {
    $draft = $this->entry($job)['job'] ?? $job;
    foreach ($draft->getTriggerCollection() as $trigger) {
      $this->blueprints->delete($this->blueprintStorage($draft, $trigger));
    }
    $this->store()->deleteIfOwner($job->id());
  }

  /**
   * Returns dialogs to the tab from which they were opened.
   */
  public function getEditUrl(JobInterface $job, ?string $section = NULL, ?string $template = NULL): Url {
    $section ??= $this->entry($job)['section'] ?? 'checklist';
    $template ??= $this->entry($job)['template'] ?? NULL;
    if ($section === 'templates' && $template !== NULL && isset(($job->get('checklist_templates') ?: [])[$template])) {
      return Url::fromRoute('entity.task_job.edit_template', ['task_job' => $job->id(), 'template' => $template]);
    }
    $route = $section === 'checklist' ? 'entity.task_job.edit_form' : 'entity.task_job.edit_' . $section;
    return Url::fromRoute($route, ['task_job' => $job->id()]);
  }

  /**
   * Loads metadata only for the owner; never exposes another user's draft.
   */
  protected function entry(JobInterface $job): ?array {
    $entry = $this->store()->getIfOwner($job->id());
    if ($entry === NULL && $this->store()->getMetadata($job->id())) {
      throw new AccessDeniedHttpException('This job is being edited by another user.');
    }
    return $entry;
  }

  /**
   * Gets the existing job shared-tempstore collection.
   */
  protected function store() {
    return $this->tempStoreFactory->get('task_jobtask_job');
  }

  /**
   * Creates the adapter used by nested template dialogs.
   */
  protected function blueprintStorage(JobInterface $job, $trigger): BlueprintStorageJobTriggerAdaptor {
    return new BlueprintStorageJobTriggerAdaptor($job, $trigger, $this->providers->createInstance('job_trigger'));
  }

}
