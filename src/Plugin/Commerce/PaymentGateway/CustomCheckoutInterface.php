<?php

namespace Drupal\commerce_bambora\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Custom Checkout payment gateway.
 */
interface CustomCheckoutInterface extends
  OnsitePaymentGatewayInterface,
  SupportsAuthorizationsInterface,
  SupportsRefundsInterface { }
