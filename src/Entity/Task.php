<?php

namespace Drupal\task\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\task\TaskInterface;

/**
 * The task entity is used to store tasks.
 *
 * @ContentEntityType(
 *   id = "task",
 *   label = @Translation("Task"),
 *   label_singular = @Translation("task"),
 *   label_plural = @Translation("tasks"),
 *   label_count = @PluralTranslation(
 *     singular = "@count task",
 *     plural = "@count tasks"
 *   ),
 *   bundle_label = @Translation("Task Type"),
 *   handlers = {
 *     "list_builder" = "Drupal\task\TaskListBuilder",
 *     "storage" = "Drupal\task\TaskStorage",
 *     "access" = "Drupal\task\TaskAccessControlHandler",
 *     "query_access" = "Drupal\task\TaskQueryAccessHandler",
 *     "form" = {
 *       "default" = "Drupal\task\TaskForm",
 *       "add" = "Drupal\task\TaskForm"
 *     },
 *     "views_data" = "Drupal\task\Entity\TaskViewsData",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     }
 *   },
 *   has_notes = "true",
 *   base_table = "task",
 *   revision_table = "task_revision",
 *   admin_permission = "administer tasks",
 *   field_ui_base_route = "entity.task.configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "vid",
 *     "uuid" = "uuid",
 *     "label" = "title"
 *   },
 *   links = {
 *     "collection" = "/task",
 *     "canonical" = "/task/{task}",
 *     "edit-form" = "/task/{task}/edit",
 *     "add-form" = "/task/add"
 *   }
 * )
 */
class Task extends ContentEntityBase implements TaskInterface {

  /**
   * {@inheritdoc}
   */
  public static function statusOptionsList() {
    return [
      static::STATUS_PENDING => t('Pending'),
      static::STATUS_ACTIVE => t('Active'),
      static::STATUS_WAITING => t('Waiting (Blocked)'),
      static::STATUS_RESOLVED => t('Resolved'),
      static::STATUS_CLOSED => t('Closed'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function resolutionOptionsList() {
    return [
      static::RESOLUTION_COMPLETE => t('Complete'),
      static::RESOLUTION_INCOMPLETE => t('Incomplete'),
      static::RESOLUTION_INVALID => t('Invalid'),
      static::RESOLUTION_DUPLICATE => t('Duplicate'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setSetting('allowed_values_function', '\Drupal\task\Entity\Task::statusOptionsList')
      ->setDefaultValue(static::STATUS_ACTIVE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'list_default',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // Important Dates.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['updated'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Updated'));
    $fields['start'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Start Date'))
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);
    $fields['due'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Due Date'))
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);
    $fields['deadline'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('External Deadline'))
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);
    $fields['resolved'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date Resolved'))
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setDisplayConfigurable('view', TRUE);

    // Important Users.
    $fields['creator'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'user')
      ->setLabel(t('Creator'));
    $fields['updater'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'user')
      ->setLabel(t('Updater'));
    $fields['assignee'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'user')
      ->setLabel(t('Assigned to'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // The Resolution of the Task.
    $fields['resolution'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Resolution'))
      ->setSetting('allowed_values_function', '\Drupal\task\Entity\Task::resolutionOptionsList')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    // Task Dependencies.
    $fields['dependencies'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Dependencies'))
      ->setSetting('target_type', 'task')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['root'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Root Task'))
      ->setSetting('target_type', 'task')
      ->setCardinality(1)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    if ($this->id()) {
      $pending = $this->dependencies->referencedEntities();
      $visited = [];
      while ($dependency = array_pop($pending)) {
        if ((string) $dependency->id() === (string) $this->id()) {
          throw new \InvalidArgumentException('Task dependencies must not contain a cycle.');
        }
        if (!isset($visited[$dependency->id()])) {
          $visited[$dependency->id()] = TRUE;
          array_push($pending, ...$dependency->dependencies->referencedEntities());
        }
      }
    }
    $current_user = \Drupal::currentUser();

    // Set Creator and Updater.
    if ($this->isNew() && !$this->creator->entity) {
      $this->creator->target_id = $current_user->id();
    }
    $this->updater->target_id = $current_user->id();

    // Set the start date to now if its not already set.
    $now = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, \Drupal::time()->getRequestTime());
    if (!$this->start->value) {
      $this->start->value = $now;
    }

    // Non-terminal status is derived from scheduling and current gates.
    // Postpone work by moving its start date, not by assigning a manual hold.
    if (in_array($this->status->value, [NULL, '', 'pending', 'active', 'waiting'], TRUE)) {
      // Runtime readiness also observes reference changes between task saves.
      $readiness = \Drupal::service('task.readiness')->evaluate($this);
      if ($readiness->state === 'invalid') {
        $this->resolve(static::RESOLUTION_INVALID);
      }
      else {
        $this->status->value = $readiness->state;
      }
    }

    // @todo Lock tokens if this is resolved.
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    if ($update && $this->status->value != $this->original->status->value) {
      $query = $storage->getQuery();
      // This maintains dependencies across assignees, independently of the
      // permissions of the user who resolved the prerequisite.
      $query->accessCheck(FALSE);
      $query->condition('dependencies.entity.id', $this->id());
      if ($ids = $query->execute()) {
        foreach ($storage->loadMultiple($ids) as $dependency) {
          $dependency->save();
        }
      }
    }
  }

  /**
   * Default value callback for author.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * Resolve the task.
   *
   * @param string $resolution
   *   What sort of resolution this is.
   * @param \DateTimeInterface|null $time
   *   An explicit resolution time. Otherwise use the current time on the first
   *   resolution, preserving the existing timestamp when already resolved.
   *
   * @return $this
   */
  public function resolve(string $resolution = Task::RESOLUTION_COMPLETE, ?\DateTimeInterface $time = NULL) {
    if ($time !== NULL) {
      $this->resolved = \DateTimeImmutable::createFromInterface($time)
        ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
        ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    }
    elseif ($this->status->value !== static::STATUS_RESOLVED || $this->resolved->isEmpty()) {
      $this->resolved = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, \Drupal::time()->getCurrentTime());
    }
    $this->status = static::STATUS_RESOLVED;
    $this->resolution = $resolution;

    return $this;
  }

}
