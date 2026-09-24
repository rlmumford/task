<?php

namespace Drupal\task_job;

/**
 * Encodes and decodes versioned task job configuration IDs.
 *
 * The double hyphen is valid in Drupal machine names and is visually distinct
 * from the single hyphens commonly used within a machine name. The `v`
 * marker makes an ID such as `review--v2` unambiguous to people reading
 * exported configuration.
 */
final class JobVersionId {

  /**
   * The separator used before a version suffix.
   */
  public const SEPARATOR = '--v';

  /**
   * Build a config entity ID for a job version.
   *
   * @param string $job_id
   *   The unversioned job ID.
   * @param string $version
   *   The version identifier.
   * @param bool $dirty
   *   Whether to create a working-copy ID.
   *
   * @return string
   *   The versioned config entity ID.
   */
  public static function build(string $job_id, string $version, bool $dirty = FALSE): string {
    self::assertMachinePart($job_id, 'job ID');
    self::assertMachinePart($version, 'version');

    if (self::isVersioned($job_id)) {
      throw new \InvalidArgumentException('The job ID must not already contain a version suffix.');
    }

    if (str_ends_with($version, '-dirty')) {
      throw new \InvalidArgumentException('The version must not include the reserved dirty suffix.');
    }

    return $job_id . self::SEPARATOR . $version . ($dirty ? '-dirty' : '');
  }

  /**
   * Build the working-copy ID for a named job version.
   */
  public static function buildDirty(string $job_id, string $version): string {
    return self::build($job_id, $version, TRUE);
  }

  /**
   * Determine whether an ID contains a version suffix.
   */
  public static function isVersioned(string $id): bool {
    return self::parse($id) !== NULL;
  }

  /**
   * Get the unversioned job ID from an ID.
   */
  public static function base(string $id): string {
    $parts = self::parse($id);
    return $parts['job_id'] ?? $id;
  }

  /**
   * Get the version from an ID, if present.
   */
  public static function version(string $id): ?string {
    $parts = self::parse($id);
    return $parts['version'] ?? NULL;
  }

  /**
   * Determine whether an ID is a dirty working copy.
   */
  public static function isDirty(string $id): bool {
    $parts = self::parse($id);
    return $parts['dirty'] ?? FALSE;
  }

  /**
   * Parse a versioned ID.
   *
   * @return array{job_id: string, version: string, dirty: bool}|null
   *   The ID parts, or NULL for an unversioned ID.
   */
  public static function parse(string $id): ?array {
    if (preg_match('/^(.+)--v([a-z0-9][a-z0-9_-]*?)(-dirty)?$/', $id, $matches)) {
      return [
        'job_id' => $matches[1],
        'version' => $matches[2],
        'dirty' => !empty($matches[3]),
      ];
    }

    return NULL;
  }

  /**
   * Validate a machine-name component.
   */
  private static function assertMachinePart(string $value, string $name): void {
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $value)) {
      throw new \InvalidArgumentException(sprintf(
        'The %s "%s" is not a valid machine-name component.',
        $name,
        $value
      ));
    }
  }

}
