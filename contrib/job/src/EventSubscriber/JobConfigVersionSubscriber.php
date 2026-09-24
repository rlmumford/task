<?php

namespace Drupal\task_job\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\task_job\Entity\Job;
use Drupal\task_job\JobVersionId;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Protects locally edited job versions during config synchronization.
 */
class JobConfigVersionSubscriber implements EventSubscriberInterface {

  /**
   * Active configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * Constructs the subscriber.
   */
  public function __construct(StorageInterface $active_storage) {
    $this->activeStorage = $active_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::STORAGE_TRANSFORM_EXPORT => 'onExport',
      ConfigEvents::STORAGE_TRANSFORM_IMPORT => 'onImportStorageTransform',
      ConfigEvents::IMPORT_VALIDATE => 'onImportValidate',
    ];
  }

  /**
   * Add the current definition as the import baseline to exported jobs.
   */
  public function onExport(StorageTransformEvent $event): void {
    $storage = $event->getStorage();

    foreach ($storage->listAll('task_job.') as $name) {
      $values = $storage->read($name);
      if (!$values || !isset($values['id'])
        || JobVersionId::isDirty($values['id'])
        || !empty($values['dirty'])) {
        continue;
      }

      $values['active_hash'] = Job::definitionHash($values);
      $values['last_imported_hash'] = $values['active_hash'];
      $storage->write($name, $values);
    }
  }

  /**
   * Keep local dirty working copies out of the import delete list.
   */
  public function onImportStorageTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();

    foreach ($this->activeStorage->listAll('task_job.') as $name) {
      $values = $this->activeStorage->read($name);
      if (!$values
        || (!JobVersionId::isDirty($values['id'] ?? '') && empty($values['dirty']))) {
        continue;
      }

      $storage->write($name, $values);
    }
  }

  /**
   * Reject imports that would overwrite a locally changed clean version.
   */
  public function onImportValidate(ConfigImporterEvent $event): void {
    $comparer = $event->getConfigImporter()->getStorageComparer();
    $source = $comparer->getSourceStorage(StorageInterface::DEFAULT_COLLECTION);
    $target = $comparer->getTargetStorage(StorageInterface::DEFAULT_COLLECTION);

    foreach ($event->getChangelist('update') as $name) {
      if (strpos($name, 'task_job.') !== 0) {
        continue;
      }

      $incoming = $source->read($name);
      $current = $target->read($name);
      if (!$incoming || !$current || !empty($current['dirty'])) {
        continue;
      }

      $active_hash = $current['active_hash'] ?? Job::definitionHash($current);
      $baseline_hash = $current['last_imported_hash'] ?? $active_hash;
      if ($active_hash !== $baseline_hash && $incoming !== $current) {
        $event->getConfigImporter()->logError(sprintf(
          'Task job configuration %s has local changes and cannot be overwritten '
          . 'by config import. Create or merge a dirty version first.',
          $name
        ));
      }
    }
  }

}
