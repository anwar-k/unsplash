<?php

namespace Drupal\unsplash\Plugin\EntityBrowser\Widget;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\entity_browser\WidgetBase;
use Drupal\entity_browser\WidgetValidationManager;
use Drupal\entity_browser\Element\EntityBrowserPagerElement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use GuzzleHttp\Client;
use Drupal\Core\Link;
use Drupal\media_entity\Entity\Media;
use Drupal\field\FieldInfo;
use Drupal\Component\Utility\UrlHelper;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

/**
 * Provides an Entity Browser widget that uploads new files.
 *
 * @EntityBrowserWidget(
 *   id = "unsplash",
 *   label = @Translation("unsplash"),
 *   description = @Translation("Adds DropzoneJS upload integration."),
 *   auto_select = TRUE
 * )
 */
class UnsplashWidget extends WidgetBase {

  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs widget plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\WidgetValidationManager $validation_manager
   *   The Widget Validation Manager service.
   * @param \Drupal\dropzonejs\DropzoneJsUploadSaveInterface $dropzonejs_upload_save
   *   The upload saving dropzonejs service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager, AccountProxyInterface $current_user, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager, $validation_manager);
    $this->currentUser = $current_user;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation'),
      $container->get('current_user'),
      $container->get('token')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
    'media_bundle' => NULL,
    'upload_location' => 'public://unsplash',
    'max_filesize' => file_upload_max_size() / pow(Bytes::KILOBYTE, 2) . 'M',
    'extensions' => 'jpg jpeg gif png',
    'items_per_page' => 10,
    ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['items_per_page'] = [
    '#type' => 'select',
    '#title' => $this->t('Items per page'),
    '#default_value' => $this->configuration['items_per_page'],
    '#options' => ['10' => 10, '15' => 15, '25' => 25, '50' => 50],
    ];

    $form['media_bundle'] = [
    '#type' => 'select',
    '#title' => $this->t('Media bundle'),
    '#default_value' => $this->configuration['media_bundle'],
    '#required' => TRUE,
    '#options' => [],
    ];

    foreach ($this->entityTypeManager->getStorage('media_bundle')->loadMultiple() as $bundle) {
      /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
      $form['media_bundle']['#options'][$bundle->id()] = $bundle->label();
    }

    if (empty($form['media_bundle']['#options'])) {
      $form['media_bundle']['#disabled'] = TRUE;
      $form['items_per_page']['#disabled'] = TRUE;
      $form['media_bundle']['#description'] = $this->t('You must @create_bundle before using this widget.', [
        '@create_bundle' => Link::createFromRoute($this->t('create an Unsplash media bundle'), 'entity.media_bundle.add_form')->toString(),
        ]);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state) {

    $media = [];

    $result = $form_state->getValue('thumbnails');
    $selected_ids = array_filter($result);
    foreach ($selected_ids as $id) {
      $query = \Drupal::entityQuery('media');
      $query->condition('status', 1);
      $query->condition('bundle', 'unplash');
      $query->condition('field_unsplash_id', $id);
      $entity_id = $query->execute();
      if ($entity_id) {
        $media[] = $entity_id;
      }
      else {
        $url = $form['widget']['thumbnails']['#options'][$id]['raw'];
        $file = system_retrieve_file($url, $destination = NULL, $managed = TRUE, $replace = FILE_EXISTS_RENAME);
        $file->save();
        $media_entity = Media::create([
          'bundle' => 'unsplash',
          'field_unsplash_id' => $id,
          'field_unsplash_image' => array('target_id'=> $file->id() ),
          ]);
        $media_entity->save();
        $media[] = $media_entity;
      }
    }
    return $media;
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $additional_widget_parameters) {
    $form = parent::getForm($original_form, $form_state, $additional_widget_parameters);
    $form['search'] = [
    '#type' => 'fieldset',
    '#title' => $this->t('Search'),
    ];

    $form['search']['keyword'] = [
    '#type' => 'search',
    '#title' => $this->t('Keyword'),
    '#default_value' => $search_params['unsplash_keyword'],
    ];

    $form['search']['submit'] = [
    '#type' => 'submit',
    '#value' => $this->t('Search'),
    '#submit' => [[$this, 'searchSubmit']],
    ];

    if(!empty($form_state->get('unsplash_keyword'))) {

      $parms = [
      'query' => $form_state->get('unsplash_keyword'),
      'page' => EntityBrowserPagerElement::getCurrentPage($form_state),
      'client_id' => \Drupal::service('config.factory')->get('unsplash.settings')->get('appid'),
      'per_page' => $this->configuration['items_per_page'],
      ]; 

      $parms = UrlHelper::buildQuery($parms, $parent = '');

      $client = \Drupal::httpClient();
      $request = $client->get('https://api.unsplash.com/search/photos?'.$parms);
      $response = json_decode($request->getBody());
      if (!empty($response->results)) {
        foreach ($response->results as $media) {
          $options[$media->id]['id'] = $media->id;
          $options[$media->id]['thumb'] = $media->urls->thumb;
          $options[$media->id]['raw'] = $media->urls->raw;
        }

        $form['thumbnails'] = [
        '#type' => 'checkboxes',
        '#options' => $options,
        '#theme' => 'unsplash_search_item',
        '#images' => $options,
        ];

        $form['pager_eb'] = [
        '#type' => 'entity_browser_pager',
        '#total_pages' => $response->total_pages,
        '#weight' => 20,
        ];
      }
      else {
        $form['empty_message'] = [
        '#prefix' => '<div class="empty-message">',
        '#markup' => $this->t('Not assets found for current search criteria.'),
        '#suffix' => '</div>',
        '#weight' => $max_option_weight + 20,
        ];
        $form['actions']['submit']['#access'] = FALSE;
      }
    }
    
    return $form;
  }

  /**
   * Search form submit callback.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   */
  public function searchSubmit(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $form_state->set('unsplash_keyword', $values['keyword']);

    EntityBrowserPagerElement::setCurrentPage($form_state);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getTriggeringElement()['#eb_widget_main_submit'])) {
      try {
        $media = $this->prepareEntities($form, $form_state);
        array_walk($media, function (Media $media_item) {
          $media_item->save();
        });
        $this->selectEntities($media, $form_state);
      }
      catch (\UnexpectedValueException $e) {
        drupal_set_message($this->t('Unsplash integration is not configured correctly. Please contact the site administrator.'), 'error');
      }
    }
  }

}