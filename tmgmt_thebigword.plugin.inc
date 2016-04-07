<?php

/**
 * @file
 * Provides TheBigWord translation plugin controller.
 */

/**
 * TheBigWord translation plugin controller.
 */
class TMGMTTheBigWordPluginController extends TMGMTDefaultTranslatorPluginController {

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::getSupportedLanguages().
   */
  public function getSupportedRemoteLanguages(TMGMTTranslator $translator) {
    return $this->getConnector($translator)->getSupportedRemoteLanguages($translator);
  }

  /**
   * Returns list of expertise options.
   *
   * @param TMGMTJob $job
   *   Job object.
   *
   * @return array
   *   List of expertise options, keyed by their code.
   */
  public function getCategory(TMGMTJob $job) {
    return array(
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
    );
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::isAvailable().
   */
  public function isAvailable(TMGMTTranslator $translator) {
    if ($translator->getSetting('client_contact_key')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::requestTranslation().
   *
   * Here we will acutally query source and get translations.
   */
  public function requestTranslation(TMGMTJob $job) {
    $job = $this->getConnector($job->getTranslator())->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted();
    }
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param TMGMTJob $job
   *   A job containing job items that translations will be fetched for.
   *
   * @return bool
   *   Returns TRUE if there are error messages during the process of retrieving
   *   translations. Otherwise FALSE.
   */
  public function fetchJobs(TMGMTJob $job) {
    // Search for placeholder item.
    $remotes = entity_get_controller('tmgmt_remote')->loadByLocalData($job->tjid);

    // Get job items of a given job.
    $job_items = $job->getItems();
    $translated = 0;
    $not_translated = 0;

    try {
      // Loop over job items and check for if there is a translation available.
      foreach ($remotes as $remote) {
        $job_item = $job_items[$remote->tjiid];
        if (empty($remote->remote_identifier_1)) {
          $not_translated++;
          $job_item->addMessage('Could not retrieve project information.', array(), 'error');
          continue;
        }
        $project_details = $this->getConnector($job->getTranslator())->getProjectDetails($remote->remote_identifier_1);
        if (isset($project_details['resources']['translations'])) {
          $this->retrieveTranslation((array) $project_details['resources']['translations'], $job_item, $project_details['project_id'], FALSE);
          $translated++;
        }
        else {
          $not_translated++;
        }
      }

      if (empty($not_translated)) {
        $job->addMessage('Fetched translations for @translated job items.', array('@translated' => $translated));
      }
      else {
        $job->addMessage('Fetched translations for @translated job items, @not_translated are not translated yet.', array('@translated' => $translated, '@not_translated' => $not_translated));
      }
    } catch (TMGMTTheBigWordException $e) {
      $job->addMessage('Could not pull translation resources.', array(), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings() {
    $defaults = parent::defaultSettings();
    // Enable CDATA for content encoding in File translator.
    $defaults['xliff_cdata'] = TRUE;
    return $defaults;
  }

  /**
   * Gets TheBigWord service connector.
   *
   * @param TMGMTTranslator $translator
   *   Current job translator.
   *
   * @return TheBigWordConnector
   *   TheBigWord connector instance.
   */
  public function getConnector(TMGMTTranslator $translator) {
    return new TheBigWordConnector(
      $translator->getSetting('service_url'),
      $translator->getSetting('client_contact_key')
    );
  }

}
