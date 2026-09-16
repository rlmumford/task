<?php

namespace Drupal\task_job\Form;

use Drupal\checklist\ChecklistItemHandlerManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\entity_template\BlueprintTempstoreRepository;
use Drupal\entity_template\TemplateBlueprintProviderManager;
use Drupal\task_job\JobInterface;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintStorageJobTriggerAdaptor;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerManager;
use Drupal\task_job\Plugin\JobTrigger\Missing;
use Drupal\task_job\TaskJobTempstoreRepository;
use Drupal\typed_data\Context\ContextDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to edit a job.
 */
class JobEditForm extends JobForm {

  /**
   * The job entity.
   *
   * @var \Drupal\task_job\Entity\Job
   */
  protected $entity;

  /**
   * A list of the blueprint storages for the different triggers.
   *
   * @var \Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintStorageJobTriggerAdaptor[]
   */
  protected $blueprintStorages = [];

  /**
   * The blueprint provider manager service.
   *
   * @var \Drupal\entity_template\TemplateBlueprintProviderManager
   */
  protected $blueprintProviderManager;

  /**
   * The blueprint tempstore repository.
   *
   * @var \Drupal\entity_template\BlueprintTempstoreRepository
   */
  protected $blueprintTempstoreRepository;

  /**
   * The job tempstore repository.
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
   * The checklist item manager.
   *
   * @var \Drupal\checklist\ChecklistItemHandlerManager
   */
  protected $manager;

  /**
   * The job trigger manager.
   *
   * @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerManager
   */
  protected $jobTriggerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('task_job.tempstore_repository'),
      $container->get('plugin.manager.checklist_item_handler'),
      $container->get('plugin.manager.entity_template.blueprint_provider'),
      $container->get('entity_template.blueprint_tempstore_repository'),
      $container->get('plugin_form.factory'),
      $container->get('plugin.manager.task_job.trigger')
    );
  }

  /**
   * JobEditForm constructor.
   *
   * @param \Drupal\task_job\TaskJobTempstoreRepository $tempstore_repository
   *   The job tempstore repository.
   * @param \Drupal\checklist\ChecklistItemHandlerManager $manager
   *   The checklist item handler manager.
   * @param \Drupal\entity_template\TemplateBlueprintProviderManager $blueprint_provider_manager
   *   The blueprint provider manager service.
   * @param \Drupal\entity_template\BlueprintTempstoreRepository $blueprint_tempstore_repository
   *   The blueprint tempstore repository.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_factory
   *   The plugin form factory service.
   * @param \Drupal\task_job\Plugin\JobTrigger\JobTriggerManager $job_trigger_manager
   *   The job trigger manager service.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository,
    ChecklistItemHandlerManager $manager,
    TemplateBlueprintProviderManager $blueprint_provider_manager,
    BlueprintTempstoreRepository $blueprint_tempstore_repository,
    PluginFormFactoryInterface $plugin_form_factory,
    JobTriggerManager $job_trigger_manager
  ) {
    $this->tempstoreRepository = $tempstore_repository;
    $this->blueprintTempstoreRepository = $blueprint_tempstore_repository;
    $this->manager = $manager;
    $this->blueprintProviderManager = $blueprint_provider_manager;
    $this->pluginFormFactory = $plugin_form_factory;
    $this->jobTriggerManager = $job_trigger_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntity(EntityInterface $entity) {
    if (!($entity instanceof JobInterface)) {
      throw new \InvalidArgumentException('This form can only be used with job entities.');
    }

    if ($this->tempstoreRepository->has($entity)) {
      $entity = $this->tempstoreRepository->get($entity);
    }
    else {
      // Get the trigger collection before setting it to the tempstore.
      $entity->getTriggerCollection();
      $this->tempstoreRepository->set($entity);
    }

    return parent::setEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['#attributes']['novalidate'] = 'novalidate';

    $unchanged = $this->entityTypeManager->getStorage($this->entity->getEntityTypeId())
      ->loadUnchanged($this->entity->id());
    if (
      $this->entity->toArray() !== $unchanged->toArray()
    ) {
      $form['changed'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['task-job-changed', 'messages', 'messages--warning'],
        ],
        '#children' => $this->t('You have unsaved changes.'),
        '#weight' => -10,
      ];
    }
    if ($this->entity->uuid() !== $unchanged->uuid()) {
      $form['uuid_error'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['task-job-changed', 'messages', 'messages--error'],
        ],
        '#children' => $this->t('Your changes are no longer compatible with the stored job. Please take note of any changes, select "Cancel" at the bottom of this page and apply your changes again.'),
        '#weight' => -12,
      ];
    }

    $ajax_attributes = [
      'attributes' => [
        'class' => [
          'use-ajax',
        ],
        'data-dialog-type' => 'dialog',
        'data-dialog-renderer' => 'off_canvas',
      ],
    ];

    $form['context_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Contexts'),
      '#description' => $this->t('Configure the contexts for this job'),
      '#open' => TRUE,
    ];
    if (!is_array($form_state->get('context'))) {
      $form_state->set('context', $this->entity->getContextDefinitions());
    }
    /** @var \Drupal\typed_data\Context\ContextDefinitionInterface[] $context */
    $context = $form_state->get('context');

    $context_type_options = [];
    $types = \Drupal::typedDataManager()->getDefinitions();
    foreach ($types as $type => $definition) {
      $category = new TranslatableMarkup('Data');
      if (!empty($definition['deriver']) && !empty($types[$definition['id']])) {
        $category = $types[$definition['id']]['label'];
      }
      $context_type_options[(string) $category][$type] = $definition['label'];
    }

    $form['context_wrapper']['context'] = [
      '#prefix' => '<div id="context-table-wrapper">',
      '#suffix' => '</div>',
      '#parents' => ['context'],
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('Machine-name'),
        $this->t('Type'),
        $this->t('Required'),
        $this->t('Multiple'),
        $this->t('Operations'),
      ],
    ];
    foreach ($context as $key => $context_definition) {
      $row = [];
      $row['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#title_display' => 'invisible',
        '#default_value' => $context_definition->getLabel(),
      ];
      $row['key'] = [
        '#type' => 'machine_name',
        '#title' => $this->t('Key'),
        '#title_display' => 'invisible',
        '#default_value' => $key,
        '#machine_name' => [
          'source' => ['context_wrapper', 'context', $key, 'label'],
          'exists' => [static::class, 'contextKeyExists'],
          'standalone' => TRUE,
        ],
        '#disabled' => TRUE,
      ];
      $row['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#title_display' => 'invisible',
        '#options' => $context_type_options,
        '#default_value' => $context_definition->getDataType(),
        '#disabled' => TRUE,
      ];
      $row['required'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Required'),
        '#title_display' => 'invisible',
        '#default_value' => $context_definition->isRequired(),
      ];
      $row['multiple'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Multiple'),
        '#title_display' => 'invisible',
        '#default_value' => $context_definition->isMultiple(),
      ];
      $row['operations'] = [
        '#type' => 'container',
        'remove' => [
          '#type' => 'submit',
          '#name' => 'remove_' . $key,
          '#context_key' => $key,
          '#value' => $this->t('Remove'),
          '#limit_validation_errors' => [],
          '#ajax' => [
            'wrapper' => 'context-table-wrapper',
            'callback' => [static::class, 'formAjaxReloadContext'],
          ],
          '#submit' => [
            '::formSubmitRemoveContext',
          ],
        ],
      ];

      $form['context_wrapper']['context'][$key] = $row;
    }

    $row = [];
    $row['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#title_display' => 'invisible',
    ];
    $row['key'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Key'),
      '#title_display' => 'invisible',
      '#required' => FALSE,
      '#machine_name' => [
        'source' => ['context_wrapper', 'context', '_add_new', 'label'],
        'exists' => [static::class, 'contextKeyExists'],
        'standalone' => TRUE,
      ],
    ];
    $row['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#title_display' => 'invisible',
      '#options' => $context_type_options,
    ];
    $row['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#title_display' => 'invisible',
      '#default_value' => TRUE,
    ];
    $row['multiple'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Multiple'),
      '#title_display' => 'invisible',
      '#default_value' => FALSE,
    ];
    $row['operations'] = [
      '#type' => 'container',
      'add' => [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
        '#limit_validation_errors' => [
          ['context', '_add_new'],
        ],
        '#ajax' => [
          'wrapper' => 'context-table-wrapper',
          'callback' => [static::class, 'formAjaxReloadContext'],
        ],
        '#validate' => [
          '::formValidateAddContext',
        ],
        '#submit' => [
          '::formSubmitAddContext',
        ],
      ],
    ];
    $form['context_wrapper']['context']['_add_new'] = $row;

    $form['resources'] = [
      '#type' => 'details',
      '#title' => $this->t('Resources'),
      '#description' => $this->t('Resources appear on the task page. Sometimes more resources than those configured here might appear, provided by checklist items or other integrations.'),
    ];
    $form['resources']['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Resource'),
      '#url' => Url::fromRoute(
        'task_job.resource.choose_block',
        [
          'task_job' => $this->entity->id(),
        ],
        $ajax_attributes
      ),
      '#attributes' => [
        'class' => ['add-resource-button', 'btn', 'button'],
      ],
    ];
    $form['resources']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Resource'),
        $this->t('Category'),
        $this->t('Operations'),
      ],
    ];
    /** @var \Drupal\Core\Block\BlockPluginInterface $block */
    foreach ($this->entity->getResourcesCollection() as $uuid => $block) {
      $row = [];
      $row['resource'] = $block->label();
      $row['category'] = $block->getPluginDefinition()['category'] ?? $this->t('Other');
      $row['operations']['data'] = [
        '#type' => 'dropbutton',
        '#links' => [
          'configure' => [
            'title' => $this->t('configure'),
            'url' => Url::fromRoute(
              'task_job.resource.configure',
              [
                'task_job' => $this->entity->id(),
                'uuid' => $uuid,
              ],
              [
                'query' => $this->getDestinationArray(),
              ] + $ajax_attributes,
            ),
          ],
          'remove' => [
            'title' => $this->t('remove'),
            'url' => Url::fromRoute(
              'task_job.resource.remove',
              [
                'task_job' => $this->entity->id(),
                'uuid' => $uuid,
              ],
              [
                'query' => $this->getDestinationArray(),
              ] + $ajax_attributes
            ),
          ],
        ],
      ];

      $form['resources']['table']['#rows'][] = $row;
    }

    $form['checklist'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Checklist'),
      '#description' => $this->t('Some help text about checklists'),
      '#open' => TRUE,
    ];

    $form['checklist']['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Checklist Item'),
      '#url' => Url::fromRoute(
        'task_job.checklist_item.choose_handler',
        [
          'task_job' => $this->entity->id(),
        ],
        $ajax_attributes
      ),
      '#attributes' => [
        'class' => ['add-checklist-item-button', 'btn', 'button'],
      ],
    ];

    $form['checklist']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Title'),
        $this->t('Summary'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No checklist items are configured'),
    ];

    $configure_ajax_attributes = $ajax_attributes;
    $configure_ajax_attributes['attributes']['data-dialog-options'] = Json::encode([
      'width' => '650px',
    ]);
    foreach ($this->entity->getChecklistItems() as $name => $definition) {
      /** @var \Drupal\checklist\Plugin\ChecklistItemHandler\ChecklistItemHandlerInterface $plugin */
      $plugin = $this->manager->createInstance(
        $definition['handler'],
        $definition['handler_configuration']
      );

      $row = [];
      $row['name'] = [
        '#markup' => $name,
      ];
      $row['title'] = [
        '#markup' => $definition['label'],
      ];
      $row['summary'] = $plugin->buildConfigurationSummary();
      $row['operations'] = [
        '#type' => 'dropbutton',
        '#links' => [
          'configure' => [
            'title' => $this->t('configure'),
            'url' => Url::fromRoute(
              'task_job.checklist_item.configure',
              [
                'task_job' => $this->entity->id(),
                'name' => $name,
              ],
              [
                'query' => $this->getDestinationArray(),
              ] + $configure_ajax_attributes,
            ),
          ],
          'remove' => [
            'title' => $this->t('remove'),
            'url' => Url::fromRoute(
              'task_job.checklist_item.remove',
              [
                'task_job' => $this->entity->id(),
                'name' => $name,
              ],
              [
                'query' => $this->getDestinationArray(),
              ] + $ajax_attributes
            ),
          ],
        ],
      ];

      $form['checklist']['table'][$name] = $row;
    }

    $form['triggers'] = [
      '#type' => 'details',
      '#title' => $this->t('Triggers'),
      '#description' => $this->t('What triggers tasks of this job?'),
      '#tree' => TRUE,
    ];
    $form['triggers']['__add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Trigger'),
      '#url' => Url::fromRoute(
        'task_job.trigger.choose',
        [
          'task_job' => $this->entity->id(),
        ],
        $ajax_attributes
      ),
      '#attributes' => [
        'class' => ['add-trigger-button', 'btn', 'button'],
      ],
    ];

    /** @var \Drupal\task_job\Plugin\JobTrigger\JobTriggerInterface $trigger */
    foreach ($this->entity->getTriggerCollection() as $key => $trigger) {
      $wrapper_id = Html::cleanCssIdentifier("trigger-{$key}-wrapper");
      $element = [
        '#type' => 'details',
        '#prefix' => '<div id="' . $wrapper_id . '">',
        '#suffix' => '</div>',
        '#title' => $trigger->getLabel(),
        '#description' => $trigger->getDescription(),
      ];
      $element['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove Trigger'),
        '#name' => 'trigger_remove_' . $key,
        '#trigger_key' => $key,
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['button--danger'],
        ],
        '#ajax' => [
          'wrapper' => $wrapper_id,
          'callback' => [static::class, 'formAjaxRemoveTrigger'],
        ],
        '#submit' => [
          '::formSubmitRemoveTrigger',
        ],
      ];
      $element['template'] = [
        '#type' => 'container',
        '#title' => $this->t('Template'),
        '#description' => $this->t('Configure how a task gets created with this job'),
        '#open' => TRUE,
        '#parents' => ['triggers', $key, 'template'],
      ];

      $storage = new BlueprintStorageJobTriggerAdaptor(
        $this->entity,
        $trigger,
        $this->blueprintProviderManager->createInstance('job_trigger')
      );
      $storage = $this->blueprintTempstoreRepository->get($storage);
      $this->blueprintTempstoreRepository->set($storage);
      $this->blueprintStorages[$key] = $storage;
      $template = $this->blueprintStorages[$key]->getTemplate('default');

      if (($template instanceof PluginWithFormsInterface) && $template->hasFormClass("configure")) {
        $plugin_form = $this->pluginFormFactory->createInstance(
          $template,
          "configure"
        );

        $element['template'] = $plugin_form->buildConfigurationForm(
          $element['template'],
          SubformState::createForSubform(
            $element['template'],
            $form,
            $form_state
          )
        );

        // Hide the label and description fields as we don't need them.
        $element['template']['label']['#access'] = FALSE;
        $element['template']['description']['#access'] = FALSE;

        // Change the empty content for the conditions table.
        $element['template']['conditions']['table']['#empty'] = $this->t(
          'The task will always be created on this trigger.',
        );
        $element['template']['conditions']['__add']['#weight'] = 10;

        $element['template']['components']['__add']['#weight'] = 10;
        $element['template']['components']['__add']['#title'] = $this->t('Add Template Component');
      }

      $form['triggers'][$key] = $element;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);

    if (isset($form['uuid_error'])) {
      $actions['submit']['#disabled'] = TRUE;
    }

    if (
      $this->entity->toArray() !==
      $this->entityTypeManager->getStorage($this->entity->getEntityTypeId())
        ->loadUnchanged($this->entity->id())->toArray()
    ) {
      $actions['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => ['::submitFormCancel'],
        '#limit_validation_errors' => [],
      ];
    }

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    /** @var \Drupal\task_job\Entity\Job $entity */
    $context_definitions = $entity->getContextDefinitions();

    parent::copyFormValuesToEntity($entity, $form, $form_state);

    // Remove the add new item added by copyFormValuesToEntity.
    $context_values = $entity->get('context');
    unset($context_values['_add_new']);
    foreach ($context_values as $key => $context_value) {
      if (isset($context_definitions[$key])) {
        $context_definition = $context_definitions[$key];
        $context_definition->setLabel($context_value['label']);
        $context_definition->setMultiple(!empty($context_value['multiple']));
        $context_definition->setRequired(!empty($context_value['required']));
        $context_values[$key] = $context_definition->toArray();
      }
      else {
        unset($context_values[$key]);
      }
    }
    $entity->set('context', $context_values);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    foreach (Element::children($form['triggers']) as $key) {
      if ($key === '__add') {
        continue;
      }

      $storage = $this->blueprintStorages[$key];
      $template = $storage->getTemplate('default');

      if (
        ($template instanceof PluginWithFormsInterface) &&
        $template->hasFormClass("configure")
      ) {
        $plugin_form = $this->pluginFormFactory->createInstance(
          $template,
          "configure"
        );

        $plugin_form->submitConfigurationForm(
          $form['triggers'][$key]['template'],
          SubformState::createForSubform(
            $form['triggers'][$key]['template'],
            $form,
            $form_state
          )
        );
      }
    }

    $triggers_config = [];
    foreach ($this->blueprintStorages as $key => $storage) {
      $trigger = $storage->getTrigger();

      $triggers_config[$key] = [
        'id' => $trigger instanceof Missing ? $trigger->getIntendedPluginId() : $trigger->getPluginId(),
        'key' => $trigger->getKey(),
        'template' => $storage->getTemplate('default')->getConfiguration(),
      ] + $trigger->getConfiguration();
    }
    $this->entity->set('triggers', $triggers_config);
    $this->entity->set('resources', $this->entity->getResourcesCollection()->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $return = parent::save($form, $form_state);

    foreach ($this->blueprintStorages as $key => $storage) {
      $this->blueprintTempstoreRepository->delete($storage);
    }
    $this->tempstoreRepository->delete($this->entity);

    $this->messenger()->addStatus($this->t('The job has been saved.'));

    return $return;
  }

  /**
   * Submit the cancel button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitFormCancel(array $form, FormStateInterface $form_state) {
    foreach ($this->blueprintStorages as $key => $storage) {
      $this->blueprintTempstoreRepository->delete($storage);
    }
    $this->tempstoreRepository->delete($this->entity);
  }

  /**
   * Validate the information entered for the new context.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formValidateAddContext(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValue(['context', '_add_new']);
    $row = &$form['context_wrapper']['context']['_add_new'];
    if (empty($values['key'])) {
      $form_state->setError($row['key'], new TranslatableMarkup('Context requires a unique machine name.'));
    }
    if (empty($values['label'])) {
      $form_state->setError($row['label'], new TranslatableMarkup('Context requires a label.'));
    }
  }

  /**
   * Submit to add a required context.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formSubmitAddContext(array $form, FormStateInterface $form_state) {
    $context = $form_state->get('context');

    $values = $form_state->getValue(['context', '_add_new']);

    $new_context = ContextDefinition::createFromArray($values);
    $context[$values['key']] = $new_context;
    $this->entity->addContextDefinition($values['key'], $new_context);

    $form_state->set('context', $context);
    $form_state->setRebuild(TRUE);

    $this->tempstoreRepository->set($this->entity);

    $user_input = &$form_state->getUserInput();
    unset($user_input['context']['_add_new']);
  }

  /**
   * Submit to remove a required context.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formSubmitRemoveContext(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $context = $form_state->get('context');
    unset($context[$button['#context_key']]);
    $form_state->set('context', $context);
    $this->entity->removeContextDefinition($button['#context_key']);
    $form_state->setRebuild(TRUE);

    $this->tempstoreRepository->set($this->entity);
  }

  /**
   * Ajax callback to reload the required context.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The context table portion of the form array.
   */
  public static function formAjaxReloadContext(array $form, FormStateInterface $form_state) {
    return $form['context_wrapper']['context'];
  }

  /**
   * Check whether the machine name of a required context exists already.
   *
   * @param mixed $value
   *   The value provided.
   * @param array $element
   *   The element being tested.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   True if it exists, false otherwise.
   */
  public static function contextKeyExists($value, array $element, FormStateInterface $form_state) {
    $context = $form_state->get('context');
    return !empty($context[$value]) && !in_array($value, $element['#parents']);
  }

  /**
   * Submit to remove a trigger.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formSubmitRemoveTrigger(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $this->entity->getTriggerCollection()->removeInstanceId($button['#trigger_key']);
    $triggers = $this->entity->getTriggersConfiguration();
    unset($triggers[$button['#trigger_key']]);
    $this->entity->set('triggers', $triggers);
    $form_state->setRebuild(TRUE);

    $this->blueprintTempstoreRepository->delete($this->blueprintStorages[$button['#trigger_key']]);
    unset($this->blueprintStorages[$button['#trigger_key']]);
    $this->tempstoreRepository->set($this->entity);
  }

  /**
   * Ajax callback to reload the trigger section.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax commands to execute.
   */
  public static function formAjaxRemoveTrigger(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('#' . $triggering_element['#ajax']['wrapper']));
    return $response;
  }

}
