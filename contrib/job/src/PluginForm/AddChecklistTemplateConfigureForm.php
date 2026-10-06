<?php

namespace Drupal\task_job\PluginForm;

use Drupal\checklist\ChecklistContextMapping;
use Drupal\task_job\TemplateInputCardinality;
use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\checklist\Form\ConfigurationForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Selects a job template and maps the inputs owned by that template.
 */
class AddChecklistTemplateConfigureForm extends PluginFormBase implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(protected ContextHandlerInterface $contextHandler, protected TemplateInputCardinality $cardinality) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('context.handler'), $container->get('task_job.template_input_cardinality'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $configuration = ConfigurationForm::input($form, $form_state) + $this->plugin->getConfiguration();
    $templates = $form_state->getTemporaryValue('checklist_templates') ?? [];
    $form['#tree'] = TRUE;
    $form['#attributes']['data-template-invocation'] = TRUE;
    $form['#attached']['library'][] = 'task_job/template-invocation';
    $form['template'] = [
      '#type' => 'select',
      '#title' => $this->t('Checklist template'),
      '#attributes' => ['data-template-input-refresh' => TRUE],
      '#options' => array_map(static fn(array $template) => $template['label'], $templates),
      '#empty_option' => $this->t('- Select a template -'),
      '#required' => TRUE,
      '#default_value' => $configuration['template'],
      '#description' => $this->t('Activate these items immediately after this item completes. Each invocation has independent inputs, outcomes and history.'),
    ];
    $form['update'] = ConfigurationForm::button($form['#parents'], $this->t('Update template inputs'));
    $form['update']['#attributes'] = ['class' => ['js-hide'], 'data-template-input-update' => TRUE];
    $form['update']['#ajax'] = ['callback' => [static::class, 'refreshInputs']];
    $form['#template_inputs'] = $templates[$configuration['template']]['context'] ?? [];
    $definitions = ChecklistContextMapping::definitions($form['#template_inputs']);
    $contexts = $form_state->getTemporaryValue('gathered_contexts') ?? [];
    $collections = $this->cardinality->collections($definitions, $configuration['context_mapping'], $contexts);
    foreach ($collections as $name) {
      $definitions[$name]->setMultiple(TRUE);
    }
    $form['iteration_help'] = [
      '#markup' => '<p>' . $this->t('Map a collection to a single-value input to run the template for each member. Multiple collection mappings run every combination. Inputs declared multiple-value receive the whole collection.') . '</p>',
    ];
    foreach ($definitions as $definition) {
      // Allow incomplete drafts; required runtime inputs block execution.
      $definition->setRequired(FALSE);
    }
    $mapping = ChecklistContextMapping::fromDefinitions($definitions, $configuration['context_mapping']);
    $form['context_mapping'] = ['#tree' => TRUE];
    if (method_exists($this->contextHandler, 'getContextAssignmentElement')) {
      $form['context_mapping'] = $this->contextHandler->getContextAssignmentElement($mapping, $contexts);
    }
    else {
      foreach ($definitions as $name => $definition) {
        $matches = $this->contextHandler->getMatchingContexts($contexts, $definition);
        $declared = ChecklistContextMapping::definitions($form['#template_inputs'])[$name];
        if (!$declared->isMultiple()) {
          $alternative = clone $definition;
          $alternative->setMultiple(!$definition->isMultiple());
          $matches += $this->contextHandler->getMatchingContexts($contexts, $alternative);
        }
        $form['context_mapping'][$name] = [
          '#type' => 'select',
          '#title' => $definition->getLabel(),
          '#options' => array_map(static fn($context) => $context->getContextDefinition()->getLabel(), $matches),
          '#empty_option' => $this->t('- Not mapped -'),
          '#default_value' => $configuration['context_mapping'][$name] ?? '',
        ];
      }
    }
    foreach ($definitions as $name => $definition) {
      $declared = ChecklistContextMapping::definitions($form['#template_inputs'])[$name];
      $form['context_mapping'][$name]['#description'] = $declared->isMultiple()
        ? $this->t('Receives the whole collection.')
        : $this->t('Accepts one value or a collection. A collection runs one invocation per member as @input.', ['@input' => $definition->getLabel()]);
      if (in_array($name, $collections, TRUE)) {
        $form['context_mapping'][$name]['#title'] = $this->t('For each … as @input', ['@input' => $definition->getLabel()]);
      }
    }
    $form['context_mapping']['#type'] = 'details';
    $form['context_mapping']['#title'] = $this->t('Template input mapping');
    $form['context_mapping']['#open'] = TRUE;
    return $form;
  }

  /**
   * Replaces the configuration form without committing the job draft.
   */
  public static function refreshInputs(array &$form, FormStateInterface $form_state): AjaxResponse {
    $selector = Html::getId($form_state->getBuildInfo()['form_id']);
    return (new AjaxResponse())->addCommand(new ReplaceCommand('form[data-drupal-selector="' . $selector . '"]', $form));
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $template = $form_state->getValue('template');
    if (!isset($form['template']['#options'][$template])) {
      $form_state->setError($form['template'], $this->t('Select an existing checklist template.'));
      return;
    }
    $configuration = $this->plugin->getConfiguration();
    $configuration['template'] = $template;
    $configuration['context_mapping'] = array_intersect_key(
      array_filter($form_state->getValue('context_mapping', [])),
      ChecklistContextMapping::definitions($form['#template_inputs']),
    );
    $form['#validated_configuration'] = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->plugin->setConfiguration($form['#validated_configuration']);
  }

}
