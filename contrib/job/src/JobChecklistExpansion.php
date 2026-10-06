<?php

namespace Drupal\task_job;

use Drupal\checklist\ChecklistContextMapping;

/**
 * Expands invoked templates into stable, scoped item definitions.
 *
 * Definitions exist before a choice is made, so their expected outcomes can be
 * configured. Checklist activates work only when its branch requirements match.
 */
final class JobChecklistExpansion {

  /**
   * Expands all reachable branches, rejecting cycles and ambiguous identities.
   */
  public static function expand(array $items, array $templates): array {
    $result = [];
    self::append($result, $items, $templates);
    return $result;
  }

  /**
   * Expands a separately recorded template instance with local outcome aliases.
   */
  public static function instance(string $template, array $templates, string $prefix, array $context_mapping = []): array {
    if (!isset($templates[$template])) {
      return [];
    }
    $items = $templates[$template]['items'] ?? [];
    $aliases = [];
    foreach (array_keys($items) as $name) {
      $aliases[$name] = $prefix . $name;
    }
    $result = [];
    $scope = [
      'aliases' => $aliases,
      'context_mapping' => $context_mapping,
      'context_definitions' => $templates[$template]['context'] ?? [],
    ];
    self::append($result, $items, $templates, $prefix, [], [$scope], [$template]);
    return $result;
  }

  /**
   * Adds one scope and its nested decisions in checklist order.
   */
  private static function append(array &$result, array $items, array $templates, string $prefix = '', array $requirements = [], array $scopes = [], array $ancestors = []): void {
    foreach ($items as $local => $item) {
      $name = $prefix . $local;
      if (strlen($name) > 255 || isset($result[$name]) || count($result) >= 1000) {
        throw new \InvalidArgumentException(sprintf('Invalid or duplicate expanded checklist name "%s".', $name));
      }
      if ($requirements || $scopes) {
        $item['derivation'] = ['requirements' => $requirements, 'scopes' => $scopes];
      }
      $result[$name] = $item;
      $automatic = $item['handler'] === 'add_checklist_template';
      if (!$automatic && $item['handler'] !== 'decision') {
        continue;
      }
      $branches = $automatic ? ['template' => $item['handler_configuration']] : ($item['handler_configuration']['options'] ?? []);
      foreach ($branches as $choice => $option) {
        $template = $option['template'] ?? '';
        if ($template === '' && !$automatic) {
          continue;
        }
        if (!isset($templates[$template]) || in_array($template, $ancestors, TRUE)) {
          throw new \InvalidArgumentException(sprintf('Checklist template "%s" is missing or recursively included.', $template));
        }
        if ($automatic && array_diff_key($option['context_mapping'] ?? [], ChecklistContextMapping::definitions($templates[$template]['context'] ?? []))) {
          throw new \InvalidArgumentException('Template expansion can only map declared template inputs.');
        }
        if (count($ancestors) >= 16) {
          throw new \InvalidArgumentException('Checklist template expansion exceeds the supported size.');
        }
        // Include the choice and template in each identity. Collisions with
        // authored names are rejected instead of reusing unrelated work.
        $child_prefix = $name . '__' . $choice . '__' . $template . '__';
        $children = $templates[$template]['items'] ?? [];
        $aliases = ['__parent' => $name];
        foreach (array_keys($children) as $child) {
          $aliases[$child] = $child_prefix . $child;
        }
        $scope = [
          'aliases' => $aliases,
          'context_mapping' => $option['context_mapping'] ?? [],
          'context_definitions' => $templates[$template]['context'] ?? [],
        ];
        self::append(
          $result, $children, $templates, $child_prefix,
          $requirements + [$name => $automatic ? ['outcome' => 'template', 'value' => $template] : $choice],
          [...$scopes, $scope],
          [...$ancestors, $template],
        );
      }
    }
  }

}
