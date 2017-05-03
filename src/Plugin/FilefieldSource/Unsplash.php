<?php

/**
 * @file
 * Contains \Drupal\filefield_sources\Plugin\FilefieldSource\Unsplash.
 */

namespace Drupal\unsplash\Plugin\FilefieldSource;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Field\WidgetInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Site\Settings;
use Drupal\Component\Utility\Unicode;
use Drupal\filefield_sources\Plugin\FilefieldSource\Remote;

/**
 * A FileField source plugin to allow downloading a file from a unsplash server.
 *
 * @FilefieldSource(
 *   id = "unsplash",
 *   name = @Translation("Unsplash URL textfield"),
 *   label = @Translation("Unsplash URL"),
 *   description = @Translation("Download a file from a unsplash server.")
 * )
 */
class Unsplash extends Remote {

  /**
   * {@inheritdoc}
   */
  public static function value(array &$element, &$input, FormStateInterface $form_state) {  
    $unsplash_url = parse_url($input['filefield_unsplash']['url']);
    if(isset($unsplash_url['query'])) {
      parse_str($unsplash_url['query'], $query_array);
      if(isset($query_array['photo'])){
        $input['filefield_unsplash']['url'] = 'https://source.unsplash.com/' .$query_array['photo'];
      } else {
        drupal_set_message(t('Please use an Unsplash url http://unsplash.com?photo=XXXXXXX'), 'error');
        return;
      }
    }

    if (isset($input['filefield_unsplash']['url']) && strlen($input['filefield_unsplash']['url']) > 0 && UrlHelper::isValid($input['filefield_unsplash']['url']) && $input['filefield_unsplash']['url'] != FILEFIELD_SOURCE_UNSPLASH_HINT_TEXT) {
      $field = entity_load('field_config', $element['#entity_type'] . '.' . $element['#bundle'] . '.' . $element['#field_name']);
      $url = $input['filefield_unsplash']['url'];

      // Check that the destination is writable.
      $temporary_directory = 'temporary://';
      if (!file_prepare_directory($temporary_directory, FILE_MODIFY_PERMISSIONS)) {
        \Drupal::logger('filefield_sources')->log(E_NOTICE, 'The directory %directory is not writable, because it does not have the correct permissions set.', array('%directory' => drupal_realpath($temporary_directory)));
        drupal_set_message(t('The file could not be transferred because the temporary directory is not writable.'), 'error');
        return;
      }

      // Check that the destination is writable.
      $directory = $element['#upload_location'];
      $mode = Settings::get('file_chmod_directory', FILE_CHMOD_DIRECTORY);

      // This first chmod check is for other systems such as S3, which don't
      // work with file_prepare_directory().
      if (!drupal_chmod($directory, $mode) && !file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
        \Drupal::logger('filefield_sources')->log(E_NOTICE, 'File %file could not be copied, because the destination directory %destination is not configured correctly.', array('%file' => $url, '%destination' => drupal_realpath($directory)));
        drupal_set_message(t('The specified file %file could not be copied, because the destination directory is not properly configured. This may be caused by a problem with file or directory permissions. More information is available in the system log.', array('%file' => $url)), 'error');
        return;
      }

      // Check the headers to make sure it exists and is within the allowed
      // size.
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HEADER, TRUE);
      curl_setopt($ch, CURLOPT_NOBODY, TRUE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(get_called_class(), 'parseHeader'));
      // Causes a warning if PHP safe mode is on.
      @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
      curl_exec($ch);
      $info = curl_getinfo($ch);
      if ($info['http_code'] != 200) {
        curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
        $file_contents = curl_exec($ch);
        $info = curl_getinfo($ch);
      }
      curl_close($ch);

      if ($info['http_code'] != 200) {
        switch ($info['http_code']) {
          case 403:
            $form_state->setError($element, t('The remote file could not be transferred because access to the file was denied.'));
            break;

          case 404:
            $form_state->setError($element, t('The remote file could not be transferred because it was not found.'));
            break;

          default:
            $form_state->setError($element, t('The remote file could not be transferred due to an HTTP error (@code).', array('@code' => $info['http_code'])));
        }
        return;
      }

      // Update the $url variable to reflect any redirects.
      $url = $info['url'];
      $url_info = parse_url($url);

      // Determine the proper filename by reading the filename given in the
      // Content-Disposition header. If the server fails to send this header,
      // fall back on the basename of the URL.
      //
      // We prefer to use the Content-Disposition header, because we can then
      // use URLs like http://example.com/get_file/23 which would otherwise be
      // rejected because the URL basename lacks an extension.
      $filename = static::filename();
      if (empty($filename)) {
        $filename = rawurldecode(basename($url_info['path']));
      }

      $pathinfo = pathinfo($filename);

      // Create the file extension from the MIME header if all else has failed.
      if (empty($pathinfo['extension']) && $extension = static::mimeExtension()) {
        $filename = $filename . '.' . $extension;
        $pathinfo = pathinfo($filename);
      }

      $filename = filefield_sources_clean_filename($filename, $field->getSetting('file_extensions'));
      $filepath = file_create_filename($filename, $temporary_directory);

      if (empty($pathinfo['extension'])) {
        $form_state->setError($element, t('The remote URL must be a file and have an extension.'));
        return;
      }

      // Check file size based off of header information.
      if (!empty($element['#upload_validators']['file_validate_size'][0])) {
        $max_size = $element['#upload_validators']['file_validate_size'][0];
        $file_size = $info['download_content_length'];
        if ($file_size > $max_size) {
          $form_state->setError($element, t('The remote file is %filesize exceeding the maximum file size of %maxsize.', array('%filesize' => format_size($file_size), '%maxsize' => format_size($max_size))));
          return;
        }
      }

      // Set progress bar information.
      $options = array(
        'key' => $element['#entity_type'] . '_' . $element['#bundle'] . '_' . $element['#field_name'] . '_' . $element['#delta'],
        'filepath' => $filepath,
      );
      static::setTransferOptions($options);

      $transfer_success = FALSE;
      // If we've already downloaded the entire file because the
      // header-retrieval failed, just ave the contents we have.
      if (isset($file_contents)) {
        if ($fp = @fopen($filepath, 'w')) {
          fwrite($fp, $file_contents);
          fclose($fp);
          $transfer_success = TRUE;
        }
      }
      // If we don't have the file contents, download the actual file.
      else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, array(get_called_class(), 'curlWrite'));
        // Causes a warning if PHP safe mode is on.
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        $transfer_success = curl_exec($ch);
        curl_close($ch);
      }
      if ($transfer_success && $file = filefield_sources_save_file($filepath, $element['#upload_validators'], $element['#upload_location'])) {
        if (!in_array($file->id(), $input['fids'])) {
          $input['fids'][] = $file->id();
        }
      }

      // Delete the temporary file.
      @unlink($filepath);
    }
  }

    public static function process(array &$element, FormStateInterface $form_state, array &$complete_form) {
      //die(kpr($complete_form));
    $element['filefield_unsplash'] = array(
      '#weight' => 100.5,
      '#theme' => 'filefield_sources_element',
      '#source_id' => 'unsplash',
       // Required for proper theming.
      '#filefield_source' => TRUE,
      '#filefield_sources_hint_text' => FILEFIELD_SOURCE_UNSPLASH_HINT_TEXT,
    );

    $element['filefield_unsplash']['url'] = array(
      '#type' => 'textfield',
      '#description' => filefield_sources_element_validation_help($element['#upload_validators']),
      '#maxlength' => NULL,
    );

    $class = '\Drupal\file\Element\ManagedFile';
    $ajax_settings = [
      'callback' => [$class, 'uploadAjaxCallback'],
      'options' => [
        'query' => [
          'element_parents' => implode('/', $element['#array_parents']),
        ],
      ],
      'wrapper' => $element['upload_button']['#ajax']['wrapper'],
      'effect' => 'fade',
      'progress' => [
        'type' => 'bar',
        'path' => 'file/unsplash/progress/' . $element['#entity_type'] . '/' . $element['#bundle'] . '/' . $element['#field_name'] . '/' . $element['#delta'],
        'message' => t('Starting transfer...'),
      ],
    ];

    $element['filefield_unsplash']['transfer'] = [
      '#name' => implode('_', $element['#parents']) . '_transfer',
      '#type' => 'submit',
      '#value' => t('Transfer'),
      '#validate' => array(),
      '#submit' => ['filefield_sources_field_submit'],
      '#limit_validation_errors' => [$element['#parents']],
      '#ajax' => $ajax_settings,
    ];

    return $element;
  }
}
