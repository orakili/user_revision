<?php

namespace Drupal\user_revision\Extension;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Validates module uninstall readiness.
 */
class UserRevisionUninstallValidator implements ModuleUninstallValidatorInterface {
  use StringTranslationTrait;

  /**
   * Constructs a new ContentUninstallValidator.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    if ($module === 'user_revision') {
      // The module cannot be uninstalled currently because Drupal core doesn't
      // support converting back to non-revisionable AND there is always data
      // (admin and anonymous users).
      //
      // @see \Drupal\Core\Entity\Sql\SqlFieldableEntityTypeListenerTrait::onFieldableEntityTypeUpdate().
      // @see https://www.drupal.org/project/drupal/issues/3024727
      return [
        $this->t("This module cannot be uninstalled because Drupal doesn't support converting an entity type from revisionable to non-revisionable"),
      ];
    }
  }

}
