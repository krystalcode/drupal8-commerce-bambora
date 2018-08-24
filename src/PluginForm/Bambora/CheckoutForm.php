<?php

namespace Drupal\commerce_bambora\PluginForm\Bambora;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;

use Drupal\Core\Form\FormStateInterface;

class CheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_bambora\Plugin\Commerce\PaymentGateway\CheckoutInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $extra = [
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
      'exception_url' => $form['#exception_url'],
      'capture' => $form['#capture'],
    ];
    $data = $payment_gateway_plugin->buildRequest($payment, $extra);

    return $this->buildRedirectForm($form, $form_state, $payment_gateway_plugin->getRedirectUrl(), $data, BasePaymentOffsiteForm::REDIRECT_GET);
  }

}
