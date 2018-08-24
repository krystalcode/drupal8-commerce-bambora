<?php

namespace Drupal\commerce_bambora\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface CheckoutInterface extends SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Gets the redirect URL.
   *
   * @return string
   *   The redirect URL.
   */
  public function getRedirectUrl();

  /**
   * Gets the hash value based on the merchant ID and hash key.
   *
   * @return string
   *   The hash value.
   */
  public function getHashValue();

  /**
   * Build Bambora request.
   *
   * Builds the data for the payment request to make to Bambora.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param array $extra
   *   Extra data needed for this request.
   *
   * @return array
   *   Bambora request data.
   *
   * @see https://dev.na.bambora.com/docs/references/checkout/
   */
  public function buildRequest(PaymentInterface $payment, array $extra);

}
