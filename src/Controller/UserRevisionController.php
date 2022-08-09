<?php

namespace Drupal\user_revision\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\user_revision\Access\UserRevisionAccessCheck;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for User revision routes.
 */
class UserRevisionController extends ControllerBase {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The access_check.user.revision service.
   *
   * @var \Drupal\user_revision\Access\UserRevisionAccessCheck
   */
  protected $userRevisionAccessCheck;

  /**
   * Constructs a UserController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\user_revision\Access\UserRevisionAccessCheck $access_check
   *   The access_check.user.revision service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    DateFormatter $date_formatter,
    UserRevisionAccessCheck $access_check,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
  ) {
    $this->dateFormatter = $date_formatter;
    $this->userRevisionAccessCheck = $access_check;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('access_check.user.revision'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Generates an overview table of older revisions of a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   A user object.
   *
   * @return array
   *   An array as expected by drupal_render().
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function revisionOverview(UserInterface $user) {
    $account = $this->currentUser();
    $user_storage = $this->entityTypeManager()->getStorage('user');
    $revision_keys = $user->getEntityType()->getRevisionMetadataKeys();

    $build = [];
    $build['#title'] = $this->t('Revisions for %title', ['%title' => $user->label()]);
    $header = [$this->t('Revision'), $this->t('Operations')];

    $rows = [];

    $revision_ids = user_revision_ids($user);

    foreach (array_reverse($revision_ids) as $revision_id) {
      if ($revision = $user_storage->loadRevision($revision_id)) {
        $revision_author = $revision->get($revision_keys['revision_user'])->entity;

        // Use revision link to link to revisions that are not active.
        $date = $this->dateFormatter->format($revision->get($revision_keys['revision_created'])->value, 'short');
        if ($revision_id == $user->getRevisionId()) {
          $link = $user->toLink($date);
        }
        else {
          $link = Link::fromTextAndUrl($date, Url::fromRoute('entity.user.revision', [
            'user' => $user->id(),
            'revision_id' => $revision_id,
          ]));
        }

        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => $link->toString(),
              'username' => $revision_author->toLink()->toString(),
              'message' => [
                '#markup' => $revision->get($revision_keys['revision_log_message'])->value,
                '#allowed_tags' => Xss::getHtmlTagList(),
              ],
            ],
          ],
        ];
        // @todo Simplify once https://www.drupal.org/node/2334319 lands.
        // $this->renderer->addCacheableDependency($column['data'], $username);
        $row[] = $column;

        if ($revision_id == $user->getRevisionId()) {
          $row[] = [
            'data' => [
              '#prefix' => '<em>',
              '#markup' => $this->t('Current revision'),
              '#suffix' => '</em>',
            ],
          ];

          $rows[] = [
            'data' => $row,
            'class' => ['revision-current'],
          ];
        }
        else {
          $links = [];
          if ($this->userRevisionAccessCheck->checkAccess($revision, $account, 'update')) {
            $links['revert'] = [
              'title' => $revision_id < $user->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
              'url' => Url::fromRoute('user.revision_revert_confirm', [
                'user' => $user->id(),
                'revision_id' => $revision_id,
              ]),
            ];
          }

          if ($this->userRevisionAccessCheck->checkAccess($revision, $account, 'delete')) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('user.revision_delete_confirm', [
                'user' => $user->id(),
                'revision_id' => $revision_id,
              ]),
            ];
          }

          $row[] = [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];

          $rows[] = $row;
        }
      }
    }

    $build['user_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => [
        'library' => ['user_revision/user.admin'],
      ],
    ];

    return $build;
  }

  /**
   * Displays a user revision.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param int $revision_id
   *   The user revision ID.
   *
   * @return array
   *   An renderable array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function revisionShow(UserInterface $user, $revision_id) {
    $revision = $this->entityTypeManager()
      ->getStorage('user')
      ->loadRevision($revision_id);
    if ($revision->id() != $user->id()) {
      throw new NotFoundHttpException();
    }
    /** @var \Drupal\Core\Entity\EntityViewBuilder $view_builder */
    $view_builder = $this->entityTypeManager()
      ->getViewBuilder($revision->getEntityTypeId());
    return $view_builder->view($revision);
  }

  /**
   * Page title callback for a user revision.
   *
   * @param int $revision_id
   *   The user revision ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function revisionPageTitle($revision_id) {
    $revision = $this->entityTypeManager()->getStorage('user')->loadRevision($revision_id);
    $revision_created = $revision->get($revision->getEntityType()->getRevisionMetadataKey('revision_created'))->value;
    return $this->t('Revision of %title from %date', [
      '%title' => $revision->label(),
      '%date' => $this->dateFormatter->format($revision_created),
    ]);
  }

}
