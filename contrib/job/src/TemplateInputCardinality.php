<?php

namespace Drupal\task_job;

use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\typed_data_plus\DataFetcherInterface;

/**
 * Detects collection selectors without loading provider values or entities.
 */
final class TemplateInputCardinality {

  public function __construct(protected DataFetcherInterface $fetcher, protected ContextRepositoryInterface $repository) {}

  /**
   * Returns single-value input names whose mapped source is a typed collection.
   */
  public function collections(array $definitions, array $mapping, array $contexts): array {
    $contexts += $this->repository->getAvailableContexts();
    uksort($contexts, static fn(string $a, string $b) => strlen($b) <=> strlen($a));
    $collections = [];
    foreach ($definitions as $input => $definition) {
      if ($definition->isMultiple()) {
        continue;
      }
      $selector = $mapping[$input] ?? $input;
      foreach ($contexts as $name => $context) {
        if ($selector !== $name && !str_starts_with($selector, $name . '.') && !str_starts_with($selector, $name . '|')) {
          continue;
        }
        $source = $this->fetcher->fetchFilteredDefinition($context->getContextDefinition()->getDataDefinition(), ltrim(substr($selector, strlen($name)), '.'));
        if ($source instanceof DataReferenceDefinitionInterface) {
          $source = $source->getTargetDefinition();
        }
        if ($source instanceof ListDataDefinitionInterface) {
          $collections[] = $input;
        }
        break;
      }
    }
    return $collections;
  }

}
