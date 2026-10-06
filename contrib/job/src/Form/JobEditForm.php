<?php

namespace Drupal\task_job\Form;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\checklist\ChecklistItemHandlerManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\InvokeCommand;
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
use Drupal\task_job\JobVersionResolverInterface;
use Drupal\task_job\JobVersionId;
use Drupal\task_job\Plugin\EntityTemplate\BlueprintProvider\BlueprintStorageJobTriggerAdaptor;
use Drupal\task_job\Plugin\JobTrigger\JobTriggerManager;
use Drupal\task_job\Plugin\JobTrigger\Missing;
use Drupal\task_job\TaskJobTempstoreRepository;
use Drupal\task_job\TriggerActionManager;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
   * The job version resolver.
   *
   * @var \Drupal\task_job\JobVersionResolverInterface
   */
  protected $jobVersionResolver;

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
      $container->get('plugin.manager.task_job.trigger'),
      $container->get('task_job.version_resolver'),
      $container->get('plugin.manager.task_job.trigger_action')
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
   * @param \Drupal\task_job\JobVersionResolverInterface $job_version_resolver
   *   The job version resolver.
   * @param \Drupal\task_job\TriggerActionManager $triggerActionManager
   *   The trigger action manager.
   */
  public function __construct(
    TaskJobTempstoreRepository $tempstore_repository,
    ChecklistItemHandlerManager $manager,
    TemplateBlueprintProviderManager $blueprint_provider_manager,
    BlueprintTempstoreRepository $blueprint_tempstore_repository,
    PluginFormFactoryInterface $plugin_form_factory,
    JobTriggerManager $job_trigger_manager,
    JobVersionResolverInterface $job_version_resolver,
    protected TriggerActionManager $triggerActionManager,
  ) {
    $this->tempstoreRepository = $tempstore_repository;
    $this->blueprintTempstoreRepository = $blueprint_tempstore_repository;
    $this->manager = $manager;
    $this->blueprintProviderManager = $blueprint_provider_manager;
    $this->pluginFormFactory = $plugin_form_factory;
    $this->jobTriggerManager = $job_trigger_manager;
    $this->jobVersionResolver = $job_version_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntity(EntityInterface $entity) {
    if (!($entity instanceof JobInterface)) {
      throw new \InvalidArgumentException('This form can only be used with job entities.');
    }

    $entity = $this->tempstoreRepository->get($entity);

    return parent::setEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $this->blueprintStorages = [];
    $section = $this->section($form_state);
    if (!$form_state->has('selected_template')) {
      $form_state->set('selected_template', $this->getRouteMatch()->getParameter('template'));
    }
    $this->tempstoreRepository->set($this->entity, $section, $form_state->get('selected_template'));
    $form['#tree'] = TRUE;
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#attributes']['class'][] = 'task-job-editor';
    $form['#attributes']['id'] = 'task-job-editor';
    $form['#attached']['library'][] = 'task_job/editor';
    $form['#cache']['max-age'] = 0;
    $form['draft_notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['task-job-draft-notice']],
      '#markup' => $this->tempstoreRepository->isChanged($this->entity)
        ? $this->t('You have unsaved changes. Save commits the whole job; Discard changes restores the saved configuration.')
        : $this->t('Changes stay in your working draft as you move between tabs. Save commits the whole job.'),
    ];
    $form['heading'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->sections()[$section]];
    $ajax_attributes = [
      'query' => $this->getDestinationArray(),
      'attributes' => [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'dialog',
        'data-dialog-renderer' => 'off_canvas',
        'data-dialog-options' => Json::encode(['width' => '650px']),
      ],
    ];
    if ($section === 'settings') {
      $form = parent::form($form, $form_state);
      unset($form['assignment']);
      $form['id'] = ['#type' => 'item', '#title' => $this->t('Machine name'), '#plain_text' => $this->entity->id()];
      $form = $this->buildResources($form, $form_state, $ajax_attributes);
    }
    elseif ($section === 'assignment') {
      $settings = parent::form([], $form_state);
      $form['assignment'] = $settings['assignment'];
      $form = $this->buildAssignmentRules($form, $form_state, $ajax_attributes);
    }
    else {
      $method = [
        'checklist' => 'buildChecklist',
        'triggers' => 'buildTriggers',
        'contexts' => 'buildContexts',
        'templates' => 'buildTemplates',
      ][$section];
      $form = $this->$method($form, $form_state, $ajax_attributes);
    }
    return $form;
  }

  /**
   * Lists rules in evaluation order, using the existing draft/dialog workflow.
   */
  protected function buildAssignmentRules(array $form, FormStateInterface $form_state, array $ajax_attributes): array {
    $form['assignment']['#title'] = $this->t('Fallback assignment');
    $form['assignment']['#description'] = $this->t('Used only when no assignment rule matches. Explicit task assignees are never replaced.');
    $form['assignment_help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Rules run from top to bottom when an unassigned task is saved. The first matching rule wins. Drag rows to change their order.'),
    ];
    $form['add_assignment_rule'] = [
      '#type' => 'link',
      '#title' => $this->t('Add assignment rule'),
      '#url' => Url::fromRoute('task_job.assignment.add', ['task_job' => $this->entity->id()], $ajax_attributes),
      '#attributes' => ['class' => ['button']],
    ];
    $form['assignment_rules'] = [
      '#type' => 'table',
      '#header' => [$this->t('Rule'), $this->t('Assignee context'), $this->t('Operations'), $this->t('Order')],
      '#empty' => $this->t('No assignment rules. The fallback assignment applies.'),
      '#tabledrag' => [['action' => 'order', 'relationship' => 'sibling', 'group' => 'assignment-rule-weight']],
    ];
    foreach (array_keys($this->entity->get('assignment_rules') ?: []) as $weight => $key) {
      $rule = $this->entity->get('assignment_rules')[$key];
      $form['assignment_rules'][$key] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $weight,
        'label' => ['#plain_text' => $rule['label']],
        'assignee' => ['#plain_text' => $rule['context_mapping']['assignee']],
        'operations' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['task-job-assignment-operations']],
          'configure' => [
            '#type' => 'link',
            '#title' => $this->t('Configure'),
            '#url' => Url::fromRoute('task_job.assignment.configure', [
              'task_job' => $this->entity->id(),
              'rule' => $key,
            ], $ajax_attributes),
          ],
          'remove' => [
            '#type' => 'submit',
            '#value' => $this->t('Remove'),
            '#name' => 'remove_assignment_' . $key,
            '#rule' => $key,
            '#submit' => ['::submitForm', '::removeAssignmentRule'],
          ],
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Order for @rule', ['@rule' => $rule['label']]),
          '#title_display' => 'invisible',
          '#default_value' => $weight,
          '#delta' => max(10, count($this->entity->get('assignment_rules'))),
          '#attributes' => ['class' => ['assignment-rule-weight']],
        ],
      ];
    }
    return $form;
  }

  /**
   * Removes a rule from the draft only.
   */
  public function removeAssignmentRule(array &$form, FormStateInterface $form_state): void {
    $rules = $this->entity->get('assignment_rules');
    unset($rules[$form_state->getTriggeringElement()['#rule']]);
    $this->entity->set('assignment_rules', $rules);
    $this->saveDraft($form, $form_state);
  }

  /**
   * The available job configuration areas.
   */
  protected function sections(): array {
    return [
      'checklist' => $this->t('Checklist'),
      'triggers' => $this->t('Triggers'),
      'contexts' => $this->t('Contexts'),
      'templates' => $this->t('Checklist templates'),
      'assignment' => $this->t('Assignment rules'),
      'settings' => $this->t('Settings'),
    ];
  }

  /**
   * Keeps the submitted form's section stable through AJAX and validation.
   */
  protected function section(FormStateInterface $form_state): string {
    if (!$form_state->has('job_section')) {
      $section = $this->getRouteMatch()->getRouteObject()->getDefault('_job_section') ?? 'checklist';
      $form_state->set('job_section', isset($this->sections()[$section]) ? $section : 'checklist');
    }
    return $form_state->get('job_section');
  }

  /**
   * Builds expected contexts for the job and its checklist handlers.
   */
  protected function buildContexts(array $form, FormStateInterface $form_state, array $ajax_attributes): array {
    $form['context_wrapper'] = [
      '#type' => 'container',
      '#title' => $this->t('Contexts'),
      '#description' => $this->t('Configure the contexts for this job'),
      '#open' => TRUE,
    ];
    if (!is_array($form_state->get('context'))) {
      $form_state->set('context', $this->entity->getContextDefinitions());
    }
    /** @var \Drupal\Core\Plugin\Context\ContextDefinitionInterface[] $context */
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

    return $form;
  }

  /**
   * Builds the resource configuration within Settings.
   */
  protected function buildResources(array $form, FormStateInterface $form_state, array $ajax_attributes): array {
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
                'query' => ($ajax_attributes['query'] ?? []) + $this->getDestinationArray(),
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
                'query' => ($ajax_attributes['query'] ?? []) + $this->getDestinationArray(),
              ] + $ajax_attributes
            ),
          ],
        ],
      ];

      $form['resources']['table']['#rows'][] = $row;
    }

    return $form;
  }

  /**
   * Builds the checklist item management table.
   */
  protected function buildChecklist(array $form, FormStateInterface $form_state, array $ajax_attributes, ?string $template = NULL): array {
    if ($template !== NULL) {
      $ajax_attributes['query']['template'] = $template;
    }
    else {
      $options = [];
      foreach ($this->entity->get('checklist_templates') ?: [] as $name => $definition) {
        $options[$name] = $definition['label'];
      }
      foreach ($this->entity->get('checklist_includes') ?: [] as $name) {
        $options[$name] ??= $this->t('Missing template: @name', ['@name' => $name]);
      }
      $form['checklist_includes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Include checklist templates'),
        '#description' => $this->t('Selected templates add their items after the default items. Item names must be unique across the resulting checklist.'),
        '#options' => $options,
        '#default_value' => $this->entity->get('checklist_includes') ?: [],
        '#access' => !empty($options),
      ];
    }
    $form['checklist'] = [
      '#type' => 'container',
      '#title' => $this->t('Default Checklist'),
      '#description' => $this->t('Configure the items staff and automation use to complete this job.'),
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
    foreach ($this->entity->getChecklistItems($template) as $name => $definition) {
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
                'query' => ($ajax_attributes['query'] ?? []) + $this->getDestinationArray(),
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
                'query' => ($ajax_attributes['query'] ?? []) + $this->getDestinationArray(),
              ] + $ajax_attributes
            ),
          ],
        ],
      ];

      $form['checklist']['table'][$name] = $row;
    }

    return $form;
  }

  /**
   * Edits named definitions within the same job working copy.
   */
  protected function buildTemplates(array $form, FormStateInterface $form_state, array $ajax_attributes): array {
    $form['template_help'] = ['#markup' => $this->t('Define named groups of checklist items here, then select them on the Checklist tab. Templates share this job version and can declare their own inputs alongside normal task contexts.')];
    $name = $form_state->get('selected_template');
    if ($name !== NULL) {
      $templates = $this->entity->get('checklist_templates') ?: [];
      if (!isset($templates[$name])) {
        throw new NotFoundHttpException();
      }
      $template = $templates[$name];
      $form['heading']['#value'] = $template['label'];
      $element = [
        '#type' => 'container',
        'machine_name' => [
          '#type' => 'item',
          '#title' => $this->t('Machine name'),
          '#plain_text' => $name,
          '#weight' => -4,
        ],
        'label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Template label'),
          '#weight' => -3,
          '#default_value' => $template['label'],
          '#required' => TRUE,
        ],
      ];
      $element['context'] = [
        '#type' => 'details',
        '#title' => $this->t('Template contexts'),
        '#open' => TRUE,
        '#weight' => -2,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Input'),
            ['data' => $this->t('Machine name'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
            ['data' => $this->t('Type'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
            ['data' => $this->t('Required'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
            ['data' => $this->t('Multiple'), 'class' => [RESPONSIVE_PRIORITY_LOW]],
            $this->t('Operations'),
          ],
          '#empty' => $this->t('No template inputs defined.'),
        ],
      ];
      $dialog = $ajax_attributes;
      $dialog['attributes']['data-dialog-options'] = Json::encode(['width' => '550px']);
      foreach ($template['context'] ?? [] as $key => $definition) {
        $element['context']['table'][$key] = [
          'label' => ['#plain_text' => $definition['label']],
          'name' => ['#plain_text' => $key],
          'type' => ['#plain_text' => $definition['type']],
          'required' => ['#plain_text' => ($definition['required'] ?? TRUE) ? $this->t('Yes') : $this->t('No')],
          'multiple' => ['#plain_text' => !empty($definition['multiple']) ? $this->t('Yes') : $this->t('No')],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('task_job.template_context.edit', [
              'task_job' => $this->entity->id(),
              'template' => $name,
              'context_name' => $key,
            ], $dialog),
          ],
        ];
      }
      $element['context']['add'] = [
        '#type' => 'link',
        '#title' => $this->t('Add template input'),
        '#attributes' => ['class' => ['button']],
        '#url' => Url::fromRoute('task_job.template_context.add', [
          'task_job' => $this->entity->id(),
          'template' => $name,
        ], $dialog),
      ];
      $element = $this->buildChecklist($element, $form_state, $ajax_attributes, $name);
      $element['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove template'),
        '#name' => 'remove_template_' . $name,
        '#template_name' => $name,
        '#validate' => ['::validateTemplateRemoval'],
        '#submit' => ['::submitForm', '::removeTemplate'],
      ];
      $form['templates'][$name] = $element;
      return $form;
    }
    $form['heading']['#value'] = $this->t('Add checklist template');
    $form['new_template'] = [
      '#type' => 'container',
      'name' => ['#type' => 'textfield', '#title' => $this->t('Template machine name')],
      'label' => ['#type' => 'textfield', '#title' => $this->t('New template label')],
      'add' => [
        '#type' => 'submit',
        '#value' => $this->t('Add template'),
        '#validate' => ['::validateTemplateName'],
        '#submit' => ['::submitForm', '::addTemplate'],
      ],
    ];
    return $form;
  }

  /**
   * Validates a new template name.
   */
  public function validateTemplateName(array &$form, FormStateInterface $form_state): void {
    $name = $form_state->getValue(['new_template', 'name'], '');
    $templates = $this->entity->get('checklist_templates') ?: [];
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name) || isset($templates[$name])) {
      $form_state->setError($form['new_template']['name'], $this->t('Use a unique machine name starting with a lowercase letter, followed by lowercase letters, digits or underscores.'));
    }
    if (trim($form_state->getValue(['new_template', 'label'], '')) === '') {
      $form_state->setError($form['new_template']['label'], $this->t('Enter a template label.'));
    }
  }

  /**
   * Adds a named definition to the draft, never the saved job.
   */
  public function addTemplate(array &$form, FormStateInterface $form_state): void {
    $templates = $this->entity->get('checklist_templates') ?: [];
    $templates[$form_state->getValue(['new_template', 'name'])] = [
      'label' => $form_state->getValue(['new_template', 'label']),
      'items' => [],
    ];
    $this->entity->set('checklist_templates', $templates);
    $form_state->set('selected_template', $form_state->getValue(['new_template', 'name']));
    $this->saveDraft($form, $form_state);
  }

  /**
   * Requires references to be removed before deleting their definition.
   */
  public function validateTemplateRemoval(array &$form, FormStateInterface $form_state): void {
    $name = $form_state->getTriggeringElement()['#template_name'];
    $definitions = [$this->entity->getChecklistItems()];
    foreach ($this->entity->get('checklist_templates') ?: [] as $template) {
      $definitions[] = $template['items'] ?? [];
    }
    foreach ($definitions as $items) {
      foreach ($items as $item) {
        if ($item['handler'] === 'add_checklist_template' && ($item['handler_configuration']['template'] ?? '') === $name) {
          $form_state->setErrorByName('templates', $this->t('Remove this template from expansion items before deleting it.'));
          return;
        }
        if ($item['handler'] !== 'decision') {
          continue;
        }
        foreach ($item['handler_configuration']['options'] ?? [] as $option) {
          if (($option['template'] ?? '') === $name) {
            $form_state->setErrorByName('templates', $this->t('Remove this template from decision choices before deleting it.'));
            return;
          }
        }
      }
    }
    if (in_array($name, $this->entity->get('checklist_includes') ?: [], TRUE)) {
      $form_state->setError($form['templates'][$name]['remove'], $this->t('Remove this template from the Checklist tab before deleting it.'));
    }
  }

  /**
   * Removes an unused definition from the draft.
   */
  public function removeTemplate(array &$form, FormStateInterface $form_state): void {
    $templates = $this->entity->get('checklist_templates');
    unset($templates[$form_state->getTriggeringElement()['#template_name']]);
    $this->entity->set('checklist_templates', $templates);
    $form_state->set('selected_template', NULL);
    $this->saveDraft($form, $form_state);
  }

  /**
   * Builds event, action and task-creation template configuration.
   */
  protected function buildTriggers(array $form, FormStateInterface $form_state, array $ajax_attributes): array {
    $form['triggers'] = [
      '#type' => 'container',
      '#title' => $this->t('Triggers'),
      '#description' => $this->t('Choose the events and actions for this job.'),
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
        '#open' => isset($form_state->getUserInput()['triggers'][$key]['action']),
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
      $action_config = $this->actionConfiguration($trigger, $key, $form_state);
      $options = [];
      foreach ($this->triggerActionManager->getDefinitions() as $id => $definition) {
        if ($trigger->getPluginId() !== 'manual' || $id === 'create_task') {
          $options[$id] = $definition['label'];
        }
      }
      $element['action'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#parents' => ['triggers', $key, 'action'],
      ];
      $element['action']['plugin'] = [
        '#type' => 'select',
        '#title' => $this->t('Action'),
        '#options' => $options,
        '#default_value' => $action_config['plugin'],
        '#required' => TRUE,
        '#ajax' => ['callback' => [static::class, 'formAjaxTriggerAction'], 'wrapper' => $wrapper_id],
      ];
      $element['action']['configuration'] = [
        '#type' => 'container',
        '#parents' => ['triggers', $key, 'action', 'configuration'],
      ];
      $action = $this->triggerActionManager->createInstance($action_config['plugin'], $action_config['configuration']);
      $form_state->set('available_contexts', $trigger->getContexts());
      $element['action']['configuration'] = $action->buildConfigurationForm(
        $element['action']['configuration'],
        SubformState::createForSubform($element['action']['configuration'], $form, $form_state)
      );
      $element['template'] = [
        '#type' => 'container',
        '#title' => $this->t('Template'),
        '#description' => $this->t('Configure when the action applies and, for creation, how the task is built.'),
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
          'The action will always run when this trigger matches.',
        );
        $element['template']['conditions']['__add']['#weight'] = 10;

        $element['template']['components']['#access'] = $action_config['plugin'] === 'create_task';
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
    $actions = ['#type' => 'actions', '#weight' => -20, '#attributes' => ['class' => ['task-job-actions']]];
    $actions['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
      '#name' => 'job_save',
      '#submit' => ['::submitForm', '::save'],
    ];
    $actions['draft'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to draft'),
      '#submit' => ['::submitForm', '::saveDraft'],
    ];
    $actions['discard'] = [
      '#type' => 'submit',
      '#value' => $this->t('Discard changes'),
      '#submit' => ['::submitFormCancel'],
      '#limit_validation_errors' => [],
    ];
    $actions['open_dialog'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply before opening editor'),
      '#name' => 'job_open_dialog',
      '#attributes' => ['class' => ['task-job-open-dialog', 'js-hide']],
      '#submit' => ['::submitForm', '::saveDraft'],
      '#ajax' => ['callback' => '::openEditorDialog'],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // Only the visible tab may change these values. Navigation controls and
    // incomplete trigger form arrays are not entity properties.
    foreach (['label', 'description', 'assignment'] as $property) {
      if (isset($form[$property]) && $form_state->hasValue($property)) {
        $entity->set($property, $form_state->getValue($property));
      }
    }
    if (isset($form['assignment_rules'])) {
      $weights = $form_state->getValue('assignment_rules') ?: [];
      uasort($weights, static fn(array $a, array $b) => $a['weight'] <=> $b['weight']);
      $rules = $entity->get('assignment_rules') ?: [];
      $entity->set('assignment_rules', array_replace(array_intersect_key($weights, $rules), $rules));
    }
    if (isset($form['checklist_includes'])) {
      $entity->set('checklist_includes', array_values(array_filter($form_state->getValue('checklist_includes', []))));
    }
    if (isset($form['templates'])) {
      $templates = $entity->get('checklist_templates');
      foreach ($form_state->getValue('templates', []) as $name => $values) {
        $templates[$name]['label'] = $values['label'];
      }
      $entity->set('checklist_templates', $templates);
    }
    if (isset($form['context_wrapper'])) {
      foreach ($form_state->getValue('context', []) as $key => $values) {
        if ($key !== '_add_new' && isset($entity->getContextDefinitions()[$key])) {
          $definition = $entity->getContextDefinition($key);
          $definition->setLabel($values['label'])->setRequired(!empty($values['required']))->setMultiple(!empty($values['multiple']));
          $entity->addContextDefinition($key, $definition);
        }
      }
    }
  }

  /**
   * Gets posted action settings, falling back to the stored trigger definition.
   */
  protected function actionConfiguration($trigger, string $key, FormStateInterface $form_state): array {
    $input = $form_state->getUserInput() ?? [];
    $submitted = NestedArray::getValue($input, ['triggers', $key, 'action']);
    $stored = $trigger->getConfiguration()['action'] ?? ['plugin' => 'create_task', 'configuration' => []];
    $configuration = is_array($submitted) ? $submitted + $stored : $stored;
    if ($trigger->getPluginId() === 'manual') {
      $configuration = ['plugin' => 'create_task', 'configuration' => []];
    }
    return $configuration + ['configuration' => []];
  }

  /**
   * Rebuilds the trigger panel when its action plugin changes.
   */
  public static function formAjaxTriggerAction(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'];
    return $form['triggers'][$parents[1]];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if (!$form_state->isSubmitted() || $form_state->getLimitValidationErrors() === []) {
      return $entity;
    }
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'job_save') {
      $candidate = clone $this->entity;
      $this->copyFormValuesToEntity($candidate, $form, $form_state);
      try {
        $candidate->getExpandedChecklistItems();
      }
      catch (\InvalidArgumentException $exception) {
        $form_state->setErrorByName('checklist_includes', $exception->getMessage());
      }
    }
    foreach ($this->blueprintStorages as $key => $storage) {
      $configuration = $this->actionConfiguration($storage->getTrigger(), $key, $form_state);
      $action = $this->triggerActionManager->createInstance($configuration['plugin'], $configuration['configuration']);
      $action->validateConfigurationForm($form['triggers'][$key]['action']['configuration'], SubformState::createForSubform($form['triggers'][$key]['action']['configuration'], $form, $form_state));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    if (!isset($form['triggers'])) {
      return;
    }
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
      $action_config = $this->actionConfiguration($trigger, $key, $form_state);
      $action = $this->triggerActionManager->createInstance($action_config['plugin'], $action_config['configuration']);
      $action->submitConfigurationForm($form['triggers'][$key]['action']['configuration'], SubformState::createForSubform($form['triggers'][$key]['action']['configuration'], $form, $form_state));

      $triggers_config[$key] = [
        'action' => ['plugin' => $action_config['plugin'], 'configuration' => $action->getConfiguration()],
        'id' => $trigger instanceof Missing ? $trigger->getIntendedPluginId() : $trigger->getPluginId(),
        'key' => $trigger->getKey(),
        'template' => $storage->getTemplate('default')->getConfiguration(),
      ] + $trigger->getConfiguration();
    }
    $this->entity->set('triggers', $triggers_config);
    $this->entity->getTriggerCollection()->setConfiguration($triggers_config);
    foreach ($this->blueprintStorages as $storage) {
      $this->blueprintTempstoreRepository->delete($storage);
    }
    $this->entity->set('resources', $this->entity->getResourcesCollection()->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $draft = $this->entity;
    $new_override = $draft->isVersioned() && !$draft->isDirty()
      && $this->entityTypeManager->getStorage('task_job')->loadUnchanged(JobVersionId::buildDirty($draft->getBaseJobId(), $draft->getVersion()));
    if ($new_override || !$this->tempstoreRepository->isCurrent($draft)) {
      $this->tempstoreRepository->set($draft);
      $this->messenger()->addError($this->t('The saved job changed while you were editing. Your draft has been retained. Review the changes or discard the draft before saving.'));
      return;
    }
    if ($draft->isVersioned() && !$draft->isDirty()) {
      $this->entity = $this->jobVersionResolver->createDirtyVersion($draft);
    }
    try {
      $this->entity->save();
    }
    catch (AccessDeniedHttpException $exception) {
      $this->tempstoreRepository->set($draft);
      $this->messenger()->addError($exception->getMessage());
      return;
    }
    $this->tempstoreRepository->delete($draft);
    $this->messenger()->addStatus($this->t('The job has been saved.'));
    $form_state->setRedirectUrl($this->tempstoreRepository->getEditUrl($this->entity, $this->section($form_state), $form_state->get('selected_template')));
  }

  /**
   * Retains edits without changing live job configuration.
   */
  public function saveDraft(array $form, FormStateInterface $form_state): void {
    $section = $this->section($form_state);
    $this->tempstoreRepository->set($this->entity, $section, $form_state->get('selected_template'));
    $form_state->setRedirectUrl($this->tempstoreRepository->getEditUrl($this->entity));
  }

  /**
   * Confirms a valid draft before client-side tab navigation or a dialog.
   */
  public function openEditorDialog(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    if ($form_state->hasAnyErrors()) {
      $form['messages'] = ['#type' => 'status_messages', '#weight' => -30];
      $response->addCommand(new ReplaceCommand('#task-job-editor', $form));
    }
    else {
      $response->addCommand(new InvokeCommand('#task-job-editor', 'trigger', ['taskJobDraftSaved']));
    }
    return $response;
  }

  /**
   * Discards the entire draft, including nested trigger template edits.
   */
  public function submitFormCancel(array $form, FormStateInterface $form_state) {
    $this->tempstoreRepository->delete($this->entity);
    $this->entity = $this->entityTypeManager->getStorage('task_job')->loadUnchanged($this->entity->id());
    $form_state->setRedirectUrl($this->tempstoreRepository->getEditUrl($this->entity, $this->section($form_state), $form_state->get('selected_template')));
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
    $this->copyFormValuesToEntity($this->entity, $form, $form_state);
    $context = $this->entity->getContextDefinitions();

    $values = $form_state->getValue(['context', '_add_new']);

    $new_context = ContextDefinition::create($values['type'])
      ->setLabel($values['label'])
      ->setRequired(!empty($values['required']))
      ->setMultiple(!empty($values['multiple']));
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
    $this->copyFormValuesToEntity($this->entity, $form, $form_state);
    $context = $this->entity->getContextDefinitions();
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
