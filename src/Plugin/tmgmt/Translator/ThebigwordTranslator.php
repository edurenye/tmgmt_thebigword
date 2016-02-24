<?php

/**
 * @file
 * Contains \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator.
 */

namespace Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\RemoteTranslatorInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\Exception\BadResponseException;
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
 *   label = @Translation("Thebigword translator"),
 *   description = @Translation("Thebigword translator service."),
 *   ui = "Drupal\tmgmt_thebigword\ThebigwordTranslatorUi"
 * )
 */
class ThebigwordTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {//}, RemoteTranslatorInterface {

  /**
   * Translation service URL.
   */
  const PRODUCTION_URL = 'http://uat-integration.thebigword.com/drupal/api';

  /**
   * Translation service API version.
   *
   * @var string
   */
  const API_VERSION = '1';

  /**
   * The translator.
   *
   * @var TranslatorInterface
   */
  private $translator;

  /**
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    if (!isset($this->translator)) {
      $this->translator = $translator;
    }
  }

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * List of supported languages by Thebigword.
   *
   * @var array
   */
  protected $supportedRemoteLanguages = array();

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
    return new static(
      $container->get('http_client'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $this->setTranslator($job->getTranslator());

    try {
      $job_id = $job->id();
      $project_id = $this->newTranslationProject($job_id, $job_id, $job->getSetting('required_by'), $job->getSetting('quote_required'), $job->getSetting('category'), $job->getSetting('review'));

      foreach ($job->getItems() as $job_item) {
        /** @var RemoteMapping $remote_mapping */
        $remote_mapping = \Drupal::entityTypeManager()
          ->getStorage('tmgmt_remote')
          ->create([
            'tjid' => $job->id(),
            'tjiid' => $job_item->id(),
            'data_item_key' => 'tmgmt_thebigword',
            'remote_identifier_1' => 'ProjectId',
            'remote_identifier_2' => $project_id,
            'files' => [],
          ]);
        $remote_mapping->save();
        $this->sendFiles($job_item);
      }
      // Confirm is required to trigger the translation.
      $form_params = [
        'ProjectId' => $project_id,
        'FileState' => 'ReferenceAdd',
      ];
      $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
      if ($confirmed != count($job->getItems())) {
        $message = 'Not all the references had been confirmed.';
        throw new TMGMTException($message);
      }
      $form_params = [
        'ProjectId' => $project_id,
        'FileState' => 'TranslatableSource',
      ];
      $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
      if ($confirmed != count($job->getItems())) {
        $message = 'Not all the sources had been confirmed.';
        throw new TMGMTException($message);
      }

      $job->submitted('Job has been successfully submitted for translation.');
    }
    catch (TMGMTException $e) {
      $this->sendFileError('RestartPoint01', '', $e->getMessage(), $job, NULL, TRUE);
      \Drupal::logger('tmgmt_thebigword')->error('Job has been rejected with following error: @error',
        array('@error' => $e->getMessage()));
      $job->rejected('Job has been rejected with following error: @error',
        array('@error' => $e->getMessage()), 'error');
    }
  }

  /**
   * Does a request to Thebigword services.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   HTTP method (GET, POST...).
   * @param array $params
   *   Form parameters to send to Thebigword service.
   * @param bool $download
   *   If we expect resource to be downloaded.
   *
   * @return array
   *   Response array from Thebigword.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function request($path, $method = 'GET', $params = array(), $download = FALSE) {
    $options = array();
    if (!$this->translator) {
      throw new TMGMTException('There is no Translation entity. Access to public/secret keys is not possible.');
    }

    if (\Drupal::config('tmgmt_thebigword.settings')->get('use_mock_service')) {
      $url = $GLOBALS['base_url'] . '/tmgmt_thebigword_mock' . '/v' . self::API_VERSION . '/' . $path;
    }
    else {
      $url = self::PRODUCTION_URL . '/v' . self::API_VERSION . '/' . $path;
    }

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
    catch (BadResponseException $e) {
      $response = $e->getResponse();
      // debug($response->getBody()->getContents());
      throw new TMGMTException('Unable to connect to Thebigword service due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }

    if ($response->getStatusCode() != 200) {
      throw new TMGMTException('Unable to connect to the Thebigword service due to following error: @error at @url',
        array('@error' => $response->getStatusCode(), '@url' => $url));
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
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    if (!empty($this->supportedRemoteLanguages)) {
      return $this->supportedRemoteLanguages;
    }

    try {
      $this->setTranslator($translator);
      $supported_languages = $this->request('languages', 'GET', array());

      // Parse languages.
      foreach ($supported_languages as $language) {
        $this->supportedRemoteLanguages[$language['CultureName']] = $language['DisplayName'];
      }

      // In case of failed request or parsing, we are returning a list of
      // supported remote languages from default Thebigword mapping.
      if (empty($this->supportedRemoteLanguages)) {
        return [];
      }
    }
    catch (\Exception $e) {
      if (\Drupal::routeMatch()->getRouteName() == 'entity.tmgmt_translator.edit_form'
        || \Drupal::routeMatch()->getRouteName() == 'entity.tmgmt_translator.add_form') {
        return [];
      }
      drupal_set_message($e->getMessage(), 'error');
      return [];
    }
    return $this->supportedRemoteLanguages;
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
      ':configured' => $translator->url(),
    ]));
  }

  /**
   * Creates new translation project at Thebigword.
   *
   * @param int $purchase_order_number
   *   Translation job item id that is equals to the purchase order number.
   * @param int $project_reference
   *   Translation job item id that is equals to the project reference.
   * @param string $required_by
   *   Required date.
   * @param string $quote_required
   *   Is the quote required?
   * @param string $category
   *   The category of the translation.
   * @param bool $review
   *   True is Localize and review, False just Localize.
   *
   * @return array
   *   Thebigword project data.
   */
  public function newTranslationProject($purchase_order_number, $project_reference, $required_by, $quote_required, $category, $review = TRUE) {
    /** @var \DateTime $required_by */
    $required_by->setTimezone(new \DateTimeZone('UTC'));
    $datetime = $required_by->format('Y-m-d\TH:i:s');
    $url = new Url('tmgmt_thebigword.callback');
    $params = [
      'PurchaseOrderNumber' => $purchase_order_number,
      'ProjectReference' => $project_reference,
      'RequiredByDateUtc' => $datetime,
      'QuoteRequired' => $quote_required ? 'true' : 'false',
      'SpecialismId' => $category,
      'ProjectMetadata' => [
        ['MetadataKey' => 'CMS User Name', 'MetadataValue' => \Drupal::currentUser()->getDisplayName()],
        ['MetadataKey' => 'CMS User Email', 'MetadataValue' => \Drupal::currentUser()->getEmail()],
        ['MetadataKey' => 'Response Service Base URL', 'MetadataValue' => $_SERVER['HTTP_HOST']],
        ['MetadataKey' => 'Response Service Path', 'MetadataValue' => '/' . $url->getInternalPath()],
      ],
    ];
    if ($review) {
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
   * Send the files to Thebigword.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   */
  private function sendFiles(JobItemInterface $job_item) {
    $cache = \Drupal::cache('data');
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');
    $resource_uuids = array();

    $job_item_id = $job_item->id();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $cid = "tmgmt_thebigword:resource_id:$job_item_id:$target_language";
    if ($cached = $cache->get($cid)) {
      $file_id = $cached->data;
    }
    else {
      $conditions = array('tjiid' => array('value' => $job_item_id));
      $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
      $name = "JobID_{$job_item->getJob()->id()}_JobItemID_{$job_item_id}_{$job_item->getJob()->getSourceLangcode()}_{$target_language}";

      $file_id = $this->uploadFileResource($xliff, $job_item->getJob(), $job_item_id, $name);
      $this->sendPreviewUrl($job_item, $file_id, FALSE);
      $cache->set($cid, $file_id, Cache::PERMANENT, $job_item->getCacheTags());
    }
    $resource_uuids[$job_item_id] = $file_id;
  }

  /**
   * Creates a file resource at Thebigword.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param \Drupal\tmgmt\JobInterface $job
   *   The Job.
   * @param int $tjiid
   *   The JobItem id.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return string Thebigword uuid of the resource.
   *   Thebigword uuid of the resource.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function uploadFileResource($xliff, JobInterface $job, $tjiid, $name) {
    $remote_mappings = RemoteMapping::loadByLocalData($job->id(), $tjiid, 'tmgmt_thebigword');
    $remote_mapping = reset($remote_mappings);
    $project_id = $remote_mapping->getRemoteIdentifier2();

    /** @var \DateTime $required_by */
    $required_by = $job->getSetting('required_by');
    $required_by->setTimezone(new \DateTimeZone('UTC'));
    $datetime = $required_by->format('Y-m-d\TH:i:s');
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $datetime,
      'SourceLanguage' => $job->getRemoteSourceLanguage(),
      'TargetLanguage' => $job->getRemoteTargetLanguage(),
      'FilePathAndName' => "$name.xliff",
      'FileState' => 'TranslatableSource',
      'FileData' => base64_encode($xliff),
    ];
    $file_id = $this->request('file', 'POST', $form_params);

    $files = $remote_mapping->getRemoteData('files');
    $files[$file_id] = ['FileStateVersion' => 1];
    $remote_mapping->addRemoteData('files', $files);
    $remote_mapping->save();

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
   * @param Job $job
   *   A job containing job items that translations will be fetched for.
   * @param string $state
   *   The state of the files.
   *
   * @return bool
   *   Returns TRUE if there are error messages during the process of
   *   retrieving translations. Otherwise FALSE.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function getTranslatedFiles(Job $job, $state) {
    // Search for placeholder item.
    $result = RemoteMapping::loadByLocalData($job->id());
    $remote = reset($result);
    $this->setTranslator($job->getTranslator());
    $project_id = $remote->getRemoteIdentifier2();
    $project_id = 18716632;
    $translated = 0;
    $not_translated = 0;
    $had_errors = FALSE;

    try {
      // Get the files of this project.
      $files = $this->request('fileinfos/TranslatableSource');// . $state);
      $my_files = [];
      foreach ($files as $file) {
        if ($file['ProjectId'] == $project_id) {
          $my_files[$file['FileId']] = $file;
        }
      }
      $remotes = [];
      foreach ($result as $remote) {
        /** @var RemoteMapping $remote */
        $remotes[$remote->getJobItemId()] = $remote;
      }

      // Loop over job items and check for if there is a translation available.
      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job->getItems() as $job_item) {
        $files = $remotes[$job_item->id()]->getRemoteData('files');
        $ids = array_keys($files);
        $file_id = reset($ids);
        $original_file_id = $file_id;
        $file_id = '0067ccc1-0000-0000-0000-000000000000';
        if (isset($file_id) && array_key_exists($file_id, $my_files) && array_key_exists($original_file_id, $files) && $files[$original_file_id]['FileStateVersion'] == $my_files[$file_id]['FileStateVersion']) {
          try {
            // throw new TMGMTException('Kaboom, error test, do not worry about it.');
            $data = $this->request('file/' . $state . '/' . $file_id);
            $decoded_data = base64_decode($data['FileData']);
            $data = str_replace('id="6', 'id="' . $job_item->id(), $decoded_data);
            $data = str_replace('resname="6', 'resname="' . $job_item->id(), $data);
            $data = str_replace('job-id="' . $job_item->id(), 'job-id="' . $job->id(), $data);
            $file_data = $this->parseTranslationData($data);
            $file_data = $this->addPreliminaryStatusToData($file_data);
            if ($state == 'TranslatableComplete') {
              $status = TMGMT_DATA_ITEM_STATE_TRANSLATED;
            }
            else {
              $status = TMGMT_DATA_ITEM_STATE_PRELIMINARY;
            }
            $job_item->getJob()->addTranslatedData($file_data, [], $status);
            // Confirm that we download the file.
            $form_params = [
              'FileId' => $file_id,
              'FileState' => $state,
            ];
            $this->request('fileinfo/downloaded', 'POST', $form_params);
          }
          catch (TMGMTException $e) {
            $this->sendFileError('RestartPoint01', $file_id, $e->getMessage(), NULL, $job_item);
            $job->addMessage('Error fetching the job item: @job_item.', array('@job_item' => $job_item->label()));
            $had_errors = TRUE;
            $not_translated++;
            continue;
          }

          if (isset($status) && $status == TMGMT_DATA_ITEM_STATE_PRELIMINARY) {
            $this->sendPreviewUrl($job_item, $original_file_id, TRUE);
          }
          $translated++;
        }
        else {
          $not_translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      $job->addMessage('Could not pull translation resources.', array(), 'error');
      return FALSE;
    }
    finally {
      if ($had_errors) {
        $form_params = [
          'ProjectId' => $project_id,
          'FileState' => 'RestartPoint01',
        ];
        $this->request('fileinfos/uploaded', 'POST', $form_params);
      }
      if (empty($not_translated)) {
        $job->addMessage('Fetched translations for @translated job items.', array('@translated' => $translated));
      }
      else {
        $job->addMessage('Fetched translations for @translated job items, @not_translated are not translated yet.', array('@translated' => $translated, '@not_translated' => $not_translated));
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var JobItemInterface $job_item */
    foreach ($job_items as $job_item) {
      $this->setTranslator($job_item->getJob()->getTranslator());

      try {
        $job_item_id = $job_item->id();
        $project_id = $this->newTranslationProject($job_item_id, $job_item_id, $job_item->getJob()->getSetting('required_by'), $job_item->getJob()->getSetting('quote_required'), $job_item->getJob()->getSetting('category'));

        /** @var RemoteMapping $remote_mapping */
        $remote_mapping = \Drupal::entityTypeManager()
          ->getStorage('tmgmt_remote')
          ->create([
            'tjid' => $job_item->getJob()->id(),
            'tjiid' => $job_item->id(),
            'data_item_key' => 'tmgmt_thebigword',
            'remote_identifier_1' => 'ProjectId',
            'remote_identifier_2' => $project_id,
            'files' => [],
          ]);
        $remote_mapping->save();
        $this->sendFiles($job_item);
        // Confirm is required to trigger the translation.
        $form_params = [
          'ProjectId' => $project_id,
          'FileState' => 'ReferenceAdd',
        ];
        $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
        if ($confirmed != 1) {
          $message = 'Not all the references had been confirmed.';
          $this->sendFileError('RestartPoint01', $message, NULL, $job_item);
          throw new TMGMTException($message);
        }
        $form_params = [
          'ProjectId' => $project_id,
          'FileState' => 'TranslatableSource',
        ];
        $confirmed = $this->request('fileinfos/uploaded', 'POST', $form_params);
        if ($confirmed != 1) {
          $message = 'Not all the sources had been confirmed.';
          $this->sendFileError('RestartPoint01', $message, NULL, $job_item);
          throw new TMGMTException($message);
        }

        $job_item->getJob()->submitted('Job item has been successfully submitted for translation.');
      }
      catch (TMGMTException $e) {
        \Drupal::logger('tmgmt_thebigword')
          ->error('Job item has been rejected with following error: @error',
            array('@error' => $e->getMessage()));
        $job_item->getJob()->rejected('Job item has been rejected with following error: @error',
          array('@error' => $e->getMessage()), 'error');
      }
    }
  }

  /**
   * Send the preview url.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job item.
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
  protected function sendPreviewUrl(JobItemInterface $job_item, $file_id, $preview) {
    $remote_mappings = RemoteMapping::loadByLocalData($job_item->getJobId(), $job_item->id(), 'tmgmt_thebigword');
    $remote_mapping = reset($remote_mappings);
    $project_id = $remote_mapping->getRemoteIdentifier2();

    /** @var Url $url */
    $uri = $job_item->getSourceUrl()->getInternalPath();
    $state = 'ReferenceAdd';
    $name = 'source-url';
    if ($preview) {
      $url = $job_item->getSourcePlugin()->getPreviewUrl($job_item);
      $uri = $url->getInternalPath() . '?key=' . $url->getOption('query')['key'];
      $state = 'ResourcePreviewUrl';
      $name = 'preview-url';
    }
    $preview_data = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE PreviewUrl SYSTEM "http://www.thebigword.com/dtds/PreviewUrl.dtd">
<PreviewUrl>' . $_SERVER['HTTP_HOST'] . '/' . $uri . '</PreviewUrl>';

    /** @var \DateTime $required_by */
    $required_by = $job_item->getJob()->getSetting('required_by');
    $required_by->setTimezone(new \DateTimeZone('UTC'));
    $datetime = $required_by->format('Y-m-d\TH:i:s');
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $datetime,
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
  public function pullRemoteTranslations(Translator $translator) {
    $this->setTranslator($translator);
    $result = RemoteMapping::loadByRemoteIdentifier('ProjectId');
    $remotes = [];
    /** @var RemoteMapping $remote */
    foreach ($result as $remote) {
      $remotes[$remote->getRemoteIdentifier2()] = $remote;
    }
    $translated = 0;
    $not_translated = 0;

    try {
      // @todo TranlatablePreviewReview
      $files = $this->request('fileinfos/TranslatableSource');
      foreach ($files as $file) {
        $file_id = $file['FileId'];
        $project_id = $file['ProjectId'];
        /** @var RemoteMapping $remote */
        $remote = isset($remotes[$project_id]) ? $remotes[$project_id] : NULL;
        if ($remote != NULL && isset($remote->getRemoteData('files')[$file_id]) && $file['FileStateVersion'] == $remote->getRemoteData('files')[$file_id]['FileStateVersion']) {
          $tmp_file_id = '0067ccc1-0000-0000-0000-000000000000';
          $data = $this->request('file/TranslatableReviewPreview/' . $tmp_file_id);
          $decoded_data = base64_decode($data['FileData']);
          $data = str_replace('id="6', 'id="' . $remote->getJobItemId(), $decoded_data);
          $data = str_replace('resname="6', 'resname="' . $remote->getJobItemId(), $data);
          $data = str_replace('job-id="' . $remote->getJobItemId(), 'job-id="' . $remote->getJobId(), $data);
          $file_data = $this->parseTranslationData($data);
          $remote->getJob()->addTranslatedData($file_data, NULL, TMGMT_DATA_ITEM_STATE_PRELIMINARY);
          // Confirm that we download the file.
          $form_params = [
            'FileId' => $file_id,
            'FileState' => 'TranslatableReviewPreview',
          ];
          // $this->request('fileinfos/downloaded', 'PUT', $form_params);
          $translated++;
        }
        else {
          $not_translated++;
        }
      }
    }
    catch (TMGMTException $e) {
      drupal_set_message('Could not pull translation resources.', array(), 'error');
      return FALSE;
    }
    finally {
      return [
        'translated' => $translated,
        'untranslated' => $not_translated,
      ];
    }
  }

  /**
   * Sends an error file to Thebigword.
   *
   * @param string $state
   *   The state.
   * @param string $file_id
   *   The file id to update.
   * @param string $message
   *   The error message.
   * @param \Drupal\tmgmt\JobInterface $job
   *   (Optional) A Job, if a JobItem is given, Job will be discarded.
   *   At least or a Job or a JobItem must be given.
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   (Optional) A JobItem.
   *   At least or a Job or a JobItem must be given.
   * @param bool $confirm
   *   (Optional) Set to TRUE if also want to send the confirmation message
   *   of this error. Otherwise will not send it.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If there is a problem with the request or if no Job and no JobItem are
   *   given.
   */
  public function sendFileError($state, $file_id, $message = '', JobInterface $job = NULL, JobItemInterface $job_item = NULL, $confirm = FALSE) {
    if (!$job_item && !$job) {
      throw new TMGMTException('No Job or JobItem given to sendFileError, at least one of both arguments is mandatory.');
    }
    if ($job_item) {
      $job = $job_item->getJob();
    }
    $remote_mappings = RemoteMapping::loadByLocalData($job->id(), $job_item != NULL ? $job_item->id() : NULL, 'tmgmt_thebigword');
    $remote_mapping = reset($remote_mappings);
    $project_id = $remote_mapping->getRemoteIdentifier2();

    /** @var \DateTime $required_by */
    $required_by = $job->getSetting('required_by');
    $required_by->setTimezone(new \DateTimeZone('UTC'));
    $datetime = (new DrupalDateTime())->format('Y-m-d\TH:i:s');
    $form_params = [
      'ProjectId' => $project_id,
      'RequiredByDateUtc' => $datetime,
      'SourceLanguage' => $job->getRemoteSourceLanguage(),
      'TargetLanguage' => $job->getRemoteTargetLanguage(),
      'FilePathAndName' => 'error-' . (new DrupalDateTime())->format('Y-m-d\TH:i:s') . '.txt',
      'FileIdToUpdate' => $file_id,
      'FileState' => $state,
      'FileData' => base64_encode($message),
    ];
    $this->request('file', 'POST', $form_params);
    if ($confirm) {
      $form_params = [
        'ProjectId' => $project_id,
        'FileState' => 'RestartPoint01',
      ];
      $this->request('fileinfos/uploaded', 'POST', $form_params);
    }
  }

  /**
   * Add the prliminary status to the data items.
   *
   * @param array $data_items
   *   Data items.
   *
   * @return array
   *   The data items filled with the preliminart status
   */
  private function addPreliminaryStatusToData($data_items) {
    if (isset($data_items['#text'])) {
      $data_items['#status'] = TMGMT_DATA_ITEM_STATE_PRELIMINARY;
      return $data_items;
    }
    foreach ($data_items as $key => $data_item) {
      $data_items[$key] = $this->addPreliminaryStatusToData($data_item);
    }
    return $data_items;
  }

}
