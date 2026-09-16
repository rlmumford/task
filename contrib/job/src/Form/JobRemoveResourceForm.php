<?php

namespace Drupal\task_job\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxFormHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\task_job\JobInterface;
use Drupal\task_job\TaskJobTempstoreRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form to remove a checklist item.
 */
class JobRemoveResourceForm extends FormBase {
  use AjaxFormHelperTrait;

  /**
   * The tempstore repo.
   *
   * @var \Drupal\task_job\TaskJobTempstoreRepository
   */
  protected $tempstoreRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('task_job.tempstore_repository')
    );
  }

  /**
   * JobRemoveResourceForm constructor.
   *
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The tempstore repository.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository
  ) {
    $this->tempstoreRepository = $tempstore_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_remove_resource_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?JobInterface $task_job = NULL,
    $uuid = NULL
  ) {
    // Get the Job from tempstore if available.
    $job = $task_job;
    if ($this->tempstoreRepository->has($job)) {
      $job = $this->tempstoreRepository->get($job);
    }
    $form_state->set('job', $job);

    $resources = $job->getResourcesCollection()->getConfiguration();
    if (!isset($resources[$uuid])) {
      throw new NotFoundHttpException();
    }
    $form_state->set('resource_uuid', $uuid);

    $form['message'] = [
      '#markup' => $this->t(
        'Are you sure you want to remove this resource?',
      ),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove Resource'),
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\task_job\JobInterface $job */
    $job = $form_state->get('job');

    $uuid = $form_state->get('resource_uuid');
    $resources = $job->getResourcesCollection()->getConfiguration();
    unset($resources[$uuid]);
    $job->set('resources', $resources);
    $job->getResourcesCollection()->setConfiguration($job->getResourcesConfiguration());

    $this->tempstoreRepository->set($job);
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

}
