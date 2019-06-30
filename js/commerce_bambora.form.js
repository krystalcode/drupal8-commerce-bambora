/**
 * @file
 * Javascript to generate Bambora token in PCI-compliant way.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Attaches the commerceBamboraForm behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the commerceBamboraForm behavior.
   * @prop {Drupal~behaviorDetach} detach
   *   Detaches the commerceStripeForm behavior.
   *
   * @see Drupal.commerceBambora
   */
  Drupal.behaviors.commerceBamboraForm = {
    attach: function (context) {
      $('.bambora-form', context).once('bambora-processed').each(function () {
        var isCardNumberComplete = false;
        var isCVVComplete = false;
        var isExpiryComplete = false;

        var $form = $(this).closest('form');

        // Clear the token every time the payment form is loaded. We only need
        // the token one time, as it is submitted to Bambora after a card is
        // validated. If this form reloads it's due to an error; received tokens
        // are stored in the checkout pane.
        $('#bambora_token', $form).val('');

        // Payment card controller.
        var paymentCardCtrl = {
          init: function () {
            this.initCustomCheckout();
            this.addListeners();
          },

          /**
           * Mount the credit card fields.
           */
          initCustomCheckout: function () {
            this.customCheckout = customcheckout();

            var options = {};

            options.placeholder = 'Card number';
            var cardNumber = this.customCheckout.create('card-number', options);
            cardNumber.mount('#card-number');

            options.placeholder = 'MM / YY';
            var cardExpiry = this.customCheckout.create('expiry', options);
            cardExpiry.mount('#card-expiry');

            options.placeholder = 'CVC';
            var cardCvv = this.customCheckout.create('cvv', options);
            cardCvv.mount('#card-cvv');
          },

          /**
           * Add our listeners.
           */
          addListeners: function () {
            var _this = this;

            /**
             * If we have empty card fields.
             */
            this.customCheckout.on('empty', function(event) {
              var id = '';

              if (event.empty) {
                if (event.field === 'card-number') {
                  isCardNumberComplete = false;
                }
                else if (event.field === 'expiry') {
                  isExpiryComplete = false;
                }
                else if (event.field === 'cvv') {
                  isCVVComplete = false;
                }
              }
            });

            /**
             * If we have an error in any of the card fields.
             */
            this.customCheckout.on('error', function(event) {
              var id = '';

              if (event.field === 'card-number') {
                id = '#card-number';
                bamboraErrorDisplay(event.message);
              }
              else if (event.field === 'expiry') {
                id = '#card-expiry';
                bamboraErrorDisplay(event.message);
              }
              else if (event.field === 'cvv') {
                id = '#card-cvv';
                bamboraErrorDisplay(event.message);
              }

              bamboraGoToError(id);
            });

            /**
             * If all card field validation has passed.
             */
            this.customCheckout.on('complete', function(event) {
              // Reset any errors displayed.
              $form.find('#payment-errors').html('');
              var id = '';

              if (event.field === 'card-number') {
                id = '#card-number';
                isCardNumberComplete = true;
              }
              else if (event.field === 'expiry') {
                id = '#card-expiry';
                isExpiryComplete = true;
              }
              else if (event.field === 'cvv') {
                id = '#card-cvv';
                isCVVComplete = true;
              }

              $(id).removeClass('error');
            });

            $form.on('submit', this.onSubmit.bind(_this));
          },

          /**
           * The submit button was clicked.
           * @param event
           */
          onSubmit: function (event) {
            if ($('.bambora-form', context).length) {
              event.preventDefault();
              var _this = this;

              // If all card validation has passed, process.
              if (isCardNumberComplete && isCVVComplete && isExpiryComplete) {
                this.customCheckout.createToken(
                  function (result) {
                    if (result.error) {
                      // Inform the user if there was an error.
                      bamboraErrorDisplay(result.error.message);
                    }
                    else {
                      // Send the token to your server.
                      bamboraTokenHandler(result.token);
                    }
                  }.bind(_this)
                );
              }
              // If we have errors in card validation, display them.
              else {
                var id = '';

                if (!isCardNumberComplete) {
                  id = '#card-number';
                }
                else if (!isExpiryComplete) {
                  id = '#card-expiry';
                }
                else if (!isCVVComplete) {
                  id = '#card-cvv';
                }

                bamboraGoToError(id);
              }
            }
          }
        };

        // Helper for displaying the error messages within the form.
        var bamboraErrorDisplay = function (error_message) {
          // Display the message error in the payment form.
          $form.find('#payment-errors').html(Drupal.theme('commerceBamboraError', error_message));
        };

        // Insert the token ID into the form so it gets submitted to the server.
        var bamboraTokenHandler = function (token) {
          // Set the Stripe token value.
          $('#bambora_token', $form).val(token);

          // Submit the form.
          $form.get(0).submit();
        };

        // Scroll to the error element so the user can see the problem.
        var bamboraGoToError = function(id) {
          if ($(id).length) {
            $(id).addClass('error');
            $(window).scrollTop($(id).position().top);
          }
        };

        paymentCardCtrl.init();
      });
    },

    /**
     * Detach behaviors.
     */
    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      var _this = this;
      ['cardNumber', 'cardExpiry', 'cardCvv'].forEach(function (i) {
        if (_this[i] && _this[i].length > 0) {
          _this[i].unmount();
          _this[i] = null;
        }
      });
      var $form = $('.bambora-form', context).closest('form');
      if ($form.length === 0) {
        return;
      }
      $form.off('submit.commerce_stripe');
    }
  };

  /**
   * @extends Drupal.theme.
   */
  $.extend(Drupal.theme, {
    commerceBamboraError: function (message) {
      return $('<div class="messages messages--error"></div>').html(message);
    }
  });

})(jQuery, Drupal, drupalSettings);
