<?php

namespace Drupal\task;

use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\ContextHandlerInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\task\Event\CollectResourcesContextsEvent;
use Drupal\task\Event\CollectResourcesEvent;
use Drupal\task\Event\TaskEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The task resource manager.
 *
 * @package Drupal\task
 */
class TaskResourceManager implements TaskResourceManagerInterface {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The context handler.
   *
   * @var \Drupal\Core\Plugin\Context\ContextHandlerInterface
   */
  protected $contextHandler;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The block manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * TaskResourceManager constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\Plugin\Context\ContextHandlerInterface $context_handler
   *   The context handler service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Block\BlockManagerInterface $block_manager
   *   The block manager service.
   */
  public function __construct(
    EventDispatcherInterface $event_dispatcher,
    ContextHandlerInterface $context_handler,
    AccountInterface $current_user,
    ModuleHandlerInterface $module_handler,
    BlockManagerInterface $block_manager
  ) {
    $this->eventDispatcher = $event_dispatcher;
    $this->contextHandler = $context_handler;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->blockManager = $block_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildTaskResources(TaskInterface $task): array {
    $collect_contexts = new CollectResourcesContextsEvent($task);
    $this->eventDispatcher->dispatch($collect_contexts, TaskEvents::COLLECT_RESOURCES_CONTEXTS);

    $collect_resources = new CollectResourcesEvent($this->blockManager, $task, $collect_contexts->getContexts());
    $this->eventDispatcher->dispatch($collect_resources, TaskEvents::COLLECT_RESOURCES);

    $resources = $collect_resources->getResources();
    $resources->sort();

    $build = [];
    $weight = 0;
    $cacheability = new BubbleableMetadata();
    /** @var \Drupal\Core\Block\BlockPluginInterface $block_plugin */
    foreach ($resources as $key => $block_plugin) {
      try {
        if ($block_plugin instanceof ContextAwarePluginInterface) {
          $this->contextHandler->applyContextMapping($block_plugin, $collect_resources->getContexts());
        }
      }
      catch (MissingValueContextException $exception) {
        continue;
      }

      $access = $block_plugin->access($this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }

      $block_build = [
        '#theme' => 'block',
        '#attributes' => [
          'class' => ['resource', 'resource-' . $key],
        ],
        '#weight' => $weight++,
        '#configuration' => $block_plugin->getConfiguration(),
        '#plugin_id' => $block_plugin->getPluginId(),
        '#base_plugin_id' => $block_plugin->getBaseId(),
        '#derivative_plugin_id' => $block_plugin->getDerivativeId(),
        '#block_plugin' => $block_plugin,
        '#pre_render' => [[$this, 'buildBlock']],
        '#cache' => [
          'keys' => ['task_resource', $task->id(), 'resource', $key],
          // Each block needs cache tags of the page and the block plugin, as
          // only the page is a config entity that will trigger cache tag
          // invalidations in case of block configuration changes.
          'tags' => Cache::mergeTags($task->getCacheTags(), $block_plugin->getCacheTags()),
          'contexts' => $block_plugin->getCacheContexts(),
          'max-age' => $block_plugin->getCacheMaxAge(),
        ],
      ];

      $cacheability->addCacheableDependency($block_plugin);

      $this->moduleHandler->alter(
        ['block_view', 'block_view_' . $block_plugin->getBaseId()],
        $block_build,
        $block_plugin
      );
      $build[$key] = $block_build;
    }

    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Pre render callback for building a block.
   *
   * Renders the content using the provided block plugin, if there is no
   * content, aborts rendering, and makes sure the block won't be rendered.
   *
   * This is copied from PageBlockDisplayVariant.
   *
   * @param array $build
   *   The block build array.
   *
   * @return array
   *   The built block.
   */
  public function buildBlock(array $build) {
    $content = $build['#block_plugin']->build();
    // Remove the block plugin from the render array.
    unset($build['#block_plugin']);
    if ($content !== NULL && !Element::isEmpty($content)) {
      $build['content'] = $content;

      // Add contextual links but prevent duplicating the Views block displays
      // contextual links.
      $add_contextual_links = !empty($content['#contextual_links']) && empty($content['#views_contextual_links']);
      $build['#contextual_links'] = $add_contextual_links ? $content['#contextual_links'] : [];
    }
    else {
      // Abort rendering: render as the empty string and ensure this block is
      // render cached, so we can avoid the work of having to repeatedly
      // determine whether the block is empty. E.g. modifying or adding entities
      // could cause the block to no longer be empty.
      $build = [
        '#markup' => '',
        '#cache' => $build['#cache'],
      ];
    }

    // If $content is not empty, then it contains cacheability metadata, and
    // we must merge it with the existing cacheability metadata. This allows
    // blocks to be empty, yet still bubble cacheability metadata, to indicate
    // why they are empty.
    if (!empty($content)) {
      CacheableMetadata::createFromRenderArray($build)
        ->merge(CacheableMetadata::createFromRenderArray($content))
        ->applyTo($build);
    }

    return $build;
  }

}
