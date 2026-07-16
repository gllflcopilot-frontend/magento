define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'omnissolutio_gateway',
        component: 'OmnisSolutio_PaymentGateway/js/view/payment/renderer/omnissolutio-method'
    });

    return Component.extend({});
});
