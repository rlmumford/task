<?php

namespace Drupal\task_job\Plugin\Field\FieldType;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\task_job\JobInterface;
use Drupal\task_job\JobVersionId;
use Drupal\task_job\TypedData\JobReference;

/**
 * One authoritative job identity, including its definition selector.
 *
 * @FieldType(
 *   id = "task_job_reference",
 *   label = @Translation("Job reference"),
 *   description = @Translation("A configuration or content job definition."),
 *   default_formatter = "task_job_reference_label",
 *   no_ui = TRUE
 * )
 */
class JobReferenceItem extends FieldItemBase {

  /**
   * An unsaved editor draft, never used to resolve persisted tasks.
   */
  protected ?JobInterface $preview = NULL;

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['target_type'] = DataDefinition::create('string')->setLabel(t('Job entity type'))->setRequired(TRUE);
    $properties['target_id'] = DataDefinition::create('string')->setLabel(t('Job ID'))->setRequired(TRUE);
    $properties['version'] = DataDefinition::create('string')->setLabel(t('Named version or content revision'));
    $properties['entity'] = DataReferenceDefinition::create('entity')
      ->setLabel(t('Job'))
      ->setComputed(TRUE)->setReadOnly(TRUE)
      ->setTargetDefinition(EntityDataDefinition::create())
      ->setClass(JobReference::class);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'target_type' => ['type' => 'varchar_ascii', 'length' => 64],
        'target_id' => ['type' => 'varchar_ascii', 'length' => 255],
        'version' => ['type' => 'varchar_ascii', 'length' => 128],
      ],
      'indexes' => ['target_id' => ['target_type', 'target_id']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'target_id';
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    $this->preview = NULL;
    if ($values instanceof JobInterface) {
      $this->preview = $values;
      $values = [
        'target_type' => $values->getEntityTypeId(),
        'target_id' => $values->getEntityTypeId() === 'task_job' ? $values->getBaseJobId() : $values->id(),
        'version' => $values->getVersion(),
      ];
    }
    elseif (is_scalar($values)) {
      // Existing scalar job assignments identify exported configuration jobs.
      $values = ['target_type' => 'task_job', 'target_id' => (string) $values];
    }
    if (is_array($values) && isset($values['target_id'])) {
      $values += ['target_type' => 'task_job', 'version' => NULL];
      $values['target_id'] = (string) $values['target_id'];
      if ($values['target_type'] === 'task_job' && $parts = JobVersionId::parse($values['target_id'])) {
        if ($values['version'] !== NULL && (string) $values['version'] !== $parts['version']) {
          throw new \InvalidArgumentException('A job ID and its version selector cannot specify different versions.');
        }
        $values['target_id'] = $parts['job_id'];
        $values['version'] = $parts['version'];
      }
      $values['version'] = isset($values['version']) ? (string) $values['version'] : NULL;
    }
    parent::setValue($values, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($property_name, $notify = TRUE) {
    $this->preview = NULL;
    parent::onChange($property_name, $notify);
  }

  /**
   * Gets an editor draft only while its task remains unsaved.
   */
  public function getPreview(): ?JobInterface {
    return $this->getEntity()->isNew() ? $this->preview : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return !$this->preview && ($this->target_id === NULL || $this->target_id === '')
      && ($this->target_type === NULL || $this->target_type === '')
      && ($this->version === NULL || $this->version === '');
  }

}
