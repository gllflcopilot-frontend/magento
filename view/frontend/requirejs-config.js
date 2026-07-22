/**
 * The hosted checkout SDK (checkout.js) is loaded remotely at runtime and exposes
 * a global `OmnisSolutio`. Its concrete URL is env-specific (sandbox vs live) and
 * comes from window.checkoutConfig, so the renderer configures the actual `paths`
 * entry at runtime; here we only declare the global-export shim.
 */
var config = {
    shim: {
        omnissolutioSdk: {
            exports: 'OmnisSolutio'
        }
    }
};
