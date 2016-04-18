<?php

/**
 * @file
 * Please supply a file description.
 */

/**
 * @file
 * Provides TheBigWord translation plugin controller.
 */
class TMGMTTheBigWordTranslatorUIController extends TMGMTDefaultTranslatorUIController {

  /**
   * Overrides TMGMTDefaultTranslatorUIController::pluginSettingsForm().
   */
  public function pluginSettingsForm($form, &$form_state, TMGMTTranslator $translator, $busy = FALSE) {
    $form['service_url'] = array(
      '#type' => 'textfield',
      '#title' => t('thebigword Web API endpoint'),
      '#default_value' => $translator->getSetting('service_url'),
      '#description' => t('Please enter the web API endpoint.'),
      '#required' => TRUE,
      '#placeholder' => 'https://example.thebigword.com/example/cms/api/1.0',
    );
    $form['client_contact_key'] = array(
      '#type' => 'textfield',
      '#title' => t('thebigword client contact key'),
      '#default_value' => $translator->getSetting('client_contact_key'),
      '#description' => t('Please enter your client contact key.'),
      '#required' => TRUE,
      '#placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    );
    return parent::pluginSettingsForm($form, $form_state, $translator, $busy);
  }

  /**
   * Implements TMGMTTranslatorUIControllerInterface::checkoutInfo().
   */
  public function checkoutInfo(TMGMTJob $job) {
    $form = array();

    if ($job->isActive()) {
      $form['actions']['pull'] = array(
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => array('_tmgmt_thebigword_pull_submit'),
        '#weight' => -10,
      );
    }

    return $form;
  }

  /**
   * Overrides TMGMTDefaultTranslatorUIController::checkoutSettingsForm().
   */
  public function checkoutSettingsForm($form, &$form_state, TMGMTJob $job) {
    /**
     * @var TMGMTTheBigWordPluginController $controller The translator object.
     */
    $controller = $job->getTranslator()->getController();
    $settings['required_by'] = array(
      '#type' => 'textfield',
      '#attributes' => array(
        'data-type' => 'number',
      ),
      '#title' => t('Required By (Workdays days)'),
      '#description' => t('Enter the number of working days before the translation is required.'),
      '#default_value' => $job->getSetting('required_by') ? $job->getSetting('required_by') : 5,
      '#min' => 1,
    );
    $settings['quote_required'] = array(
      '#type' => 'checkbox',
      '#title' => t('Quotation required before translation'),
      '#description' => t('If this is selected a quote will be provided for acceptance before translation work begins.'),
      '#default_value' => $job->getSetting('quote_required') ? $job->getSetting('quote_required') : FALSE,
    );
    $settings['category'] = array(
      '#type' => 'select',
      '#title' => t('Category'),
      '#description' => t('Select the content category type. This is used to help select linguists with appropriate subject matter knowledge. Translation of specialist content (other than Generic/Universal) can affect the overall translation costs.'),
      '#options' => $controller->getCategory($job),
      '#default_value' => $job->getSetting('category'),
    );
    $settings['review'] = array(
      '#type' => 'checkbox',
      '#title' => t('Review with thebigword Review Tool'),
      '#description' => t('Indicate that the project is to be reviewed by nominated reviewers* before return to Drupal.<br><br>*Nominated Reviewers are defined by prior discussion with thebigword.'),
      '#default_value' => $job->getSetting('review') ? $job->getSetting('review') : TRUE,
    );

    return $settings;
  }

}
