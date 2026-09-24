<?php

namespace Drupal\Tests\task_job\Unit;

use Drupal\task_job\JobVersionId;
use Drupal\Tests\UnitTestCase;

/**
 * Tests versioned job IDs.
 */
class JobVersionIdTest extends UnitTestCase {

  /**
   * Tests building and parsing a versioned ID.
   */
  public function testBuildAndParse(): void {
    $id = JobVersionId::build('employment_support', '6');

    $this->assertSame('employment_support--v6', $id);
    $this->assertTrue(JobVersionId::isVersioned($id));
    $this->assertSame('employment_support', JobVersionId::base($id));
    $this->assertSame('6', JobVersionId::version($id));
    $this->assertFalse(JobVersionId::isDirty($id));
    $this->assertNull(JobVersionId::version('employment_support'));
  }

  /**
   * Tests dirty working-copy IDs.
   */
  public function testDirtyVersion(): void {
    $id = JobVersionId::buildDirty('employment_support', '6');

    $this->assertSame('employment_support--v6-dirty', $id);
    $this->assertSame('employment_support', JobVersionId::base($id));
    $this->assertSame('6', JobVersionId::version($id));
    $this->assertTrue(JobVersionId::isDirty($id));
  }

  /**
   * Tests that invalid IDs cannot be created.
   */
  public function testBuildRejectsInvalidParts(): void {
    $this->expectException(\InvalidArgumentException::class);
    JobVersionId::build('Employment Support', '6');
  }

  /**
   * Tests that a version suffix cannot be applied twice.
   */
  public function testBuildRejectsVersionedJobId(): void {
    $this->expectException(\InvalidArgumentException::class);
    JobVersionId::build('employment_support--v5', '6');
  }

  /**
   * Tests that the dirty suffix is reserved.
   */
  public function testBuildRejectsDirtySuffix(): void {
    $this->expectException(\InvalidArgumentException::class);
    JobVersionId::build('employment_support', '6-dirty');
  }

}
