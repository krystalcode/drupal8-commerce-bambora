<?php

namespace Drupal\commerce_bambora\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_bambora\BamboraService;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Beanstream\Exception;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Bambora Off-site Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "bambora_checkout",
 *   label = @Translation("Bambora (Checkout)"),
 *   display_label = @Translation("Bambora"),
 *
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_bambora\PluginForm\Bambora\CheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "diners", "jcb", "mastercard", "discover", "visa",
 *   },
 * )
 */
class Checkout extends OffsitePaymentGatewayBase implements CheckoutInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The price rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  protected $rounder;

  /**
   * The time.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The Bambora service class.
   *
   * @var \Drupal\commerce_bambora\BamboraService
   */
  protected $bamboraService;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   *   The logger channel factory.
   * @param \GuzzleHttp\ClientInterface $client
   *   The client.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The price rounder.
   * @param \Drupal\commerce_bambora\BamboraService $bambora_service
   *   The commerce bambora service class.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    LoggerChannelFactoryInterface $logger_channel_factory,
    ClientInterface $client,
    RounderInterface $rounder,
    BamboraService $bambora_service
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

    $this->logger = $logger_channel_factory->get(COMMERCE_BAMBORA_LOGGER_CHANNEL);
    $this->httpClient = $client;
    $this->rounder = $rounder;
    $this->bamboraService = $bambora_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('logger.factory'),
      $container->get('http_client'),
      $container->get('commerce_price.rounder'),
      $container->get('commerce_bambora.commerce_bambora')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'merchant_id' => '',
      'hash_key' => '',
      'payments_api_key' => '',
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

    $form['hash_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hash Key'),
      '#description' => $this->t('This is the Hash Key found under
        Administration -> Account Settings -> Order Settings.'),
      '#default_value' => $this->configuration['hash_key'],
      '#required' => TRUE,
    ];

    $form['payments_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payments API Key'),
      '#description' => $this->t('This is the API Passcode found under
        Administration -> Account -> Order Settings.'),
      '#default_value' => $this->configuration['payments_api_key'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['hash_key'] = $values['hash_key'];
      $this->configuration['payments_api_key'] = $values['payments_api_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $approved = $request->query->get('trnApproved');

    if ($approved == 0) {
      throw new PaymentGatewayException(
        $request->query->get('messageText'),
        $request->query->get('messageID')
      );
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'remote_id' => $request->query->get('trnId'),
      'remote_state' => $approved,
    ]);

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
      $beanstream = $this->bamboraService->initializeBeanstream($payment->getPaymentGateway(), 'payment');

      $remote_id = $payment->getRemoteId();
      $result = $beanstream
        ->payments()
        ->complete(
          $remote_id,
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
      $beanstream = $this->bamboraService->initializeBeanstream($payment->getPaymentGateway(), 'payment');

      $remote_id = $payment->getRemoteId();

      $result = $beanstream
        ->payments()
        ->voidPayment(
          $remote_id,
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
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount = $this->rounder->round($amount);
    $this->assertRefundAmount($payment, $amount);

    // Refund the payment.
    try {
      $beanstream = $this->bamboraService->initializeBeanstream($payment->getPaymentGateway(), 'payment');

      $remote_id = $payment->getRemoteId();

      $result = $beanstream
        ->payments()
        ->returnPayment(
          $remote_id,
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
  public function getHashValue() {
    $configuration = $this->getConfiguration();

    return sha1(
      'merchant_id='
      . $configuration['merchant_id']
      . $configuration['hash_key']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl() {
    return 'https://web.na.bambora.com/scripts/payment/payment.asp';
  }

  /**
   * {@inheritdoc}
   */
  public function buildRequest(PaymentInterface $payment, array $extra) {
    $configuration = $this->getConfiguration();
    $order = $payment->getOrder();
    $rounder = \Drupal::service('commerce_price.rounder');
    $amount = $rounder->round($payment->getAmount());
    $capture = $extra['capture'] == TRUE ? 'P' : 'PA';
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $order->getBillingProfile()->address->first();
    $name = $address->getGivenName() . ' ' . $address->getFamilyName();

    $data = [
      'merchant_id' => $configuration['merchant_id'],
      'hashValue' => $this->getHashValue(),
      'trnAmount' => $amount->getNumber(),
      'trnOrderNumber' => $order->id(),
      'trnType' => $capture,
      'trnCardOwner' => $name,
      'ordName' => $name,
      'ordEmailAddress' => $order->getEmail(),
      'ordAddress1' => $address->getAddressLine1(),
      'ordAddress2' => $address->getAddressLine2(),
      'ordCity' => $address->getLocality(),
      'ordProvince' => $address->getAdministrativeArea(),
      'ordPostalCode' => $address->getPostalCode(),
      'ordCountry' => $address->getCountryCode(),
      'approvedPage' => $extra['return_url'],
      'declinedPage' => $extra['exception_url'],
    ];

    return $data;
  }

}
