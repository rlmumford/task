<?php

namespace Drupal\task_job_additions_api\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\task\Entity\Task;
use Drupal\task_job_additions\AdditionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Exposes only authored choices and request identities, never execution policy.
 */
class AdditionController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(protected AdditionManager $manager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('task_job_additions.manager'));
  }

  /**
   * Discovers available templates and recorded additions under current access.
   */
  public function discover(Task $task): JsonResponse {
    $data = $this->manager->discover($task);
    $data['templates'] = (object) $data['templates'];
    return $this->response($data);
  }

  /**
   * Adds an instance idempotently, with permissions shared with the HTML form.
   */
  public function add(Task $task, Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);
      if (!is_array($data) || array_diff(array_keys($data), ['request_id', 'template']) || !is_string($data['request_id'] ?? NULL) || !is_string($data['template'] ?? NULL)) {
        throw new \InvalidArgumentException('Supply only request_id and template strings.');
      }
      return $this->response($this->manager->add($task, $data['template'], $data['request_id']));
    }
    catch (\JsonException | \InvalidArgumentException $exception) {
      throw new BadRequestHttpException($exception->getMessage(), $exception);
    }
  }

  /**
   * Returns private, uncached JSON for a user's task workspace.
   */
  protected function response(array $data): JsonResponse {
    return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
  }

}
