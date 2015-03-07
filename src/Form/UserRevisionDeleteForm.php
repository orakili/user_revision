<?php

/**
 * @file
 * Contains \Drupal\user_revision\Form\UserRevisionDeleteForm.
 */

namespace Drupal\user_revision\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a form for delete a user revision.
 */
class UserRevisionDeleteForm extends ConfirmFormBase {

  /**
   * The user revision.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $revision;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs a new UserRevisionDeleteForm.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $user_storage
   *   The user storage.
   */
  public function __construct(EntityStorageInterface $user_storage) {
    $this->userStorage = $user_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $entity_manager = $container->get('entity.manager');
    return new static(
      $entity_manager->getStorage('user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'user_revision_delete_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to delete the revision from %revision-date?', array('%revision-date' => format_date($this->revision->revision_timestamp->value)));
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.user.version_history', array('user' => $this->revision->id()));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL, $user_revision = NULL) {
    $this->revision = $this->userStorage->loadRevision($user_revision);
    if ($this->revision->id() != $user) {
      throw new NotFoundHttpException;
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->userStorage->deleteRevision($this->revision->getRevisionId());

    $this->logger('user_revision')->notice('user: deleted %name revision %revision.', array('%name' => $this->revision->label(), '%revision' => $this->revision->getRevisionId()));
    drupal_set_message(t('Revision from %revision-date of user %name has been deleted.', array('%revision-date' => format_date($this->revision->revision_timestamp->value), '%name' => $this->revision->label())));
    $form_state->setRedirect(
      'entity.user.canonical', array('user' => $this->revision->id())
    );

    if (user_revision_count($this->revision) > 1) {
      $form_state->setRedirect(
        'entity.user.version_history', array('user' => $this->revision->id())
      );
    }
  }

}
