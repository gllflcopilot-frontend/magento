# OmnisSolutio Payment Gateway for Magento 2

A Magento 2 payment gateway extension for Omnis Solutio.

## Requirements

- Magento 2.4.x
- PHP 8.3 or 8.4

## Installation

```bash
composer require omnissolutio/payment-gateway
bin/magento module:enable OmnisSolutio_PaymentGateway
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

1. Go to **Stores → Configuration → Sales → Payment Methods**
2. Expand **Omnis Solutio Payment Gateway**
3. Set **Enabled** to Yes
4. Enter your **API Key** and **API Secret** (from your Omnis Solutio dashboard)
5. Set **Sandbox Mode** to Yes for testing, No for production
6. Save configuration

## Development (path repository)

To develop this module against a local Magento install without copy-pasting files, add a path repository to your Magento `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "/absolute/path/to/omnis-solutio-payment-gateway",
        "options": { "symlink": true }
    }
]
```

Then install with:

```bash
bin/composer require omnissolutio/payment-gateway:@dev
```

Edits in your local repo are instantly live in the Magento install via symlink.

## License

MIT
