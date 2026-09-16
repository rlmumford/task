<?php

namespace Drupal\task_job\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\entity_template\BlueprintTempstoreRepository;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintStorageJobTriggerAdaptor;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to add a job trigger.
 */
class JobAddTriggerForm extends JobPluginFormBase {

  /**
   * The blueprint tempstore repository.
   *
   * @var \Drupal\entity_template\BlueprintTempstoreRepository
   */
  protected $blueprintTempstoreRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin_form.factory'),
      $container->get('plugin.manager.task_job.trigger'),
      $container->get('config.factory'),
      $container->get('entity_template.blueprint_tempstore_repository')
    );
  }

  /**
   * JobAddTriggerForm constructor.
   *
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The tempstore repository.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_factory
   *   The plugin form factory.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\entity_template\BlueprintTempstoreRepository $blueprint_tempstore_repository
   *   The blueprint tempstore repository.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository,
    PluginFormFactoryInterface $plugin_form_factory,
    PluginManagerInterface $manager,
    ConfigFactoryInterface $config_factory,
    BlueprintTempstoreRepository $blueprint_tempstore_repository
  ) {
    parent::__construct(
      $tempstore_repository, $plugin_form_factory, $manager, $config_factory
    );

    $this->blueprintTempstoreRepository = $blueprint_tempstore_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_add_trigger_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface $plugin */
    $plugin = $form_state->get('plugin');
    /** @var \Drupal\task_job\JobInterface $job */
    $job = $form_state->get('job');
    $triggers = $job->getTriggerCollection()->getConfiguration();
    $triggers[$plugin->getKey()] = [
      'id' => $plugin->getPluginId(),
      'label' => $plugin->getLabel(),
      'key' => $plugin->getKey(),
    ] + $plugin->getConfiguration();
    $job->set('triggers', $triggers);
    $job->getTriggerCollection()->setConfiguration($job->getTriggersConfiguration());

    $this->tempstoreRepository->set($job);

    // WE also want to clear the blueprint storage for this key.
    $blueprint_storage = new BlueprintStorageJobTriggerAdaptor(
      $job,
      $plugin,
      \Drupal::service('plugin.manager.entity_template.blueprint_provider')
        ->createInstance('job_trigger')
    );
    $this->blueprintTempstoreRepository->delete($blueprint_storage);
  }

}
