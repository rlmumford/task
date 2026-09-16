<?php

namespace Drupal\task_job\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Render\Markup;

/**
 * The job list builder.
 */
class JobListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'title' => $this->t('Job'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    return [
      'title' => $entity->status() ? $entity->label() : Markup::create("<del>{$entity->label()}</del>"),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    foreach (['enable', 'disable'] as $op) {
      if ($entity->access($op) && $entity->hasLinkTemplate("{$op}-form")) {
        $operations[$op] = [
          'title' => $op === 'enable' ? $this->t('Enable') : $this->t('Disable'),
          'weight' => 12,
          'url' => $this->ensureDestination($entity->toUrl("{$op}-form")),
        ];
      }
    }
    return $operations;
  }

}
