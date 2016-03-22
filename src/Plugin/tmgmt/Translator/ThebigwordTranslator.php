<?php

/**
 * @file
 * Contains \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator.
 */

namespace Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\SourcePreviewInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Translator\AvailableResult;

/**
 * Thebigword translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "thebigword",
 *   label = @Translation("thebigword"),
 *   description = @Translation("Thebigword translator service."),
 *   ui = "Drupal\tmgmt_thebigword\ThebigwordTranslatorUi"
 * )
 */
class ThebigwordTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {

  /**
   * The translator.
   *
   * @var TranslatorInterface
   */
  private $translator;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Constructs a ThebigwordTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \GuzzleHttp\ClientInterface $client */
    $client = $container->get('http_client');
    return new static(
      $client,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $supported_remote_languages = [];
    $this->setTranslator($translator);

    if (empty($this->translator->getSetting('client_contact_key'))) {
      return $supported_remote_languages;
    }

    try {
      $supported_languages = $this->request('languages', 'GET', []);

      // Parse languages.
      foreach ($supported_languages as $language) {
        $supported_remote_languages[$language['CultureName']] = $language['DisplayName'];
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
    }
    asort($supported_remote_languages);
    return $supported_remote_languages;
  }

  /**
   * Returns list of expertise options.
   *
   * @param JobInterface $job
   *   Job object.
   *
   * @return array
   *   List of expertise options, keyed by their code.
   */
  public function getCategory(JobInterface $job) {
    return [
      1 => 'Generic / Universal',
      2 => 'Agriculture & Horticulture',
      3 => 'Architecture & Construction',
      4 => 'Arts & Culture',
      5 => 'Automotive & Transport',
      6 => 'Banking & Finance',
      7 => 'Business & Commerce',
      8 => 'Communication & Media',
      9 => 'Compliance',
      10 => 'Computer Hardware & Telecommunications',
      11 => 'Computer Software & Networking',
      12 => 'Electrical Engineering / Electronics',
      13 => 'Energy & Environment',
      14 => 'Food & Drink',
      15 => 'General Healthcare',
      16 => 'Law & Legal',
      17 => 'Manufacturing / Industry',
      18 => 'Marketing, Advertising & Fashion',
      19 => 'Mechanical Engineering / Machinery',
      20 => 'Military & Defence',
      21 => 'Pharmaceutical & Clinical trials',
      22 => 'Science & Chemicals',
      23 => 'Specialist Healthcare (Machinery)',
      24 => 'Specialist Healthcare (Practise)',
      25 => 'Sports, Entertainment & Gaming',
      26 => 'Travel Hospitality & Tourism',
      27 => 'UK Gov (EU based)',
      28 => 'UK Government',
      29 => 'Veterinary Sciences',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if ($translator->getSetting('client_contact_key')) {
      return AvailableResult::yes();
    }
    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $job = $this->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    $this->setTranslator($job->getTranslator());
    $project_id = 0;
    $required_by = $job->getSetting('required_by');
    $datetime = new DrupalDateTime('+' . $required_by . ' weekday', 'UTC');
    $datetime = $datetime->format('Y-m-d\TH:i:s');

    try {
      $project_id = $this->newTranslationProject($job, $datetime);
      $job->addMessage('Created a new project in thebigword with the id: @id', ['@id' => $project_id], 'debug');

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
        $remote_mapping = RemoteMapping::create([
          'tjid' => $job->id(),
          'tjiid' => $job_item->id(),
          'remote_identifier_1' => 'tmgmt_thebigword',
          'remote_identifier_2' => $project_id,
          'remote_data' => [
            'files' => [],
            'required_by' => $datetime,
          ],
        ]);
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

      if ($job->isContinuous()) {
        $job_item->active();
      }
    }
    catch (TMGMTException $e) {
      try {
        $this->sendFileError('RestartPoint03', $project_id, '', $job, $e->getMessage(), TRUE);
      }
      catch (TMGMTException $e) {
        \Drupal::logger('tmgmt_thebigword')->error('Error sending the error file: @error', ['@error' => $e->getMessage()]);
      }
      $job->rejected('Job has been rejected with following error: @error',
        ['@error' => $e->getMessage()], 'error');
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
   * @param bool $download
   *   (Optional) If we expect resource to be downloaded. FALSE by default.
   * @param bool $code
   *   (Optional) If we want to return the status code of the call. FALSE by
   *   default.
   *
   * @return array
   *   Response array from thebigword.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function request($path, $method = 'GET', $params = [], $download = FALSE, $code = FALSE) {
    $options = [];
    if (!$this->translator) {
      throw new TMGMTException('There is no Translator entity. Access to the client contact key is not possible.');
    }

    $url = $this->translator->getSetting('service_url') . '/' . $path;

    try {
      if ($method == 'GET') {
        $options['query'] = $params;
      }
      else {
        $options['json'] = $params;
      }
      $options['headers'] = [
        'TMS-REQUESTER-ID' => $this->translator->getSetting('client_contact_key'),
      ];
      $response = $this->client->request($method, $url, $options);
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        if ($code) {
          return $e->getCode();
        }
        throw new TMGMTException('Unable to connect to thebigword service due to following error: @error', ['@error' => $e->getMessage()], $e->getCode());
      }
      $response = $e->getResponse();
      if ($code) {
        return $response->getStatusCode();
      }
      // @todo Maybe add detailed info to the TMGMTException so we can provide
      // better info to thebigword.
      // debug($response->getBody()->getContents());
      throw new TMGMTException('Unable to connect to thebigword service due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }
    if ($code) {
      return $response->getStatusCode();
    }

    if ($response->getStatusCode() != 200) {
      throw new TMGMTException('Unable to connect to the thebigword service due to following error: @error at @url',
        ['@error' => $response->getStatusCode(), '@url' => $url]);
    }

    // If we are expecting a download, just return received data.
    $received_data = $response->getBody()->getContents();
    if ($download) {
      return $received_data;
    }
    $received_data = json_decode($received_data, TRUE);

    return $received_data;
  }

  /**
   * Creates new translation project at thebigword.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   * @param string $required_by
   *   The date by when the translation is required.
   *
   * @return int
   *   Thebigword project id.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function newTranslationProject(JobInterface $job, $required_by) {
    $url = Url::fromRoute('tmgmt_thebigword.callback');
    $mail = empty($job->getOwner()->getEmail()) ? \Drupal::config('system.site')->get('mail') : $job->getOwner()->getEmail();
    $params = [
      'PurchaseOrderNumber' => $job->id(),
      'ProjectReference' => $job->id(),
      'RequiredByDateUtc' => $required_by,
      'QuoteRequired' => $job->getSetting('quote_required') ? 'true' : 'false',
      'SpecialismId' => $job->getSetting('category'),
      'ProjectMetadata' => [
        ['MetadataKey' => 'CMS User Name', 'MetadataValue' => $job->getOwner()->getDisplayName()],
        ['MetadataKey' => 'CMS User Email', 'MetadataValue' => $mail],
        ['MetadataKey' => 'Response Service Base URL', 'MetadataValue' => \Drupal::request()->getSchemeAndHttpHost()],
        ['MetadataKey' => 'Response Service Path', 'MetadataValue' => $url->toString()],
      ],
    ];
    if ($job->getSetting('review')) {
      $params['ProjectMetadata'][] = [
        'MetadataKey' => 'Workflow Options',
        'MetadataValue' => 'Localize and Review',
      ];
    }
    else {
      $params['ProjectMetadata'][] = [
        'MetadataKey' => 'Workflow Options',
        'MetadataValue' => 'Localize Only',
      ];
    }

    return $this->request('project', 'POST', $params);
  }

  /**
   * Send the files to thebigword.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   */
  private function sendFiles(JobItemInterface $job_item) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

    $job_item_id = $job_item->id();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $conditions = ['tjiid' => ['value' => $job_item_id]];
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_item->getJob()->id()}_JobItemID_{$job_item_id}_{$job_item->getJob()->getSourceLangcode()}_{$target_language}";

    $remote_mappings = RemoteMapping::loadByLocalData($job_item->getJobId(), $job_item->id());
    $remote_mapping = reset($remote_mappings);
    $project_id = $remote_mapping->getRemoteIdentifier2();

    $file_id = $this->uploadFileResource($xliff, $job_item, $project_id, $name);

    $files = $remote_mapping->getRemoteData('files');
    $files[$file_id] = [
      'FileStateVersion' => 1,
      'FileId' => $file_id,
    ];
    $remote_mapping->addRemoteData('files', $files);
    $remote_mapping->save();

    $this->sendUrl($job_item, $project_id, $file_id, FALSE);
  }

  /**
   * Creates a file resource at thebigword.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job item.
   * @param string $project_id
   *   The Project ID.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return string
   *   Thebigword uuid of the resource.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function uploadFileResource($xliff, JobItemInterface $job_item, $project_id, $name) {
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $this->getRemoteData($project_id, 'required_by'),
      'SourceLanguage' => $job_item->getJob()->getRemoteSourceLanguage(),
      'TargetLanguage' => $job_item->getJob()->getRemoteTargetLanguage(),
      'FilePathAndName' => "$name.xliff",
      'FileState' => 'TranslatableSource',
      'FileData' => base64_encode($xliff),
    ];
    /** @var int $file_id */
    $file_id = $this->request('file', 'POST', $form_params);

    return $file_id;
  }

  /**
   * Parses received translation from thebigword and returns unflatted data.
   *
   * @param string $data
   *   Base64 encode data, received from thebigword.
   *
   * @return array
   *   Unflatted data.
   */
  protected function parseTranslationData($data) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');
    // Import given data using XLIFF converter. Specify that passed content is
    // not a file.
    return $xliff_converter->import($data, FALSE);
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
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
  public function fetchTranslatedFiles(JobInterface $job, $state, $project_id) {
    $this->setTranslator($job->getTranslator());
    $translated = 0;
    $had_errors = FALSE;

    try {
      // Get the files of this job.
      $files = $this->requestFileinfo($state, $job);
      /** @var JobItemInterface $job_item */
      foreach ($job->getItems() as $job_item) {
        $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
        $mapping = reset($mappings);
        $ids = $mapping->getRemoteData('files');
        $file_id = reset($ids)['FileId'];
        if (isset($files[$file_id])) {
          try {
            $this->addFileDataToJob($job, $state, $project_id, $file_id);
          }
          catch (TMGMTException $e) {
            $this->sendFileError('RestartPoint01', $project_id, $file_id, $job_item->getJob(), $e->getMessage());
            $job->addMessage('Error fetching the job item: @job_item.', ['@job_item' => $job_item->label()], 'error');
            $had_errors = TRUE;
            continue;
          }
          $translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_thebigword')->error('Could not pull translation resources: @error', ['@error' => $e->getMessage()]);
    }
    if ($had_errors) {
      $this->confirmUpload($project_id, 'RestartPoint01');
    }
    return [
      'translated' => $translated,
      'untranslated' => count($job->getItems()) - $translated,
    ];
  }

  /**
   * Send the preview url.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
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
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function sendUrl(JobItemInterface $job_item, $project_id, $file_id, $preview) {
    /** @var Url $url */
    $url = $job_item->getSourceUrl();
    $state = 'ReferenceAdd';
    $name = 'source-url';
    if ($preview) {
      $source_plugin = $job_item->getSourcePlugin();
      if ($source_plugin instanceof SourcePreviewInterface) {
        $url = $source_plugin->getPreviewUrl($job_item);
      }
      $state = 'ResourcePreviewUrl';
      $name = 'preview-url';
    }
    if (!$url) {
      $url = Url::fromRoute('tmgmt_thebigword.no_preview');
    }
    $preview_data = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE PreviewUrl SYSTEM "http://www.thebigword.com/dtds/PreviewUrl.dtd">
<PreviewUrl>' . $url->setAbsolute()->toString() . '</PreviewUrl>';

    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $this->getRemoteData($project_id, 'required_by'),
      'SourceLanguage' => $job_item->getJob()->getRemoteSourceLanguage(),
      'TargetLanguage' => $job_item->getJob()->getRemoteTargetLanguage(),
      'FilePathAndName' => "$name.xml",
      'FileState' => $state,
      'FileData' => base64_encode($preview_data),
      'FileIdToUpdate' => $file_id,
    ];
    $file_id = $this->request('file', 'PUT', $form_params);

    return $file_id;
  }

  /**
   * {@inheritdoc}
   */
  public function pullAllRemoteTranslations(Translator $translator) {
    $translated = 0;
    $untranslated = 0;
    $result = $this->pullAllRemoteTranslationsForStatus($translator, 'TranslatableReviewPreview');
    $translated += $result['updated'];
    $untranslated += $result['non-updated'];
    $result = $this->pullAllRemoteTranslationsForStatus($translator, 'TranslatableComplete');
    $translated += $result['updated'];
    $untranslated -= $result['updated'];
    return [
      'updated' => $translated,
      'non-updated' => $untranslated,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function pullAllRemoteTranslationsForStatus(Translator $translator, $status) {
    $this->setTranslator($translator);
    $translated = 0;
    $not_translated = 0;

    try {
      $files = $this->requestFileinfo($status);
      foreach ($files as $file) {
        $file_id = $file['FileId'];
        $project_id = $file['ProjectId'];
        // @todo Optimize for load multiple in one query.
        /** @var RemoteMapping $remote */
        $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_thebigword', $project_id);
        $remote = reset($remotes);
        if ($remote != NULL && isset($remote->getRemoteData('files')[$file_id]) && $file['FileStateVersion'] == $remote->getRemoteData('files')[$file_id]['FileStateVersion']) {
          $this->addFileDataToJob($remote->getJob(), $status, $project_id, $file_id);
          $translated++;
        }
        else {
          $not_translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      \Drupal::logger('tmgmt_thebigword')->error('Error pulling the translations: @error', ['@error' => $e->getMessage()]);
    }
    finally {
      return [
        'updated' => $translated,
        'non-updated' => $not_translated,
      ];
    }
  }

  /**
   * Returns the file info of one state.
   *
   * @param string $state
   *   The state.
   * @param \Drupal\tmgmt\JobInterface $job
   *   (Optional) A Job.
   *
   * @return array
   *   The file infos.
   */
  protected function requestFileinfo($state, JobInterface $job = NULL) {
    $all_files = [];
    $files = $this->request('fileinfos/' . $state);
    foreach ($files as $file) {
      $all_files[$file['FileId']] = $file;
    }
    if ($job) {
      $files = [];
      $mappings = RemoteMapping::loadByLocalData($job->id());
      /** @var RemoteMapping $mapping */
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
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job.
   * @param string $message
   *   The error message.
   * @param bool $confirm
   *   (Optional) Set to TRUE if also want to send the confirmation message
   *   of this error. Otherwise will not send it.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If there is a problem with the request.
   */
  public function sendFileError($state, $project_id, $file_id, JobInterface $job, $message = '', $confirm = FALSE) {
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $this->getRemoteData($project_id, 'required_by'),
      'SourceLanguage' => $job->getRemoteSourceLanguage(),
      'TargetLanguage' => $job->getRemoteTargetLanguage(),
      'FilePathAndName' => 'error-' . (new DrupalDateTime())->format('Y-m-d\TH:i:s') . '.txt',
      'FileIdToUpdate' => $file_id,
      'FileState' => $state,
      'FileData' => base64_encode($message),
    ];
    $this->request('file', 'PUT', $form_params);
    if ($confirm) {
      $this->confirmUpload($project_id, $state);
    }
  }

  /**
   * Retrieve the data of a file in a state.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job to which will be added the data.
   * @param string $state
   *   The state of the file.
   * @param int $project_id
   *   The project ID.
   * @param string $file_id
   *   The file ID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  private function addFileDataToJob(JobInterface $job, $state, $project_id, $file_id) {
    $data = $this->request('file/' . $state . '/' . $file_id);
    $decoded_data = base64_decode($data['FileData']);
    $file_data = $this->parseTranslationData($decoded_data);
    if ($state == 'TranslatableComplete') {
      $status = TMGMT_DATA_ITEM_STATE_TRANSLATED;
    }
    else {
      $status = TMGMT_DATA_ITEM_STATE_PRELIMINARY;
    }
    $job->addTranslatedData($file_data, [], $status);
    // Confirm that we download the file.
    $form_params = [
      'FileId' => $file_id,
      'FileState' => $state,
    ];
    $this->request('fileinfo/downloaded', 'POST', $form_params);

    // If this is a preliminary translation we must send the preview url.
    if ($status == TMGMT_DATA_ITEM_STATE_PRELIMINARY && $job->getSetting('review')) {
      foreach (array_keys($file_data) as $job_item_id) {
        /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
        $job_item = JobItem::load($job_item_id);
        $this->sendUrl($job_item, $project_id, $file_id, TRUE);
      }
    }
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
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function confirmUpload($project_id, $state) {
    $form_params = [
      'ProjectId' => $project_id,
      'FileState' => $state,
    ];
    return $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
  }

  /**
   * Return the data with the data key of the mapping with the given Project ID.
   *
   * @param int $project_id
   *   The project ID.
   * @param string $data_key
   *   The key of the data you want to retrieve from the mapping.
   *
   * @return mixed
   *   The data stored in the mapping for that key.
   */
  protected function getRemoteData($project_id, $data_key) {
    $mappings = RemoteMapping::loadByRemoteIdentifier('tmgmt_thebigword', $project_id);
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = reset($mappings);
    return $mapping->getRemoteData($data_key);
  }

}
