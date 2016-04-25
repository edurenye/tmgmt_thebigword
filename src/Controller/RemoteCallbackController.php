<?php

namespace Drupal\tmgmt_thebigword\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator;
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
    $config = \Drupal::configFactory()->get('tmgmt_thebigword.settings');
    if ($config->get('debug')) {
      \Drupal::logger('tmgmt_thebigword')->debug('Request received %request', ['%request' => $request]);
    }
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

      $info = $translator_plugin->request('file/cmsstate/' . $file_id);
      $job = $remote->getJob();
      $job_item = $remote->getJobItem();
      try {
        $translator_plugin->addFileDataToJob($remote->getJob(), $info['CmsState'], $project_id, $file_id);
      }
      catch (TMGMTException $e) {
        $translator_plugin->sendFileError('RestartPoint01', $project_id, $file_id, $job_item->getJob(), $e->getMessage());
        $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
      }
    }
    else {
      return new Response('Bad request.', 400);
    }
    return new Response();
  }

  /**
   * Returns a no preview response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function noPreview(Request $request) {
    return new Response('No preview url available for this file.');
  }

  /**
   * Pull all remote translations.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function pullAllRemoteTrenslations(Request $request) {
    $translators = Translator::loadMultiple();
    $updated = 0;
    $non_updated = 0;

    /** @var \Drupal\tmgmt\Entity\Translator $translator */
    foreach ($translators as $translator) {
      $translator_plugin = $translator->getPlugin();
      if ($translator_plugin instanceof ThebigwordTranslator) {
        try {
          $result = $translator_plugin->pullAllRemoteTranslations($translator);
          $updated += $result['updated'];
          $non_updated += $result['non-updated'];
        }
        catch (TMGMTException $e) {
          drupal_set_message(new TranslatableMarkup('Could not pull translation resources due to the following error: @message',
            ['@message' => $e->getMessage()]), 'error');
        }
      }
    }
    if ($non_updated == 0 && $updated != 0) {
      drupal_set_message(new TranslatableMarkup('Fetched @updated translation updates.', ['@updated' => $updated]));
    }
    elseif ($updated == 0) {
      drupal_set_message(new TranslatableMarkup('Nothing has been updated.'));
    }
    else {
      drupal_set_message(new TranslatableMarkup('Fetched @updated translation updates, @non-updated where not fetched.', [
        '@updated' => $updated,
        '@non-updated' => $non_updated,
      ]));
    }
    return $this->redirect('view.tmgmt_translation_all_job_items.page_1');
  }

}
