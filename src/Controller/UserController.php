<?php

namespace Drupal\user_revision\Controller;

use Drupal\Core\Link;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user\UserInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\user_revision\Access\UserRevisionAccessCheck;

/**
 * Returns responses for User revision routes.
 */
class UserController extends ControllerBase implements ContainerInjectionInterface {

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
   */
  public function __construct(DateFormatter $date_formatter,
                              UserRevisionAccessCheck $accessCheck ) {
    $this->dateFormatter = $date_formatter;
    $this->userRevisionAccessCheck = $accessCheck;
    $this->entityTypeManager();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('access_check.user.revision')
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
    $user_storage = $this->entityTypeManager->getStorage('user');

    $build = array();
    $build['#title'] = $this->t('Revisions for %title', array('%title' => $user->label()));
    $header = array($this->t('Revision'), $this->t('Operations'));

    $rows = array();

    $vids = user_revision_ids($user);

    foreach (array_reverse($vids) as $vid) {
      if ($revision = $user_storage->loadRevision($vid)) {
        $revision_author = $revision->revision_uid->entity;

        $username = [
          '#theme' => 'username',
          '#account' => $revision_author,
        ];

        // Use revision link to link to revisions that are not active.
        $date = $this->dateFormatter->format($revision->revision_timestamp->value, 'short');
        if ($vid == $user->getRevisionId()) {
          $link = $user->toLink($date)->toString();
        }
        else {
          $link = Link::fromTextAndUrl($date, new Url('entity.user.revision', array('user' => $user->id(), 'user_revision' => $vid)))->toString();
        }



        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => $link,
              'username' => \Drupal::service('renderer')->render($username),
              'message' => ['#markup' => $revision->revision_log->value, '#allowed_tags' => Xss::getHtmlTagList()],
            ],
          ],
        ];
        // @todo Simplify once https://www.drupal.org/node/2334319 lands.
        // $this->renderer->addCacheableDependency($column['data'], $username);
        $row[] = $column;

        if ($vid == $user->getRevisionId()) {
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
              'title' => $vid < $user->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
              'url' => Url::fromRoute('user.revision_revert_confirm', ['user' => $user->id(), 'user_revision' => $vid]),
            ];
          }

          if ($this->userRevisionAccessCheck->checkAccess($revision, $account, 'delete')) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('user.revision_delete_confirm', ['user' => $user->id(), 'user_revision' => $vid]),
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

    $build['user_revisions_table'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
      '#attached' => array(
        'library' => array('user_revision/user.admin')
      )
    );

    return $build;
  }

  /**
   * Displays a user revision.
   *
   * @param int $user
   *   The user ID.
   * @param int $user_revision
   *   The user revision ID.
   *
   * @return array
   *   An array suitable for drupal_render().
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function revisionShow($user, $user_revision) {
    $user_history = $this->entityTypeManager->getStorage('user')->loadRevision($user_revision);
    if ($user_history->id() != $user) {
      throw new NotFoundHttpException;
    }
    /* @var $view_builder \Drupal\Core\Entity\EntityViewBuilder */
    $view_builder = $this->entityTypeManager->getViewBuilder($user_history->getEntityTypeId());
    return $view_builder->view($user_history);
  }

  /**
   * Page title callback for a user revision.
   *
   * @param int $user_revision
   *   The user revision ID.
   *
   * @return string
   *   The page title.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function revisionPageTitle($user_revision) {
    $user = $this->entityTypeManager->getStorage('user')->loadRevision($user_revision);
    return $this->t('Revision of %title from %date', array('%title' => $user->label(), '%date' => $this->dateFormatter->format($user->get('revision_timestamp')->value)));
  }

}
