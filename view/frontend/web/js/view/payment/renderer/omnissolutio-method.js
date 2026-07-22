/**
 * Omnis Solutio checkout renderer (Phase D).
 *
 * Flow (order-first, mirrors WP checkout.js):
 *   1. Validate agreements / default validators.
 *   2. Place the Magento order (standard action) → order lands in pending_payment
 *      via the initialize gateway command (can_initialize=1).
 *   3. Ajax → Order/Create controller → {token, amount, currency}.
 *   4. Build the iframe URL (matches WP buildIframeUrl() exactly):
 *      checkoutAppUrl?token=…&amount=…&currency=…&baseUrl=…&tenantId=…&evTeam=…&evApp=…
 *   5. Open the iframe in a full-screen modal overlay.
 *   6. Listen for window.parent.postMessage({status:'success', payment_id:'…'})
 *      or {status:'cancelled'} from the checkout app.
 *   7. On success → POST payment_id to Callback controller → success page.
 *   8. On cancel/failure → re-enable the button; order stays pending_payment
 *      (Phase E webhook / Phase G cron will clean it up).
 */
define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Ui/js/model/messageList',
    'mage/url',
    'mage/translate'
], function ($, Component, additionalValidators, fullScreenLoader, messageList, url, $t) {
    'use strict';

    var modalEl      = null;
    var paymentBound = false;
    var onMessageRef = null;

    return Component.extend({
        defaults: {
            template: 'OmnisSolutio_PaymentGateway/payment/form',
            redirectAfterPlaceOrder: false
        },

        /** @returns {String} */
        getCode: function () {
            return 'omnissolutio_gateway';
        },

        /** @returns {Object} */
        getMethodConfig: function () {
            return window.checkoutConfig.payment[this.getCode()] || {};
        },

        getTitle: function () {
            return this.getMethodConfig().title || this._super();
        },

        getData: function () {
            return { method: this.getCode(), additional_data: {} };
        },

        /** Override Place Order to intercept before the standard form submit. */
        placeOrder: function (data, event) {
            var self = this;
            if (event) { event.preventDefault(); }

            if (!this.validate() || !additionalValidators.validate()) {
                return false;
            }

            this.isPlaceOrderActionAllowed(false);
            fullScreenLoader.startLoader();

            this.getPlaceOrderDeferredObject()
                .done(function () {
                    self.startGatewayPayment();
                })
                .fail(function () {
                    fullScreenLoader.stopLoader();
                    self.isPlaceOrderActionAllowed(true);
                });

            return true;
        },

        /** Step 3: fetch the session token from our Create controller. */
        startGatewayPayment: function () {
            var self = this;

            $.ajax({
                url:      this.getMethodConfig().createOrderUrl,
                type:     'POST',
                dataType: 'json',
                global:   false
            }).done(function (response) {
                if (!response || !response.success) {
                    self.handleFailure(response && response.message);
                    return;
                }
                self.openCheckout(response);
            }).fail(function () {
                self.handleFailure();
            });
        },

        /**
         * Step 4–6: build the iframe URL (matches WP's buildIframeUrl() exactly),
         * show the overlay modal, and wire the postMessage listener.
         */
        openCheckout: function (session) {
            var self   = this;
            var config = this.getMethodConfig();

            // Build URL matching WP's buildIframeUrl()
            var params = new URLSearchParams();
            params.set('token',    session.token);
            params.set('amount',   String(session.amount));
            params.set('currency', session.currency);
            if (config.baseUrl)        { params.set('baseUrl',  config.baseUrl); }
            if (config.tenantId)       { params.set('tenantId', config.tenantId); }
            if (config.evervaultTeamId) { params.set('evTeam',  config.evervaultTeamId); }
            if (config.evervaultAppId)  { params.set('evApp',   config.evervaultAppId); }

            var iframeSrc = config.checkoutAppUrl + '?' + params.toString();

            fullScreenLoader.stopLoader();
            this.openModal(iframeSrc);

            // Wire the postMessage listener (matches WP's window.addEventListener)
            if (onMessageRef) {
                window.removeEventListener('message', onMessageRef);
            }
            onMessageRef = function (event) {
                var data = event.data;
                if (!data || typeof data !== 'object') { return; }

                if (data.status === 'success' && data.payment_id) {
                    self.confirmPayment(data.payment_id);
                } else if (data.status === 'cancelled') {
                    self.handleFailure($t('Payment was cancelled. Please try again.'));
                }
            };
            window.addEventListener('message', onMessageRef);
        },

        openModal: function (iframeSrc) {
            this.closeModal();

            modalEl = $('<div id="omnissolutio-overlay">' +
                '<div id="omnissolutio-spinner"><div class="omnissolutio-ring"></div></div>' +
                '<iframe id="omnissolutio-iframe" src="" title="Omnis Solutio Secure Payment" allow="payment"></iframe>' +
                '<button id="omnissolutio-close" type="button" aria-label="Close">×</button>' +
                '</div>');

            $('body').append(modalEl);

            $('#omnissolutio-iframe').on('load', function () {
                $('#omnissolutio-spinner').hide();
                $(this).css('visibility', 'visible');
            });

            $('#omnissolutio-iframe').attr('src', iframeSrc);

            var self = this;
            $('#omnissolutio-close').on('click', function () {
                self.handleFailure($t('Payment was cancelled. Please try again.'));
            });
        },

        closeModal: function () {
            if (modalEl) {
                modalEl.remove();
                modalEl = null;
            }
        },

        /** Step 7: server-side confirmation via the Callback controller. */
        confirmPayment: function (paymentId) {
            var self = this;
            this.closeModal();
            if (onMessageRef) {
                window.removeEventListener('message', onMessageRef);
                onMessageRef = null;
            }

            fullScreenLoader.startLoader();

            $.ajax({
                url:      this.getMethodConfig().callbackUrl,
                type:     'POST',
                dataType: 'json',
                global:   false,
                data:     { payment_id: paymentId }
            }).done(function (response) {
                if (response && response.success) {
                    $.mage.redirect(url.build('checkout/onepage/success'));
                } else {
                    self.handleFailure(response && response.message);
                }
            }).fail(function () {
                self.handleFailure();
            });
        },

        /** Step 8: dismiss modal, re-enable button, show message. */
        handleFailure: function (message) {
            this.closeModal();
            if (onMessageRef) {
                window.removeEventListener('message', onMessageRef);
                onMessageRef = null;
            }
            fullScreenLoader.stopLoader();
            this.isPlaceOrderActionAllowed(true);
            messageList.addErrorMessage({
                message: message || $t('Something went wrong while processing your payment. Please try again.')
            });
        }
    });
});
