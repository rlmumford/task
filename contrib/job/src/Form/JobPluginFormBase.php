<?php

namespace Drupal\task_job\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;

/**
 * Base form for configuring job plugins.
 */
abstract class JobPluginFormBase extends FormBase {
  use AjaxFormHelperTrait;

  /**
   * The tempstore repo.
   *
   * @var \Drupal\task_job\TaskJobTempstoreRepository
   */
  protected $tempstoreRepository;

  /**
   * The plugin form factory.
   *
   * @var \Drupal\Core\Plugin\PluginFormFactoryInterface
   */
  protected $pluginFormFactory;

  /**
   * The plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $manager;

  /**
   * JobEditForm constructor.
   *
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The tempstore repository.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_factory
   *   The plugin form factory.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $manager
   *   The plugin manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository,
    PluginFormFactoryInterface $plugin_form_factory,
    PluginManagerInterface $manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->tempstoreRepository = $tempstore_repository;
    $this->pluginFormFactory = $plugin_form_factory;
    $this->manager = $manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?JobInterface $task_job = NULL,
    $plugin_id = NULL,
    $plugin_configuration = []
  ) {
    // Get the Job from tempstore if available.
    $job = $task_job;
    if ($this->tempstoreRepository->has($job)) {
      $job = $this->tempstoreRepository->get($job);
    }
    $form_state->set('job', $job);

    $form['plugin_id'] = [
      '#type' => 'value',
      '#value' => $plugin_id,
    ];

    /** @var \Drupal\Core\Plugin\PluginWithFormsInterface $plugin */
    $plugin = $this->manager->createInstance($plugin_id, $plugin_configuration);

    if ($plugin instanceof ContextAwarePluginInterface) {
      $form_state->setTemporaryValue('gathered_contexts', $this->gatherContexts($task_job));
    }

    if (
      $plugin instanceof PluginWithFormsInterface &&
      $plugin->hasFormClass('configure')
    ) {
      $plugin_form = $this->pluginFormFactory->createInstance(
        $plugin,
        'configure'
      );

      $form['plugin_configuration'] = [
        '#type' => 'container',
        '#parents' => ['plugin_configuration'],
        '#tree' => TRUE,
      ];
      $subform_state = SubformState::createForSubform(
        $form['plugin_configuration'],
        $form,
        $form_state
      );
      $form['plugin_configuration'] = $plugin_form->buildConfigurationForm(
        $form['plugin_configuration'],
        $subform_state
      );
    }
    else {
      $form['message']['#markup'] = new TranslatableMarkup('Are you sure you want to add @label?', ['@label' => $plugin->getPluginDefinition()['label']]);
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
    ];

    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
      // @todo static::ajaxSubmit() requires data-drupal-selector to be the same
      //   between the various Ajax requests. A bug in
      //   \Drupal\Core\Form\FormBuilder prevents that from happening unless
      //   $form['#id'] is also the same. Normally, #id is set to a unique HTML
      //   ID via Html::getUniqueId(), but here we bypass that in order to work
      //   around the data-drupal-selector bug. This is okay so long as we
      //   assume that this form only ever occurs once on a page. Remove this
      //   workaround in https://www.drupal.org/node/2897377.
      $form['#id'] = Html::getId($form_state->getBuildInfo()['form_id']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $plugin_id = $form_state->getValue('plugin_id');

    /** @var \Drupal\Core\Plugin\PluginWithFormsInterface $plugin */
    $plugin = $this->manager->createInstance($plugin_id);
    if (
      $plugin instanceof PluginWithFormsInterface &&
      $plugin->hasFormClass('configure')
    ) {
      $plugin_form = $this->pluginFormFactory->createInstance(
        $plugin,
        'configure'
      );
      $subform_state = SubformState::createForSubform(
        $form['plugin_configuration'],
        $form,
        $form_state
      );

      $plugin_form->validateConfigurationForm(
        $form['plugin_configuration'],
        $subform_state
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $handler = $form_state->getValue('plugin_id');

    /** @var \Drupal\Core\Plugin\PluginWithFormsInterface $plugin */
    $plugin = $this->manager->createInstance($handler);
    if (
      $plugin instanceof PluginWithFormsInterface &&
      $plugin->hasFormClass('configure')
    ) {
      $plugin_form = $this->pluginFormFactory->createInstance(
        $plugin,
        'configure'
      );
      $subform_state = SubformState::createForSubform(
        $form['plugin_configuration'],
        $form,
        $form_state
      );

      $plugin_form->submitConfigurationForm(
        $form['plugin_configuration'],
        $subform_state
      );
    }

    $form_state->set('plugin', $plugin);

    $form_state->setRedirect(
      'entity.task_job.edit_form',
      [
        'task_job' => $form_state->get('job')->id(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(
    array $form,
    FormStateInterface $form_state
  ) {
    $response = new AjaxResponse();
    $response->addCommand(new RedirectCommand(Url::fromRoute(
      'entity.task_job.edit_form',
      [
        'task_job' => $form_state->get('job')->id(),
      ]
    )->toString()));

    return $response;
  }

  /**
   * Gather the contexts available for this plugin.
   *
   * @param \Drupal\task_job\JobInterface $task_job
   *   The job.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   A list of contexts available to the plugin.
   */
  protected function gatherContexts(JobInterface $task_job) {
    return [];
  }

}
