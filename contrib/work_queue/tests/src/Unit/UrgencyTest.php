<?php

namespace Drupal\Tests\task_work_queue\Unit;

use Drupal\task_work_queue\Urgency;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the platform weights, dependency propagation and interruption edge.
 *
 * @group task_work_queue
 */
class UrgencyTest extends UnitTestCase {

  /**
   * Produces normalized task inputs with a known clock.
   */
  private function record(array $values = []): array {
    return $values + [
      'start' => 2000000000,
      'due' => NULL,
      'deadline' => NULL,
      'priority' => 'medium',
      'status' => 'active',
      'interrupter' => FALSE,
      'estimate' => 0,
      'dependencies' => [],
    ];
  }

  /**
   * Uses shipped weights and fractional UTC days, capped before multipliers.
   */
  public function testWeightsAndInterruption(): void {
    $settings = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/task_work_queue.settings.yml');
    $urgency = new Urgency($settings, 2000000000);
    $this->assertEquals(12.5, $urgency->base($this->record()));
    $this->assertEquals(87.5, $urgency->base($this->record(['due' => 2000000000 + 3 * 86400])));
    $this->assertEquals(250.0, $urgency->base($this->record(['due' => 2000000000 - 30 * 86400])));
    $this->assertEquals(0.0, $urgency->base($this->record(['start' => 2000000000 + 30 * 86400])));
    $this->assertEquals(600.0, $urgency->base($this->record([
      'due' => 2000000000,
      'priority' => 'high',
      'status' => 'waiting',
      'deadline' => 2000000000 + 86400,
    ])));
    $this->assertFalse($urgency->isInterrupter($this->record()));
    $this->assertTrue($urgency->isInterrupter($this->record(['interrupter' => TRUE])));
    $this->assertTrue($urgency->isInterrupter($this->record(['deadline' => 2000001200, 'estimate' => 300])));
    $this->assertFalse($urgency->isInterrupter($this->record(['deadline' => 2000001201, 'estimate' => 300])));
    $this->assertTrue($urgency->isInterrupter($this->record(['deadline' => 1999999999])));
  }

  /**
   * Shared descendants and cycles contribute once, without recursive loops.
   */
  public function testDependencyGraph(): void {
    $settings = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/task_work_queue.settings.yml');
    $urgency = new Urgency($settings, 2000000000);
    $records = [
      1 => $this->record(['priority' => 'low']),
      2 => $this->record(['priority' => 'low', 'dependencies' => [1]]),
      3 => $this->record(['priority' => 'low', 'dependencies' => [1]]),
      4 => $this->record(['priority' => 'low', 'dependencies' => [2, 3]]),
    ];
    $this->assertSame([1 => 40, 2 => 20], $urgency->scores($records, [1, 2]));
    $records[1]['dependencies'] = [4];
    $this->assertSame([1 => 40, 2 => 40], $urgency->scores($records, [1, 2]));
    $records = [
      1 => $this->record(),
      2 => $this->record(['due' => 2000000000, 'status' => 'waiting', 'dependencies' => [1]]),
    ];
    $this->assertSame([1 => 263], $urgency->scores($records, [1]));
  }

}
