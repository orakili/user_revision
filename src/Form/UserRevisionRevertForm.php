<?php

/**
 * @file
 * Contains \Drupal\user_revision\Form\UserRevisionRevertForm.
 */

namespace Drupal\user_revision\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a form for reverting a user revision.
 */
class UserRevisionRevertForm extends ConfirmFormBase {

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
   * Constructs a new UserRevisionRevertForm.
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
    return new static(
      $container->get('entity.manager')->getStorage('user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'user_revision_revert_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to revert to the revision from %revision-date?', array('%revision-date' => format_date($this->revision->revision_timestamp->value)));
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
    return t('Revert');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return '';
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
    $this->revision->setNewRevision();
    // Make this the new default revision for the user.
    $this->revision->isDefaultRevision(TRUE);

    // The revision timestamp will be updated when the revision is saved. Keep the
    // original one for the confirmation message.
    $original_revision_timestamp = $this->revision->revision_timestamp->value;

    $this->revision->revision_log = t('Copy of the revision from %date.', array('%date' => format_date($original_revision_timestamp)));
    $this->revision->save();

    $this->logger('user_revision')->notice('user: reverted %name revision %revision.', array('%name' => $this->revision->label(), '%revision' => $this->revision->getRevisionId()));
    drupal_set_message(t('User %name has been reverted back to the revision from %revision-date.', array('%name' => $this->revision->label(), '%revision-date' => format_date($original_revision_timestamp))));
    $form_state->setRedirect(
      'entity.user.version_history', array('user' => $this->revision->id())
    );
  }

}
