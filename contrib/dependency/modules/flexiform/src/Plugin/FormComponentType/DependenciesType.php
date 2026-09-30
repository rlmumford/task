<?php

namespace Drupal\task_dependency_flexiform\Plugin\FormComponentType;

use Drupal\flexiform\FormComponent\FormComponentTypeBase;

/**
 * Provides task dependency editing in standalone and embedded forms.
 *
 * @FormComponentType(
 *   id = "task_dependencies",
 *   label = @Translation("Task dependencies"),
 *   component_class = "Drupal\task_dependency_flexiform\Plugin\FormComponentType\Dependencies"
 * )
 */
class DependenciesType extends FormComponentTypeBase {}
