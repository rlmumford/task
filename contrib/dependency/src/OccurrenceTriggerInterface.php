<?php

namespace Drupal\task_dependency;

/**
 * An explicit occurrence reported by trusted source code, not an entity save.
 *
 * Implementations return FALSE from matches(). Their source calls
 * recordOccurrence() within its transaction after establishing the event facts.
 */
interface OccurrenceTriggerInterface extends TriggerInterface {}
