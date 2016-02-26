<?php

/**
 * @file
 * Contains Drupal\tmgmt_thebigword\ThebigwordTranslatorUi.
 */

namespace Drupal\tmgmt_thebigword;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\JobInterface;

/**
 * Thebigword translator UI.
 */
class ThebigwordTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['client_contact_key'] = [
      '#type' => 'textfield',
      '#title' => t('Thebigword client contact key'),
      '#default_value' => $translator->getSetting('client_contact_key'),
      '#description' => t('Please enter your client contact key.'),
    ];
    $form['service_url'] = [
      '#type' => 'value',
      '#value' => 'http://uat-integration.thebigword.com/drupal/api',
    ];
    $form += parent::addConnectButton();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $supported_remote_languages = $translator->getPlugin()->getSupportedRemoteLanguages($translator);
    if (empty($supported_remote_languages)) {
      $form_state->setErrorByName('settings][client_contact_key', t('The client contact key is not correct.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    // @todo Replace the widget with one that just says in how many days you
    // want it, as it does not work in continuous translator, also we should
    // skip weekends.
    /** @var \DateTime $default_datetime */
    $default_datetime = new DrupalDateTime('+1 week');
    $settings['required_by'] = [
      '#type' => 'datetime',
      '#title' => t('Required By'),
      '#description' => t('The date the project is required by. You will not get translations during the weekends.'),
      '#default_value' => $job->getSetting('required_by') ? $job->getSetting('required_by') : $default_datetime,
    ];
    $settings['quote_required'] = [
      '#type' => 'checkbox',
      '#title' => t('Quote required'),
      '#description' => t('Is the quote required?'),
      '#default_value' => $job->getSetting('quote_required') ? $job->getSetting('quote_required') : FALSE,
    ];
    $settings['category'] = [
      '#type' => 'select',
      '#title' => t('Category'),
      '#description' => t('Select a category to identify the area of the text you will request to translate.'),
      '#options' => $translator_plugin->getCategory($job),
      '#default_value' => $job->getSetting('category'),
    ];
    $settings['review'] = [
      '#type' => 'checkbox',
      '#title' => t('Review'),
      '#description' => t('Set the project as reviewable.'),
      '#default_value' => $job->getSetting('review') ? $job->getSetting('review') : TRUE,
    ];

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = [];

    if ($job->isActive()) {
      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => [[$this, 'submitPullTranslations']],
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * Submit callback to pull translations form thebigword.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    $translated = 0;
    $untranslated = 0;
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    $result = RemoteMapping::loadByLocalData($job->id());
    $remote = reset($result);
    $project_id = $remote->getRemoteIdentifier2();

    /** @var \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $result = $translator_plugin->addTranslatedFilesToJob($job, 'TranslatableReviewPreview', $project_id);
    $translated += $result['translated'];
    $untranslated += $result['untranslated'];
    $result = $translator_plugin->addTranslatedFilesToJob($job, 'TranslatableComplete', $project_id);
    $translated += $result['translated'];
    $untranslated += $result['untranslated'];
    if ($untranslated == 0 && $translated != 0) {
      $job->addMessage('Fetched translations for @translated job items.', ['@translated' => $translated]);
    }
    elseif ($translated == 0) {
      drupal_set_message('No job item has been translated yet.');
    }
    else {
      $job->addMessage('Fetched translations for @translated job items, @untranslated are not translated yet.', [
        '@translated' => $translated,
        '@untranslated' => $untranslated,
      ]);
    }
    tmgmt_write_request_messages($job);
  }

}
