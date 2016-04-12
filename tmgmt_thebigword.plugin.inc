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
    return $this->getConnector($translator)->getSupportedRemoteLanguages();
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
      $translator->getSetting('client_contact_key'),
      $translator
    );
  }

}
