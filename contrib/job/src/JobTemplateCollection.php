<?php

namespace Drupal\task_job;

/**
 * Expands frozen collection membership against live job template definitions.
 */
final class JobTemplateCollection {

  /**
   * Inserts each recorded invocation directly after its calling item.
   */
  public static function expand(array $definitions, array $templates, array $invocations, bool $preserve_existing = FALSE): array {
    $parents = array_filter($definitions, static fn(array $definition) => $definition['handler'] === 'add_checklist_template');
    if ($preserve_existing) {
      $parents = array_intersect_key($parents, $invocations);
    }
    $definitions = array_filter($definitions, static fn(array $definition) => !array_intersect_key($definition['derivation']['requirements'] ?? [], $parents));
    $result = [];
    foreach ($definitions as $name => $definition) {
      if (isset($result[$name])) {
        throw new \InvalidArgumentException('Collection expansion collides with an existing item.');
      }
      $result[$name] = $definition;
      if (!isset($invocations[$name])) {
        continue;
      }
      $configuration = $invocations[$name]['configuration'];
      $template = $configuration['template'];
      if (!isset($templates[$template])) {
        throw new \InvalidArgumentException('A recorded collection template is missing.');
      }
      foreach (self::combinations($invocations[$name]['counts']) as $index => $combination) {
        $mapping = $configuration['context_mapping'];
        foreach ($combination as $input => $position) {
          $mapping['template_context:' . $input] = "item:{$name}:members_{$input}.{$position}";
        }
        // The persisted membership position is immutable, unlike a live delta.
        $prefix = $name . ($combination ? '__member_' . $index : '__template') . '__' . $template . '__';
        $children = JobChecklistExpansion::instance($template, $templates, $prefix, $mapping);
        foreach ($children as &$child) {
          $derivation = $child['derivation'];
          $child['derivation']['requirements'] = ($definition['derivation']['requirements'] ?? []) + [
            $name => ['outcome' => 'template', 'value' => $template],
          ] + ($derivation['requirements'] ?? []);
          $child['derivation']['scopes'] = [
            ...($definition['derivation']['scopes'] ?? []),
            ...$derivation['scopes'],
          ];
        }
        unset($child);
        $children = self::expand($children, $templates, $invocations);
        if (array_intersect_key($result, $children) || array_intersect_key($definitions, $children)) {
          throw new \InvalidArgumentException('Collection expansion collides with an existing item.');
        }
        $result += $children;
        if (count($result) > 1000) {
          throw new \InvalidArgumentException('The task checklist exceeds 1,000 definitions.');
        }
      }
    }
    if (count($result) > 1000) {
      throw new \InvalidArgumentException('The task checklist exceeds 1,000 definitions.');
    }
    return $result;
  }

  /**
   * Builds bounded Cartesian coordinates; an empty dimension yields no work.
   */
  public static function combinations(array $counts): array {
    foreach ($counts as $count) {
      if ($count < 0 || $count > 1000) {
        throw new \InvalidArgumentException('A collection cannot exceed 1,000 members.');
      }
    }
    if (in_array(0, $counts, TRUE)) {
      return [];
    }
    if (array_product($counts) > 1000) {
      throw new \InvalidArgumentException('Collection combinations exceed the 1,000-item checklist limit.');
    }
    $combinations = [[]];
    foreach ($counts as $name => $count) {
      $next = [];
      foreach ($combinations as $combination) {
        for ($index = 0; $index < $count; $index++) {
          $next[] = $combination + [$name => $index];
        }
      }
      $combinations = $next;
    }
    return $combinations;
  }

}
