<?php

namespace Drupal\task_job\PluginForm\EntityTemplate\Component;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Add form for task context components.
 */
class TaskContextAddForm extends PluginFormBase {

  /**
   * The component plugin.
   *
   * @var \Drupal\task_job\Plugin\EntityTemplate\Component\TaskContextBase
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $definitions = $this->plugin->getTaskContextDefinitions();
    $configuration = $this->plugin->getConfiguration();
    if ($definitions) {
      $form['task_context'] = [
        '#type' => 'select',
        '#title' => new TranslatableMarkup('Context'),
        '#options' => [],
        '#default_value' => $configuration['task_context'] ?? NULL,
        '#required' => TRUE,
      ];

      foreach ($definitions as $key => $definition) {
        $form['task_context']['#options'][$key] = $definition->getLabel();
      }
    }
    else {
      $form['message'] = [
        '#markup' => new TranslatableMarkup('Cannot detect any contexts on this task.'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $configuration = $this->plugin->getConfiguration();
    $configuration['task_context'] = $form_state->getValue('task_context');
    $this->plugin->setConfiguration($configuration);
  }

}
