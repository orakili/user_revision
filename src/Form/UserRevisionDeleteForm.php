<?php

namespace Drupal\user_revision\Form;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new UserRevisionDeleteForm.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    DateFormatter $date_formatter,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('entity_type.manager')
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
    $revision_created_field = $this->revision
      ->getEntityType()
      ->getRevisionMetadataKey('revision_created');

    return $this->t('Are you sure you want to delete the revision from %revision-date?', [
      '%revision-date' => $this->dateFormatter->format($this->revision->get($revision_created_field)->value),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('entity.user.revisions', [
      'user' => $this->revision->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL, $revision_id = NULL) {
    $this->revision = $this->entityTypeManager
      ->getStorage('user')
      ->loadRevision($revision_id);

    if ($this->revision->id() != $user->id()) {
      throw new NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $revision_created_field = $this->revision
      ->getEntityType()
      ->getRevisionMetadataKey('revision_created');

    $this->entityTypeManager
      ->getStorage('user')
      ->deleteRevision($this->revision->getRevisionId());

    $this->logger('user_revision')->notice('user: deleted %name revision %revision.', [
      '%name' => $this->revision->label(),
      '%revision' => $this->revision->getRevisionId(),
    ]);
    $this->messenger()->addStatus($this->t('Revision from %revision-date of user %name has been deleted.', [
      '%revision-date' => $this->dateFormatter->format($this->revision->get($revision_created_field)->value),
      '%name' => $this->revision->label(),
    ]));

    if (user_revision_count($this->revision) > 1) {
      $form_state->setRedirect('entity.user.revisions', [
        'user' => $this->revision->id(),
      ]);
    }
    else {
      $form_state->setRedirect('entity.user.canonical', [
        'user' => $this->revision->id(),
      ]);
    }
  }

}
