# Lynx Payment Stripe

`oocscommerce/module-lynx-payment-stripe`

Stripe payment methods on the Lynx checkout.

## Features

- Stripe Payment Element, Stripe Checkout and bank transfers
- Express Checkout Element on the checkout page: Apple Pay, Google Pay, Link, PayPal, Amazon Pay and Klarna, as set in the Stripe express configuration

## Installation

An add-on of [Lynx](https://docs.oocscommerce.com/lynx/), not part of `oocscommerce/magento2-lynx-community`. Requires `stripe/module-payments`.

```bash
composer require oocscommerce/module-lynx-payment-stripe
bin/magento setup:upgrade
```

See [Installation](https://docs.oocscommerce.com/lynx/developer/installation) for the full setup.

## Dependencies

- [`oocscommerce/module-lynx-checkout`](https://github.com/oocscommerce/module-lynx-checkout)
- [`oocscommerce/module-lynx-component`](https://github.com/oocscommerce/module-lynx-component)

## Documentation

https://docs.oocscommerce.com/lynx/

Building on Lynx with Claude Code: the [Lynx skills](https://github.com/oocscommerce/claude-skills) teach it to write components, providers, checkout methods and more.

## Community

Questions, ideas and help: join [Lynx on Slack](https://join.slack.com/t/lynxmagento/shared_invite/zt-4dd3wdz3k-DILaGAowU_p3nevNLJ1gbw).

## License

[OSL-3.0](LICENSE.txt) or [AFL-3.0](LICENSE_AFL.txt).
