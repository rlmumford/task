<?php

namespace Drupal\task_job\Plugin\ChecklistItemHandler;

use Drupal\checklist\Attempt\ChecklistAttempt;
use Drupal\checklist\ChecklistConditionEvaluator;
use Drupal\checklist\ChecklistContextMapping;
use Drupal\checklist\Execution\ChecklistItemResult;
use Drupal\checklist\Plugin\ChecklistItemHandler\AutomaticChecklistItemHandlerBase;
use Drupal\checklist\Plugin\ChecklistItemHandler\ExpectedOutcomeChecklistItemHandlerInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\task_job\JobChecklistExpansion;
use Drupal\task_job\JobExecutionAuthorization;
use Drupal\task_job\JobInterface;
use Drupal\task_job\Plugin\ChecklistType\Job;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activates one scoped template instance through the audited item executor.
 *
 * @ChecklistItemHandler(
 *   id = "add_checklist_template",
 *   label = @Translation("Add checklist template"),
 *   category = @Translation("Task job"),
 *   forms = {
 *     "configure" = "\Drupal\task_job\PluginForm\AddChecklistTemplateConfigureForm"
 *   }
 * )
 */
class AddChecklistTemplate extends AutomaticChecklistItemHandlerBase implements ExpectedOutcomeChecklistItemHandlerInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ChecklistConditionEvaluator $conditions, protected JobExecutionAuthorization $authorization) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $conditions);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('checklist.condition_evaluator'), $container->get('task_job.execution_authorization'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['template' => '', 'context_mapping' => []] + parent::defaultConfiguration();
  }

  /**
   * Requires a live job checklist with authoritative template definitions.
   */
  protected function job(): JobInterface {
    $type = $this->getItem()->get('checklist')->checklist->getType();
    if (!$type instanceof Job || isset($type->getConfiguration()['default_items']) || !$job = $type->getJob()) {
      throw new \DomainException('Template expansion requires a live task job checklist.');
    }
    return $job;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    if (!$this->item) {
      return [];
    }
    $template = $this->getConfiguration()['template'];
    $templates = $this->job()->get('checklist_templates') ?: [];
    if (!isset($templates[$template])) {
      throw new \InvalidArgumentException('Select an existing checklist template.');
    }
    return ChecklistContextMapping::definitions($templates[$template]['context'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function expectedOutcomeDefinitions(): array {
    return ['template' => DataDefinition::create('string')->setLabel($this->t('Activated checklist template'))];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationSummary(): array {
    return ['#plain_text' => $this->getConfiguration()['template']];
  }

  /**
   * {@inheritdoc}
   */
  public function actionIteration(ChecklistAttempt $attempt): ChecklistItemResult {
    $job = $this->job();
    $configuration = $this->getConfiguration();
    $definitions = JobChecklistExpansion::instance($configuration['template'], $job->get('checklist_templates') ?: [], '', $configuration['context_mapping']);
    foreach ($definitions as $definition) {
      if (($definition['execution']['mode'] ?? 'self') === 'context') {
        $this->authorization->approvedGrant($job);
        break;
      }
    }
    // Completion and the selected-template outcome commit together. Stable
    // derived names activate the existing definitions without duplicating work.
    return new ChecklistItemResult(
      ChecklistAttempt::SUCCEEDED,
      outcomes: ['template' => $configuration['template']],
      reason: 'Checklist template activated.',
    );
  }

}
