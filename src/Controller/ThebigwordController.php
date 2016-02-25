<?php /**
 * @file
 * Contains \Drupal\tmgmt_thebigword\Controller\ThebigwordController.
 */

namespace Drupal\tmgmt_thebigword\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Route controller class for the tmgmt_thebigword module.
 */
class ThebigwordController extends ControllerBase {

  /**
   * Provides a callback function for Thebigword translator.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function callback(Request $request) {
    \Drupal::logger('tmgmt_thebigword')->warning('Request received %request', ['%request' => $request]);
    $project_id = $request->get('ProjectId');
    $file_id = $request->get('FileId');
    if (isset($project_id) && isset($file_id)) {
      $remotes = RemoteMapping::loadByRemoteIdentifier('ProjectId', $project_id);
      if (empty($remotes)) {
        throw new NotFoundHttpException();
      }
      /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
      foreach ($remotes as $remote) {
        if (!isset($remote->getRemoteData('files')[$file_id])) {
          throw new NotFoundHttpException();
        }
      }

      /** @var ThebigwordTranslator $translator_plugin */
      $translator_plugin = $remote->getJob()->getTranslator()->getPlugin();
      $translator_plugin->setTranslator($remote->getJob()->getTranslator());

      $info = $translator_plugin->request('fileinfo/' . $file_id);
      $translator_plugin->addTranslatedFilesToJob($remote->getJob(), $info['FileState']);
    }
    else {
      \Drupal::logger('tmgmt_thebigword')->warning('Wrong call for submitting translation for project %id', ['%id' => $project_id]);
      throw new NotFoundHttpException();
    }
    return new Response();
  }

}
