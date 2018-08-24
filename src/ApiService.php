<?php

namespace Drupal\commerce_bambora;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

use Beanstream\Gateway;

/**
 * A utility service providing functionality related to Commerce Bambora.
 */
class ApiService {

  /**
   * Returns an initialized Beanstream Payments API client.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway
   *   The payment gateway we're using.
   *
   * @param \Beanstream\Payments
   *   The Beanstream Payments API client.
   */
  public function payments(PaymentGatewayInterface $gateway) {
    return $this->gateway($gateway, 'payments')->payments();
  }

  /**
   * Returns an initialized Beanstream Profiles API client.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway
   *   The payment gateway we're using.
   *
   * @param \Beanstream\Profiles
   *   The Beanstream Profiles API client.
   */
  public function profiles(PaymentGatewayInterface $gateway) {
    return $this->gateway($gateway, 'profiles')->profiles();
  }

  /**
   * Returns an initialized Beanstream Gateway client.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway
   *   The payment_gateway we're using.
   * @param string $type
   *   If we're initializing Beanstream for a profile/payment request.
   *
   * @return \Beanstream\Gateway
   *   The Beanstream Gateway class.
   */
  public function gateway(
    PaymentGatewayInterface $payment_gateway,
    $type = 'profiles'
  ) {
    $config = $payment_gateway->getPlugin()->getConfiguration();

    $api_key = $type === 'profiles'
      ? $config['profiles_api_key']
      : $config['payments_api_key'];

    // Create Beanstream Gateway.
    $beanstream = new Gateway(
      $config['merchant_id'],
      $api_key,
      'www',
      'v1'
    );

    return $beanstream;
  }

}
