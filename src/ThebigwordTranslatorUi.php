<?php

/**
 * @file
 * Contains Drupal\tmgmt_thebigword\ThebigwordTranslatorUi.
 */

namespace Drupal\tmgmt_thebigword;

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

    $form['client_contact_key'] = array(
      '#type' => 'textfield',
      '#title' => t('Thebigword client contact key'),
      '#default_value' => $translator->getSetting('client_contact_key'),
      '#description' => t('Please enter your client contact key.'),
    );
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
    $settings['required_by'] = [
      '#type' => 'datetime',
      '#title' => t('Required By'),
      '#description' => t('The date the project is required by.'),
      '#default_value' => $job->getSetting('required_by') ? $job->getSetting('required_by') : '',
    ];
    $settings['quote_required'] = [
      '#type' => 'checkbox',
      '#title' => t('Quote required'),
      '#description' => t('Is the quote required?'),
      '#default_value' => $job->getSetting('quote_required') ? $job->getSetting('quote_required') : '',
    ];
    $settings['category'] = [
      '#type' => 'select',
      '#title' => t('Category'),
      '#description' => t('Select a category to identify the area of the text you will request to translate.'),
      '#empty_option' => ' - ',
      '#options' => $translator_plugin->getCategory($job),
      '#default_value' => $job->getSetting('category') ? $job->getSetting('category') : '',
    ];

    return $settings;
  }

}
