<?php

namespace Drupal\commerce_bambora\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_bambora\ApiService;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Beanstream\Exception;
use Beanstream\Profiles as ProfilesApi;

/**
 * Provides the Bambora Custom Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "bambora_custom_checkout",
 *   label = "Bambora (Custom Checkout)",
 *   display_label = "Bambora",
 *   forms = {
 *     "add-payment-method" =
 *   "Drupal\commerce_bambora\PluginForm\Bambora\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "diners", "jcb", "mastercard", "discover", "visa",
 *   },
 *   js_library = "commerce_bambora/form",
 * )
 */
class CustomCheckout extends OnsitePaymentGatewayBase implements CustomCheckoutInterface {

  /**
   * Holds the period in seconds after which an authorization expires (29 days).
   */
  const AUTHORIZATION_EXPIRATION_PERIOD = 2505600;

  /**
   * The rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * The Bambora API helper service.
   *
   * @var \Drupal\commerce_bambora\ApiService
   */
  protected $apiService;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    RounderInterface $rounder,
    ApiService $api_service
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $payment_type_manager,
      $payment_method_type_manager,
      $time
    );

    $this->rounder = $rounder;
    $this->apiService = $api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_price.rounder'),
      $container->get('commerce_bambora.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'merchant_id' => '',
      'payments_api_key' => '',
      'profiles_api_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['payments_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payments API Key'),
      '#description' => $this->t('This is the API Passcode found under
        Administration -> Account Settings -> Order Settings -> Payment Gateway
        -> Security/Authentication.'),
      '#default_value' => $this->configuration['payments_api_key'],
      '#required' => TRUE,
    ];

    $form['profiles_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profiles API Key'),
      '#description' => $this->t('This is the API Passcode found under
        Configuration -> Payment Profile Configuration -> Security Settings.'),
      '#default_value' => $this->configuration['profiles_api_key'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['payments_api_key'] = $values['payments_api_key'];
      $this->configuration['profiles_api_key'] = $values['profiles_api_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentState($payment, ['new']);
    $this->assertPaymentMethod($payment_method);

    $payments_api = $this->apiService->payments($payment->getPaymentGateway());

    // If the user is authenticated.
    $owner = $payment_method->getOwner();
    if ($owner && $owner->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($owner);
      $remote_id = $payment_method->getRemoteId();

      try {
        // Authorize the payment.
        $profile_payment_data = [
          'order_number' => $payment->getOrderId(),
          'amount' => $this->rounder->round($payment->getAmount())->getNumber(),
        ];

        $result = $payments_api
          ->makeProfilePayment(
            $customer_id,
            $remote_id,
            $profile_payment_data,
            $capture
          );

        $remote_id = $result['id'];
      }
      catch (Exception $e) {
        throw new HardDeclineException('Could not charge the payment method. Message: ' . $e->getMessage());
      }
    }
    // Else if we have an anonymous user we issue a legato token payment.
    else {
      $payments_api = $this->apiService->payments($payment_method->getPaymentGateway());

      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $payment->getPaymentMethod()->getBillingProfile()->get('address')->first();

      $legato_payment_data = [
        'order_number' => $payment->getOrderId(),
        'amount' => $this->rounder->round($payment->getAmount())->getNumber(),
        'name' => $address->getGivenName() . ' ' . $address->getFamilyName(),
      ];

      try {
        $result = $payments_api->makeLegatoTokenPayment(
          $payment_method->getRemoteId(),
          $legato_payment_data,
          $capture
        );
      }
      catch (Exception $e) {
        throw new HardDeclineException(
          sprintf('Could not charge the payment method. Message: "%s"', $e->getMessage()),
          0,
          NULL,
          $this->t(
            'We encountered an error processing your payment method. We use a
             secure, single-use authorization that is temporary and it might
             have already expired. Please try adding your payment details again
             using the "New credit card" option.'
          )
        );
      }

      $remote_id = $result['id'];
    }

    $next_state = $capture ? 'completed' : 'authorization';
    if (!$capture) {
      $payment->setExpiresTime($this->time->getRequestTime() + self::AUTHORIZATION_EXPIRATION_PERIOD);
    }
    $payment->setState($next_state);
    $payment->setRemoteId($remote_id);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);

    // Capture the payment.
    try {
      $result = $this->apiService->payments($payment->getPaymentGateway())
        ->complete(
          $payment->getRemoteId(),
          $amount->getNumber()
      );
    }
    catch (Exception $e) {
      throw new PaymentGatewayException('Could not capture the payment. Message: ' . $e->getMessage());
    }

    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    // Void the payment.
    try {
      $result = $this->apiService->payments($payment->getPaymentGateway())
        ->voidPayment(
          $payment->getRemoteId(),
          $this->rounder->round($payment->getAmount())->getNumber()
        );
    }
    catch (Exception $e) {
      throw new PaymentGatewayException('Could not void the payment. Message: ' . $e->getMessage());
    }

    $payment->setState('authorization_voided');
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(
    PaymentInterface $payment,
    Price $amount = NULL
  ) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);
    $this->assertRefundAmount($payment, $amount);

    // Refund the payment.
    try {
      $result = $this->apiService->payments($payment->getPaymentGateway())
        ->returnPayment(
          $payment->getRemoteId(),
          $amount->getNumber(),
          $payment->getOrderId()
        );
    }
    catch (Exception $e) {
      throw new InvalidRequestException('Could not refund the payment. Message: ' . $e->getMessage());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(
    PaymentMethodInterface $payment_method,
    array $payment_details
  ) {
    // The expected token must always be present.
    $required_keys = [
      'bambora_token',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf(
          '$payment_details must contain the %s key.',
          $required_key
        ));
      }
    }

    $owner = $payment_method->getOwner();

    // Create the payment method for anonymous users. It simply stores the token
    // as the method's remote ID since there is no API currently that allows
    // getting the card details from the token.
    if (!$owner || !$owner->isAuthenticated()) {
      $this->doCreatePaymentMethodForAnonymousUser(
        $payment_method,
        $payment_details
      );
      return;
    }

    // Create the actual payment method depending on new/existing customer.
    $remote_payment_method = $this->doCreatePaymentMethod(
      $payment_method,
      $payment_details
    );

    // If the user is authenticated, we save the most recently created card ID
    // as the remote ID.
    if ($owner && $owner->isAuthenticated()) {
      $card = end($remote_payment_method['card']);

      $payment_method->card_type = $this->mapCreditCardType($card['card_type']);
      $payment_method->card_number = substr($card['number'], -4);
      $payment_method->card_exp_month = $card['expiry_month'];
      $payment_method->card_exp_year = $card['expiry_year'];

      // Expiration time.
      $expires = CreditCard::calculateExpirationTimestamp(
        $card['expiry_month'],
        $card['expiry_year']
      );
      $payment_method->setExpiresTime($expires);

      $remote_id = $card['card_id'];
    }

    $payment_method->setRemoteId($remote_id);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $owner = $payment_method->getOwner();
    // If there's no owner we won't be able to delete the remote payment method
    // as we won't have a remote profile. Just delete the payment method locally
    // in that case.
    if (!$owner) {
      $payment_method->delete();
      return;
    }

    // Delete the remote record on Bambora.
    try {
      $result = $this->apiService->profiles($payment_method->getPaymentGateway())
        ->deleteCard(
          $this->getRemoteCustomerId($owner),
          $payment_method->getRemoteId()
        );
    }
    catch (Exception $e) {
      throw new InvalidRequestException('Could not delete the payment method. Message: ' . $e->getMessage());
    }

    $payment_method->delete();
  }

  /**
   * Creates the payment method on the Bambora gateway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @return array
   *   The details of the entered credit card.
   */
  protected function doCreatePaymentMethod(
    PaymentMethodInterface $payment_method,
    array $payment_details
  ) {
    $profiles_api = $this->apiService->profiles($payment_method->getPaymentGateway());

    $owner = $payment_method->getOwner();

    if ($owner && $owner->isAuthenticated()) {
      $customer_id = $this->getRemoteCustomerId($owner);
    }

    // If the customer id already exists, use the Bambora token to retrieve
    // the customer.
    if (isset($customer_id)) {
      $result = $this->doCreatePaymentMethodForExistingCustomer(
        $payment_method,
        $payment_details,
        $customer_id,
        $profiles_api
      );
    }
    // If this is a new customer create both the customer and the payment
    // method.
    else {
      $result = $this->doCreatePaymentMethodForNewCustomer(
        $payment_method,
        $payment_details,
        $profiles_api
      );
    }

    return $result;
  }

  /**
   * Creates the payment method for an existing customer.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   * @param int $customer_id
   *   The profile ID of the customer from Bambora.
   * @param \Beanstream\Profiles $profiles_api
   *   The Beanstream Profiles API client.
   *
   * @return array
   *   The details of the last entered credit card.
   */
  protected function doCreatePaymentMethodForExistingCustomer(
    PaymentMethodInterface $payment_method,
    array $payment_details,
    $customer_id,
    ProfilesApi $profiles_api
  ) {
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $payment_method->getBillingProfile()->get('address')->first();

    try {
      // Create a payment method for an existing customer.
      $card_data = [
        'token' => [
          'name' => $address->getGivenName() . ' ' . $address->getFamilyName(),
          'code' => $payment_details['bambora_token'],
        ],
        'validate' => TRUE,
      ];
      $result = $profiles_api->addCard(
        $customer_id,
        $card_data
      );
      $cards = $profiles_api->getCards($customer_id);

      return $cards;
    }
    catch (Exception $e) {
      throw new HardDeclineException('Unable to verify the credit card: ' . $e->getMessage());
    }
  }

  /**
   * Creates the payment method for a new customer.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   * @param \Beanstream\Profiles $profiles_api
   *   The Beanstream Profiles API client.
   *
   * @return array
   *   The details of the last entered credit card.
   */
  protected function doCreatePaymentMethodForNewCustomer(
    PaymentMethodInterface $payment_method,
    array $payment_details,
    ProfilesAPI $profiles_api
  ) {
    $owner = $payment_method->getOwner();

    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $payment_method->getBillingProfile()->get('address')->first();

    // For authenticated users we create a payment method by first creating a
    // profile on Bambora.
    if ($owner && $owner->isAuthenticated()) {
      try {
        // Create a new customer profile.
        $profile_create_token = [
          'billing' => [
            'name' => $address->getGivenName() . ' ' . $address->getFamilyName(),
            'email_address' => $owner->getEmail(),
            'phone_number' => '1234567890',
            'address_line1' => $address->getAddressLine1(),
            'city' => $address->getLocality(),
            'province' => $address->getAdministrativeArea(),
            'postal_code' => $address->getPostalCode(),
            'country' => $address->getCountryCode(),
          ],
          'token' => [
            'name' => $address->getGivenName() . ' ' . $address->getFamilyName(),
            'code' => $payment_details['bambora_token'],
          ],
          'validate' => TRUE,
        ];

        $customer_id = $profiles_api->createProfile($profile_create_token);

        $cards = $profiles_api->getCards($customer_id);

        // Save the remote customer ID.
        $this->setRemoteCustomerId($owner, $customer_id);
        $owner->save();

        return $cards;
      }
      catch (Exception $e) {
        throw new HardDeclineException('Unable to verify the credit card: ' . $e->getMessage());
      }
    }
  }

  /**
   * Creates the payment method for an anonymous user.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   */
  protected function doCreatePaymentMethodForAnonymousUser(
    PaymentMethodInterface $payment_method,
    array $payment_details
  ) {
    $payment_method->setRemoteId($payment_details['bambora_token']);
    $payment_method->save();
  }

  /**
   * Maps the Bambora credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Bambora credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'AM' => 'amex',
      'DI' => 'dinersclub',
      'JB' => 'jcb',
      'MC' => 'mastercard',
      'NN' => 'discover',
      'VI' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".',
        $card_type));
    }

    return $map[$card_type];
  }

}
