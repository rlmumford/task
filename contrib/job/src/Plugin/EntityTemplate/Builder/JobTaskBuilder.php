<?php

namespace Drupal\task_job\Plugin\EntityTemplate\Builder;

use Drupal\Core\Url;
use Drupal\entity_template\BlueprintInterface;
use Drupal\entity_template\BlueprintStorageInterface;
use Drupal\entity_template\Plugin\EntityTemplate\BlueprintProvider\BlueprintProviderInterface;
use Drupal\entity_template\Plugin\EntityTemplate\Builder\BuilderBase;
use Drupal\task_job\JobInterface;

/**
 * Builder for job tasks.
 *
 * @EntityTemplateBuilder(
 *   id = "task_job",
 *   label = @Translation("Task Job"),
 *   deriver = "\Drupal\task_job\Plugin\Derivative\JobEntityTemplateBuilderDeriver"
 * )
 *
 * @package Drupal\task_job\Plugin\EntityTemplate\Builder
 */
class JobTaskBuilder extends BuilderBase {

  /**
   * The job.
   *
   * @var \Drupal\task_job\JobInterface
   */
  protected $job;

  /**
   * Get the job.
   *
   * @return \Drupal\task_job\JobInterface
   *   The job entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getJob() : JobInterface {
    if (!$this->job) {
      $this->job = $this->entityTypeManager->getStorage('task_job')
        ->load($this->getPluginDefinition()['task_job']);
    }

    return $this->job;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultBlueprint() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultBlueprintStorage(BlueprintProviderInterface $provider) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultBlueprintEditTemplateUrl(
    BlueprintStorageInterface $blueprint_storage,
    string $key
  ): Url {
    /** @var \Drupal\entity_template\BlueprintEntityStorageAdaptor $blueprint_storage */
    return Url::fromRoute(
      'entity.task_job.edit_form',
      [
        'task_job' => $blueprint_storage->getEntity()->id(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getReturnType() {
    // These always create tasks.
    return 'entity:task';
  }

  /**
   * Massage the template initial values.
   *
   * @param array $values
   *   The vinitial entity values.
   * @param string $target_type_id
   *   The target entity type id.
   * @param \Drupal\entity_template\BlueprintInterface $blueprint
   *   The blueprint.
   */
  public function massageInitialValues(array &$values, string $target_type_id, BlueprintInterface $blueprint) {
    if ($target_type_id === 'task') {
      $values['job'] = $this->getJob();
    }
  }

}
