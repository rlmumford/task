<?php

namespace Drupal\task_work_queue\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\task_work_queue\NextTask;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Opens a recommended task on its canonical, access-checked workspace page.
 */
final class NextTaskController extends ControllerBase {

  /**
   * Constructs the next-work entry point.
   */
  public function __construct(private readonly NextTask $selector) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('task_work_queue.selector'));
  }

  /**
   * GET only: recommendations never claim work or perform checklist actions.
   */
  public function next(Request $request): array|LocalRedirectResponse {
    $result = $this->selector->select($request->query->getInt('context') ?: NULL);
    if ($result->task) {
      $response = new LocalRedirectResponse($result->task->toUrl()->toString());
      $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
      $response->headers->set('Cache-Control', 'private, no-store');
      return $response;
    }
    $build = [
      '#cache' => ['max-age' => 0],
      'message' => [
        '#markup' => $result->limited
          ? $this->t('There is too much work to rank in one request. Open My work to review your tasks; no recommendation has been made.')
          : $this->t('No other ready tasks are assigned to you. Scheduled, blocked and completed tasks remain available in the work lists.'),
      ],
      'list' => [
        '#type' => 'link',
        '#title' => $this->t('My work'),
        '#url' => Url::fromRoute('task_ui.tasks', [], ['query' => ['queue' => 'mine']]),
      ],
    ];
    if ($result->context) {
      $build['return'] = [
        '#type' => 'link',
        '#title' => $this->t('Return to previous task'),
        '#url' => $result->context->toUrl(),
      ];
    }
    return $build;
  }

}
