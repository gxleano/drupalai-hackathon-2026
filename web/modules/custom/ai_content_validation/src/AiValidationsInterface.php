<?php

declare(strict_types=1);

namespace Drupal\ai_content_validation;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an ai validations entity type.
 */
interface AiValidationsInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
