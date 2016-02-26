<?php

/**
 * @file
 * Contains \Drupal\tmgmt_thebigword\Controller\RemoteCallbackController.
 */

namespace Drupal\tmgmt_thebigword\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\Entity\RemoteMapping;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route controller of the remote callbacks for the tmgmt_thebigword module.
 */
class RemoteCallbackController extends ControllerBase {

  /**
   * Handles the notifications of changes in the files states.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function callback(Request $request) {
    // @todo Remove this when the server start to call it well.
    \Drupal::logger('tmgmt_thebigword')->warning('Request received %request', ['%request' => $request]);
    $project_id = $request->get('ProjectId');
    $file_id = $request->get('FileId');
    if (isset($project_id) && isset($file_id)) {
      // Get mappings between the job items and the file IDs, for the project.
      $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_thebigword', $project_id);
      if (empty($remotes)) {
        \Drupal::logger('tmgmt_thebigword')->warning('Project %id not found', ['%id' => $project_id]);
        return new Response(new FormattableMarkup('Project %id not found', ['%id' => $project_id]), 404);
      }
      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
      foreach ($remotes as $remote) {
        if (!isset($remote->getRemoteData('files')[$file_id])) {
          \Drupal::logger('tmgmt_thebigword')->warning('File %id not found', ['%id' => $file_id]);
          return new Response(new FormattableMarkup('File %id not found', ['%id' => $file_id]), 404);
        }
      }

      /** @var \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator $translator_plugin */
      $translator_plugin = $remote->getJob()->getTranslator()->getPlugin();
      $translator_plugin->setTranslator($remote->getJob()->getTranslator());

      $info = $translator_plugin->request('fileinfo/' . $file_id);
      $translator_plugin->fetchTranslatedFiles($remote->getJob(), $info['FileState'], $project_id);
    }
    else {
      return new Response('Bad request.', 400);
    }
    return new Response();
  }

  /**
   * Returns a no review response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function noReview(Request $request) {
    return new Response('No preview url available for this file.');
  }

}
