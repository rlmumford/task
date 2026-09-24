<?php

namespace Drupal\task_job\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Render\Markup;
use Drupal\task_job\JobInterface;

/**
 * The job list builder.
 */
class JobListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * Versioned jobs are addressed through their explicit version routes and
   * are deliberately omitted from the ordinary job administration list.
   */
  public function load() {
    return array_filter(parent::load(), static function (EntityInterface $entity): bool {
      return !($entity instanceof JobInterface && $entity->isVersioned());
    });
  }

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
    if ($entity instanceof JobInterface && !$entity->isVersioned()) {
      $operations['versions'] = [
        'title' => $this->t('Versions'),
        'weight' => 13,
        'url' => $this->ensureDestination($entity->toUrl('versions')),
      ];
    }
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
