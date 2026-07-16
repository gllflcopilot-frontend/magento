define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'OmnisSolutio_PaymentGateway/payment/form',
            redirectAfterPlaceOrder: false
        },

        getCode: function () {
            return 'omnissolutio_gateway';
        },

        isActive: function () {
            return this.getCode() === this.isChecked();
        },

        getData: function () {
            return {
                method: this.getCode(),
                additional_data: {}
            };
        }
    });
});
