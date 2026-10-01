<?php

namespace Drupal\task_job;

use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\checklist\ChecklistConditionEvaluator;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\task\Entity\Task;
use Drupal\typed_data_plus\Plugin\Condition\ContextAwareCondition;

/**
 * Evaluates ordered job rules using fresh task contexts on every assignment.
 */
class AssignmentRules {
  use StringTranslationTrait;

  /**
   * Constructs the rule evaluator.
   */
  public function __construct(protected ChecklistConditionEvaluator $conditions, protected ContextHandlerInterface $contextHandler) {}

  /**
   * Supplies definitions for authoring, or current values when a task is given.
   *
   * The task root is reserved. Job contexts keep their existing namespaced IDs.
   */
  public function contexts(JobInterface $job, ?Task $task = NULL): array {
    $contexts = ['task' => new Context(ContextDefinition::create('entity:task')->setLabel($this->t('Task')), $task)];
    foreach ($job->getContextDefinitions() as $name => $definition) {
      $context = new Context($definition, $task?->get('context')->get($name));
      $contexts['task_context:' . $name] = $context;
      // Simple aliases support condition-string roots; task stays reserved.
      $contexts[$name] ??= $context;
    }
    return $contexts;
  }

  /**
   * Tests a rule; missing required inputs do not match, broken config throws.
   */
  public function matches(array $configuration, array $contexts): bool {
    if (empty($configuration['condition'])) {
      return TRUE;
    }
    $config = $configuration['condition'];
    $condition = $this->conditions->createCondition($config, $contexts);
    try {
      if ($condition instanceof ContextAwareCondition) {
        $condition->setRuntimeContexts($contexts);
      }
      else {
        $this->contextHandler->applyContextMapping($condition, $contexts);
      }
      return (bool) $condition->execute();
    }
    catch (MissingValueContextException) {
      return FALSE;
    }
  }

  /**
   * Resolves a matched rule; missing values cannot trigger a different rule.
   */
  public function assignee(array $configuration, array $contexts): mixed {
    $rule = new AssignmentRule($configuration);
    try {
      $this->contextHandler->applyContextMapping($rule, $contexts);
      return $rule->getContextValue('assignee');
    }
    catch (MissingValueContextException) {
      return NULL;
    }
  }

}
