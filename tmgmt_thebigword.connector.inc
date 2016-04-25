<?php
/**
 * @file
 * Connector to TheBigWord Service.
 */

/**
 * TheBigWord connector class.
 */
class TheBigWordConnector {

  private $translator;
  private $serviceUrl;
  private $clientContactKey;

  /**
   * @param string $service_url
   *   TheBigWord service URL.
   * @param $client_contact_key
   *   TheBigWord Client Contact Key.
   * @param $translator
   */
  function __construct($service_url, $client_contact_key, $translator) {
    $this->serviceUrl = $service_url;
    $this->clientContactKey = $client_contact_key;
    $this->translator = $translator;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages() {
    $supported_remote_languages = array();

    if (empty($this->clientContactKey)) {
      return $supported_remote_languages;
    }

    try {
      $supported_languages = $this->request('languages', 'GET', array());

      // Parse languages.
      foreach ($supported_languages as $language) {
        $supported_remote_languages[$language['CultureName']] = $language['CultureName'];
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
    }
    asort($supported_remote_languages);
    return $supported_remote_languages;
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var TMGMTJob $job */
    $job = reset($job_items)->getJob();
    $project_id = 0;
    $required_by = $job->getSetting('required_by');
    $datetime = new DateTime('+' . $required_by . ' weekday', new DateTimeZone('UTC'));
    $datetime = $datetime->format('Y-m-d\TH:i:s');

    try {
      $project_id = $this->newTranslationProject($job, $datetime);
      $job->addMessage('Created a new project in thebigword with the id: @id', array('@id' => $project_id), 'debug');

      /** @var TMGMTJobItem $job_item */
      foreach ($job_items as $job_item) {
        /** @var TMGMTRemote $remote_mapping */
        $remote_mapping = entity_create('tmgmt_remote', array(
          'tjid' => $job->tjid,
          'tjiid' => $job_item->tjiid,
          'remote_identifier_1' => 'tmgmt_thebigword',
          'remote_identifier_2' => $project_id,
          'remote_data' => array(
            'files' => array(),
            'required_by' => $datetime,
          ),
        ));
        $remote_mapping->save();
        $this->sendFiles($job_item);
      }
      // Confirm is required to trigger the translation.
      $confirmed = $this->confirmUpload($project_id, 'ReferenceAdd');
      if ($confirmed != count($job_items)) {
        $message = 'Not all the references had been confirmed.';
        throw new TMGMTException($message);
      }
      $confirmed = $this->confirmUpload($project_id, 'TranslatableSource');
      if ($confirmed != count($job_items)) {
        $message = 'Not all the sources had been confirmed.';
        throw new TMGMTException($message);
      }
    }
    catch (TMGMTException $e) {
      try {
        $this->sendFileError('RestartPoint03', $project_id, '', $job, $e->getMessage(), TRUE);
      }
      catch (TMGMTException $e) {
        watchdog('tmgmt_thebigword', 'Error sending the error file: @error', array('@error' => $e->getMessage()), $severity = WATCHDOG_ERROR);
      }
      $job->rejected('Job has been rejected with following error: @error',
        array('@error' => $e->getMessage()), 'error');
      if (isset($remote_mapping)) {
        $remote_mapping->delete();
      }
    }
    return $job;
  }

  /**
   * Does a request to thebigword services.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   (Optional) HTTP method (GET, POST...). By default uses GET method.
   * @param array $params
   *   (Optional) Form parameters to send to thebigword service.
   * @param bool $code
   *   (Optional) If we want to return the status code of the call. FALSE by
   *   default.
   *
   * @return array
   *   Response array from thebigword.
   *
   * @throws TMGMTException
   */
  public function request($path, $method = 'GET', $params = array(), $code = FALSE) {
    $options = array();

    $url = $this->serviceUrl . '/' . $path;
    
    $options['headers'] = array(
      'TMS-REQUESTER-ID' => $this->clientContactKey,
    );
    if ($method == 'GET') {
      $options['query'] = $params;
    }
    else {
      $options['headers']['Content-Type'] = 'application/json';
      $options['data'] = json_encode($params);
      $options['method'] = $method;
    }

    $response = drupal_http_request($url, $options);
    $received_data = $response->data;

    if ($response->code != 200) {
      watchdog('tmgmt_thebigword', '%method Request to %url:<br>
        <ul>
            <li>Request: %request</li>
            <li>Response: %response</li>
        </ul>
        ', array(
          '%method' => $method,
          '%url' => $url,
          '%request' => json_encode($options),
          '%response' => $received_data,
        ), WATCHDOG_ERROR);
      if ($code) {
        return $response->code;
      }
      throw new TMGMTException('Unable to connect to thebigword service due to following error: @error at @url',
        array('@error' => $response->code, '@url' => $url));
    }

    watchdog('tmgmt_thebigword', '%method Request to %url:<br>
        <ul>
            <li>Request: %request</li>
            <li>Response: %response</li>
        </ul>
        ', array(
      '%method' => $method,
      '%url' => $url,
      '%request' => json_encode($options),
      '%response' => $received_data
    ), WATCHDOG_DEBUG);

    if ($code) {
      return $response->code;
    }

    $received_data = json_decode($received_data, TRUE);

    return $received_data;
  }

  /**
   * Creates new translation project at thebigword.
   *
   * @param TMGMTJob $job
   *   The job.
   * @param string $required_by
   *   The date by when the translation is required.
   *
   * @return int
   *   Thebigword project id.
   *
   * @throws TMGMTException
   */
  public function newTranslationProject(TMGMTJob $job, $required_by) {
    $url = url('tmgmt_thebigword_callback', array('absolute' => FALSE));
    $email = user_load($job->uid)->mail;
    $email = empty($email) ? variable_get('site_mail', ini_get('sendmail_from')) : $email;
    $params = array(
      'PurchaseOrderNumber' => $job->tjid,
      'ProjectReference' => $job->tjid,
      'RequiredByDateUtc' => $required_by,
      'QuoteRequired' => $job->getSetting('quote_required') ? 'true' : 'false',
      'SpecialismId' => $job->getSetting('category'),
      'ProjectMetadata' => array(
        array('MetadataKey' => 'CMS User Name', 'MetadataValue' => user_load($job->uid)->name),
        array('MetadataKey' => 'CMS User Email', 'MetadataValue' => $email),
        array('MetadataKey' => 'Response Service Base URL', 'MetadataValue' => $GLOBALS['base_url']),
        array('MetadataKey' => 'Response Service Path', 'MetadataValue' => $url),
      ),
    );
    if ($job->getSetting('review')) {
      $params['ProjectMetadata'][] = array(
        'MetadataKey' => 'Workflow Options',
        'MetadataValue' => 'Localize and Review',
      );
    }
    else {
      $params['ProjectMetadata'][] = array(
        'MetadataKey' => 'Workflow Options',
        'MetadataValue' => 'Localize Only',
      );
    }

    return $this->request('project', 'POST', $params);
  }

  /**
   * Send the files to thebigword.
   *
   * @param TMGMTJobItem $job_item
   *   The Job.
   */
  public function sendFiles(TMGMTJobItem $job_item) {
    $xliff_converter = tmgmt_file_format_controller('xlf');


    $job_item_id = $job_item->tjiid;
    $target_language = $job_item->getJob()->target_language;
    $target_language = $job_item->getJob()->getTranslator()->mapToRemoteLanguage($target_language);
    $conditions = array('tjiid' => array('value' => $job_item_id));
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_item->tjid}_JobItemID_{$job_item_id}_{$job_item->getJob()->source_language}_{$target_language}";

    /** @var TMGMTRemoteController $remote_mapping_controller */
    $remote_mapping_controller = entity_get_controller('tmgmt_remote');
    $remote_mappings = $remote_mapping_controller->loadByLocalData($job_item->tjid, $job_item_id);
    $remote_mapping = reset($remote_mappings);
    $project_id = $remote_mapping->remote_identifier_2;

    $file_id = $this->uploadFileResource($xliff, $job_item, $project_id, $name);

    $files = $remote_mapping->getRemoteData('files');
    $files[$file_id] = array(
      'FileStateVersion' => 1,
      'FileId' => $file_id,
    );
    $remote_mapping->addRemoteData('files', $files);
    $remote_mapping->save();

    $this->sendUrl($job_item, $project_id, $file_id, FALSE);
  }

  /**
   * Creates a file resource at thebigword.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param TMGMTJobItem $job_item
   *   The Job item.
   * @param string $project_id
   *   The Project ID.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return string
   *   Thebigword uuid of the resource.
   *
   * @throws TMGMTException
   */
  public function uploadFileResource($xliff, TMGMTJobItem $job_item, $project_id, $name) {
    $target_language = $job_item->getJob()->target_language;
    $target_language = $job_item->getJob()->getTranslator()->mapToRemoteLanguage($target_language);
    $source_language = $job_item->getJob()->source_language;
    $source_language = $job_item->getJob()->getTranslator()->mapToRemoteLanguage($source_language);

    $form_params = array(
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => tmgmt_thebigword_get_remote_data($project_id, 'required_by'),
      'SourceLanguage' => $source_language,
      'TargetLanguage' => $target_language,
      'FilePathAndName' => "$name.xliff",
      'FileState' => 'TranslatableSource',
      'FileData' => base64_encode($xliff),
    );
    /** @var int $file_id */
    $file_id = $this->request('file', 'POST', $form_params);

    return $file_id;
  }

  /**
   * Parses received translation from TheBigWord and returns unflatted data.
   *
   * @param string $data
   *   Base64 encode data, received from TheBigWord.
   *
   * @return array
   *   Unflatted data.
   */
  protected function parseTranslationData($data) {
    $xlf_converter = tmgmt_file_format_controller('xlf');
    // Import given data using XLIFF converter. Specify that passed content is
    // not a file.
    return $xlf_converter->import($data, FALSE);
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param TMGMTJob $job
   *   A job containing job items that translations will be fetched for.
   * @param string $state
   *   The state of the files.
   * @param int $project_id
   *   The project ID.
   *
   * @return bool
   *   Returns TRUE if there are error messages during the process of
   *   retrieving translations. Otherwise FALSE.
   */
  public function fetchTranslatedFiles(TMGMTJob $job, $state, $project_id) {
    $translated = 0;
    $had_errors = FALSE;

    try {
      // Get the files of this job.
      $files = $this->requestFileinfo($state, $job);
      /** @var TMGMTJobItem $job_item */
      foreach ($job->getItems() as $job_item) {
        $ids = tmgmt_thebigword_get_remote_data($project_id, 'files');
        $file = reset($ids);
        $file_id = $file['FileId'];
        if (isset($files[$file_id])) {
          try {
            $this->addFileDataToJob($job, $state, $file_id);
          }
          catch (TMGMTException $e) {
            $this->sendFileError('RestartPoint01', $project_id, $file_id, $job_item->getJob(), $e->getMessage());
            $job->addMessage('Error fetching the job item: @job_item.', array('@job_item' => $job_item->label()), 'error');
            $had_errors = TRUE;
            continue;
          }
          $translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      watchdog('tmgmt_thebigword', 'Could not pull translation resources: @error', array('@error' => $e->getMessage()), $severity = WATCHDOG_ERROR);
    }
    if ($had_errors) {
      $this->confirmUpload($project_id, 'RestartPoint01');
    }
    return array(
      'translated' => $translated,
      'untranslated' => count($job->getItems()) - $translated,
    );
  }

  /**
   * Send the preview url.
   *
   * @param TMGMTJobItem $job_item
   *   The Job item.
   * @param int $project_id
   *   The project ID.
   * @param string $file_id
   *   The file ID.
   * @param bool $preview
   *   If true will send the preview URL, otherwise the source URL.
   *
   * @return string
   *   The file ID;
   *
   * @throws TMGMTException
   */
  protected function sendUrl(TMGMTJobItem $job_item, $project_id, $file_id, $preview) {
    $uri = $job_item->getSourceUri();
    $state = 'ReferenceAdd';
    $name = 'source-url';
    if ($preview || !$uri) {
      $uri = url('tmgmt_thebigword_no_preview', array('absolute' => TRUE));
    }
    $preview_data = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE PreviewUrl SYSTEM "http://www.thebigword.com/dtds/PreviewUrl.dtd">
<PreviewUrl>' . $GLOBALS['base_url'] . base_path() . $uri['path'] . '</PreviewUrl>';

    $target_language = $job_item->getJob()->target_language;
    $target_language = $job_item->getJob()->getTranslator()->mapToRemoteLanguage($target_language);
    $source_language = $job_item->getJob()->source_language;
    $source_language = $job_item->getJob()->getTranslator()->mapToRemoteLanguage($source_language);
    $form_params = array(
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => tmgmt_thebigword_get_remote_data($project_id, 'required_by'),
      'SourceLanguage' => $source_language,
      'TargetLanguage' => $target_language,
      'FilePathAndName' => "$name.xml",
      'FileState' => $state,
      'FileData' => base64_encode($preview_data),
      'FileIdToUpdate' => $file_id,
    );
    $file_id = $this->request('file', 'PUT', $form_params);

    return $file_id;
  }

  /**
   * {@inheritdoc}
   */
  public function pullAllRemoteTranslations() {
    $translated = 0;
    $untranslated = 0;
    $result = $this->pullAllRemoteTranslationsForStatus('TranslatableComplete');
    $translated += $result['updated'];
    $untranslated -= $result['non-updated'];
    return array(
      'updated' => $translated,
      'non-updated' => $untranslated,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function pullAllRemoteTranslationsForStatus($status) {
    $translated = 0;
    $not_translated = 0;

    try {
      $files = $this->requestFileinfo($status);
      foreach ($files as $file) {
        $file_id = $file['FileId'];
        $project_id = $file['ProjectId'];
        /** @var TMGMTRemoteController $remote_mapping_controller */
        $remote_mapping_controller = entity_get_controller('tmgmt_remote');
        /** @var TMGMTRemote $remote */
        $remotes = $remote_mapping_controller->loadByRemoteIdentifier('tmgmt_thebigword', $project_id);
        $remote = reset($remotes);
        $ids = $remote->getRemoteData('files');
        if ($remote != NULL && isset($ids[$file_id]) && $file['FileStateVersion'] == $ids[$file_id]['FileStateVersion']) {
          $this->addFileDataToJob($remote->getJob(), $status, $file_id);
          $translated++;
        }
        else {
          $not_translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      watchdog('tmgmt_thebigword', 'Error pulling the translations: @error', array('@error' => $e->getMessage()), $severity = WATCHDOG_ERROR);
    }
    return array(
      'updated' => $translated,
      'non-updated' => $not_translated,
    );
  }

  /**
   * Returns supported languages.
   *
   * @return mixed
   *   The list of supported languages.
   */
  public function getSupportedLanguages() {
    return $this->request('languages', 'GET', array());
  }

  /**
   * Returns the file info of one state.
   *
   * @param string $state
   *   The state.
   * @param TMGMTJob $job
   *   (Optional) A Job.
   *
   * @return array
   *   The file infos.
   */
  protected function requestFileinfo($state, TMGMTJob $job = NULL) {
    $all_files = array();
    $files = $this->request('fileinfos/' . $state);
    foreach ($files as $file) {
      $all_files[$file['FileId']] = $file;
    }
    if ($job) {
      $files = array();
      /** @var TMGMTRemoteController $remote_mapping_controller */
      $remote_mapping_controller = entity_get_controller('tmgmt_remote');
      $mappings = $remote_mapping_controller->loadByLocalData($job->tjid);
      /** @var TMGMTRemote $mapping */
      foreach ($mappings as $mapping) {
        $remote_files = $mapping->getRemoteData('files');
        foreach ($remote_files as $file_id => $file) {
          if (isset($all_files[$file_id]) && $all_files[$file_id]['FileStateVersion'] == $file['FileStateVersion']) {
            $files[$file_id] = $all_files[$file_id];
          }
        }
      }
    }
    else {
      $files = $all_files;
    }
    return $files;
  }

  /**
   * Sends an error file to thebigword.
   *
   * @param string $state
   *   The state.
   * @param int $project_id
   *   The project id.
   * @param string $file_id
   *   The file id to update.
   * @param TMGMTJob $job
   *   The Job.
   * @param string $message
   *   The error message.
   * @param bool $confirm
   *   (Optional) Set to TRUE if also want to send the confirmation message
   *   of this error. Otherwise will not send it.
   *
   * @throws TMGMTException
   *   If there is a problem with the request.
   */
  public function sendFileError($state, $project_id, $file_id, TMGMTJob $job, $message = '', $confirm = FALSE) {
    $date = new DateTime();
    $target_language = $job->target_language;
    $target_language = $job->getTranslator()->mapToRemoteLanguage($target_language);
    $source_language = $job->source_language;
    $source_language = $job->getTranslator()->mapToRemoteLanguage($source_language);

    $form_params = array(
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => tmgmt_thebigword_get_remote_data($project_id, 'required_by'),
      'SourceLanguage' => $source_language,
      'TargetLanguage' => $target_language,
      'FilePathAndName' => 'error-' . $date->format('Y-m-d\TH:i:s') . '.txt',
      'FileIdToUpdate' => $file_id,
      'FileState' => $state,
      'FileData' => base64_encode($message),
    );
    $this->request('file', 'PUT', $form_params);
    if ($confirm) {
      $this->confirmUpload($project_id, $state);
    }
  }

  /**
   * Retrieve the data of a file in a state.
   *
   * @param TMGMTJob $job
   *   The Job to which will be added the data.
   * @param string $state
   *   The state of the file.
   * @param string $file_id
   *   The file ID.
   *
   * @throws TMGMTException
   */
  private function addFileDataToJob(TMGMTJob $job, $state, $file_id) {
    $data = $this->request('file/' . $state . '/' . $file_id);
    $decoded_data = base64_decode($data['FileData']);
    $file_data = $this->parseTranslationData($decoded_data);
    $job->addTranslatedData($file_data, array());
    // Confirm that we download the file.
    $form_params = array(
      'FileId' => $file_id,
      'FileState' => $state,
    );
    $this->request('fileinfo/downloaded', 'POST', $form_params);
  }

  /**
   * Confirm all the files uploaded in a project for a state.
   *
   * @param int $project_id
   *   The project ID.
   * @param string $state
   *   The state.
   *
   * @return array
   *   The number of confirmed files.
   *
   * @throws TMGMTException
   */
  protected function confirmUpload($project_id, $state) {
    $form_params = array(
      'ProjectId' => $project_id,
      'FileState' => $state,
    );
    return $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
  }

}
