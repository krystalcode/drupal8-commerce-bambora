<?php

namespace Drupal\commerce_bambora\PluginForm\Bambora;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;

use Drupal\Core\Form\FormStateInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // Alter the form with Bambora specific needs.
    $element['#attributes']['class'][] = 'bambora-form';

    $element['#attached']['library'][] = 'commerce_bambora/form';

    // Populated by the JS library.
    $element['bambora_token'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'id' => 'bambora_token'
      ]
    ];

    $element['card_number'] = [
      '#type' => 'item',
      '#title' => t('Card number'),
      '#required' => TRUE,
      '#validated' => TRUE,
      '#markup' => '<div id="card-number" class="form-text"></div>',
    ];

    $element['expiration'] = [
      '#type' => 'item',
      '#title' => t('Expiration date'),
      '#required' => TRUE,
      '#validated' => TRUE,
      '#markup' => '<div id="card-expiry" class="form-text"></div>',
    ];

    $element['security_code'] = [
      '#type' => 'item',
      '#title' => t('CVC'),
      '#required' => TRUE,
      '#validated' => TRUE,
      '#markup' => '<div id="card-cvv" class="form-text"></div>',
    ];

    // To display validation errors.
    $element['payment_errors'] = [
      '#type' => 'markup',
      '#markup' => '<div id="payment-errors"></div>',
      '#weight' => -200,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    // Add the bambora attribute to the postal code field.
    $form['billing_information']['address']['widget'][0]['address_line1']['#attributes']['data-bambora'] = 'address_line1';
    $form['billing_information']['address']['widget'][0]['address_line2']['#attributes']['data-bambora'] = 'address_line2';
    $form['billing_information']['address']['widget'][0]['locality']['#attributes']['data-bambora'] = 'address_city';
    $form['billing_information']['address']['widget'][0]['postal_code']['#attributes']['data-bambora'] = 'address_zip';
    $form['billing_information']['address']['widget'][0]['country_code']['#attributes']['data-bambora'] = 'address_country';
    return $form;
  }

}
