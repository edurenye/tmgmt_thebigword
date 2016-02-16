<?php

/**
 * @file
 * Contains \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator.
 */

namespace Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Entity\JobItem;
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
class ThebigwordTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface {

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
      $job_item_id = $job->id();
      $project_id = $this->newTranslationProject($job_item_id, $job_item_id, $job->getSetting('required_by'), $job->getSetting('quote_required'), $job->getSetting('category'));
      /** @var RemoteMapping $remote_mapping */
      $remote_mapping = \Drupal::entityTypeManager()->getStorage('tmgmt_remote')
        ->create([
          'tjid' => $job->id(),
          'data_item_key' => 'project',
          'remote_identifier_1' => 'ProjectId',
          'remote_identifier_2' => $project_id,
        ]);
      $remote_mapping->save();
      $result = $this->sendFiles($job);

      $job->submitted('Job has been successfully submitted for translation.');
    }
    catch (TMGMTException $e) {
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
   *   HTTP method (GET, POST...)
   * @param array $params
   *   Form parameters to send to Thebigword service.
   * @param bool $download
   *   If we expect resource to be downloaded.
   * @param string $content_type
   *   (optional) Content-type to use.
   *
   * @return array
   *   Response array from Thebigword.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function request($path, $method = 'GET', $params = array(), $download = FALSE, $content_type = 'application/x-www-form-urlencoded') {
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
      $options['json'] = $params;
      $options['headers'] = [
        'TMS-REQUESTER-ID' => $this->translator->getSetting('client_contact_key'),
      ];
      $response = $this->client->request($method, $url, $options);
    }
    catch (BadResponseException $e) {
      $response = $e->getResponse();
      debug($response->getBody()->getContents());
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
      '12' => 'Category 1',
      '14' => 'Category 2',
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
   * @param array $params
   *   Additional params.
   *
   * @return array
   *   Thebigword project data.
   */
  public function newTranslationProject($purchase_order_number, $project_reference, $required_by, $quote_required, $category, $params = array()) {
    $required_by->setTimezone(new \DateTimeZone('UTC'));
    $datetime = $required_by->format('Y-m-d\TH:i:s');
    $params += [
      'PurchaseOrderNumber' => $purchase_order_number,
      'ProjectReference' => $project_reference,
      'RequiredByDateUtc' => $datetime,
      'QuoteRequired' => $quote_required ? 'true' : 'false',
      'SpecialismId' => $category,
      'ProjectMetadata' => [],
    ];

    return $this->request('project', 'POST', $params);
  }

  /**
   * @param \Drupal\tmgmt\JobInterface $job
   */
  private function sendFiles(JobInterface $job) {
    $cache = \Drupal::cache('data');
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');
    $resource_uuids = array();

    foreach ($job->getItems() as $job_item) {
      $job_item_id = $job_item->id();
      $target_language = $job->getRemoteTargetLanguage();
      $cid = "tmgmt_thebigword:resource_id:$job_item_id:$target_language";
      if ($cached = $cache->get($cid)) {
        $resource_uuid = $cached->data;
      }
      else {
        $conditions = array('tjiid' => array('value' => $job_item_id));
        $xliff = $xliff_converter->export($job, $conditions);
        $name = "JobID_{$job->id()}_JobItemID_{$job_item_id}_{$job->getSourceLangcode()}_{$target_language}";

        $resource_uuid = $this->uploadFileResource($xliff, $job, $name);
        $cache->set($cid, $resource_uuid, Cache::PERMANENT, $job_item->getCacheTags());
      }
      $resource_uuids[$job_item_id] = $resource_uuid;
    }
  }

  /**
   * Creates a file resource at Thebigword.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return string
   *   Thebigword uuid of the resource.
   */
  public function uploadFileResource($xliff, JobInterface $job, $name) {
    $remote_mappings = $job->getRemoteMappings();
    /** @var RemoteMapping $remote_mapping */
    foreach ($remote_mappings as $remote_mapping) {
      if ($remote_mapping->getRemoteIdentifier1() == 'ProjectId') {
        $project_id = $remote_mapping->getRemoteIdentifier2();
        continue;
      }
    }
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
    $result = $this->request('file', 'POST', $form_params);

    return $result;
  }

}
