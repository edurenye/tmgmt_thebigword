<?php

/**
 * @file
 * Contains Drupal\tmgmt_thebigword\ThebigwordTranslatorUi.
 */

namespace Drupal\tmgmt_thebigword;

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

    $form['service_url'] = [
      '#type' => 'textfield',
      '#title' => t('thebigword Web API endpoint'),
      '#default_value' => $translator->getSetting('service_url'),
      '#description' => t('Please enter the web API endpoint.'),
      '#required' => TRUE,
      '#placeholder' => 'https://example.thebigword.com/example/cms/api/1.0',
    ];
    $form['client_contact_key'] = [
      '#type' => 'textfield',
      '#title' => t('thebigword client contact key'),
      '#default_value' => $translator->getSetting('client_contact_key'),
      '#description' => t('Please enter your client contact key.'),
      '#required' => TRUE,
      '#placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    ];
    $form += parent::addConnectButton();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    /** @var \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    $result = $plugin->request('states', 'GET', [], FALSE, TRUE);
    if ($result == 401) {
      $form_state->setErrorByName('settings][client_contact_key', t('The client contact key is not correct.'));
    }
    elseif ($result != 200) {
      $form_state->setErrorByName('settings][service_url', t('The Web API endpoint is not correct.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_thebigword\Plugin\tmgmt\Translator\ThebigwordTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());
    $settings['required_by'] = [
      '#type' => 'number',
      '#title' => t('Required By (Workdays days)'),
      '#description' => t('Enter the number of working days before the translation is required.'),
      '#default_value' => $job->getSetting('required_by') ? $job->getSetting('required_by') : 5,
      '#min' => 1,
    ];
    $settings['quote_required'] = [
      '#type' => 'checkbox',
      '#title' => t('Quotation required before translation.'),
      '#description' => t('If this is selected a quote will be provided for acceptance before translation work begins.'),
      '#default_value' => $job->getSetting('quote_required') ? $job->getSetting('quote_required') : FALSE,
    ];
    $settings['category'] = [
      '#type' => 'select',
      '#title' => t('Category'),
      '#description' => t('Select the content category type. This is used to help select linguists with appropriate subject matter knowledge. Translation of specialist content (other than Generic/Universal) can affect the overall translation costs.'),
      '#options' => $translator_plugin->getCategory($job),
      '#default_value' => $job->getSetting('category'),
    ];
    $settings['review'] = [
      '#type' => 'checkbox',
      '#title' => t('Review with thebigword Review Tool'),
      '#description' => t('Indicate that the project is to be reviewed by nominated reviewers* before return to Drupal.<br><br>*Nominated Reviewers are defined by prior discussion with thebigword.'),
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
    $result = $translator_plugin->fetchTranslatedFiles($job, 'TranslatableReviewPreview', $project_id);
    $translated += $result['translated'];
    $untranslated += $result['untranslated'];
    $result = $translator_plugin->fetchTranslatedFiles($job, 'TranslatableComplete', $project_id);
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
