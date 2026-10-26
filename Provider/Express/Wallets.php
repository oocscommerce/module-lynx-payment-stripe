<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentStripe\Provider\Express;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use OOCS\LynxComponent\Model\AbstractComponentProvider;
use StripeIntegration\Payments\Helper\InitParams;
use StripeIntegration\Payments\Model\Config as StripeConfig;
use StripeIntegration\Payments\Model\ExpressCheckout\Config as ExpressConfig;

/**
 * Feeds the Stripe express wallets (Components/Express/Wallets.vue)
 */
class Wallets extends AbstractComponentProvider
{
    /** The location as the admin's "Enable on" list spells it. */
    private const CONFIG_LOCATION = 'checkout_page';

    /** The same place, as the module's REST service spells it. */
    private const SERVICE_LOCATION = 'checkout';

    /**
     * Express config, stripe config, init params, json, url builder
     *
     * @param ExpressConfig $expressConfig
     * @param StripeConfig $stripeConfig
     * @param InitParams $initParams
     * @param Json $json
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly ExpressConfig $expressConfig,
        private readonly StripeConfig $stripeConfig,
        private readonly InitParams $initParams,
        private readonly Json $json,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Resolve
     *
     * @param array $props
     * @return array
     */
    public function resolve(array $props): array
    {
        // Two questions, because the module's express config answers only the second
        $props['enabled'] = $this->stripeConfig->isEnabled()
            && $this->expressConfig->isEnabled(self::CONFIG_LOCATION);

        if (!$props['enabled']) {
            return $props;
        }

        // Talks to Stripe when it resolves the publishable key and the client betas
        try {
            $params = $this->json->unserialize($this->initParams->getWalletParams());
        } catch (\Exception $e) {
            return ['enabled' => false] + $props;
        }

        $rest = $this->urlBuilder->getBaseUrl() . 'rest/V1/stripe/payments/';

        return $props + [
            'apiKey'        => (string) ($params['apiKey'] ?? ''),
            'appInfo'       => $params['appInfo'] ?? null,
            'options'       => $params['options'] ?? [],
            'locale'        => (string) ($params['locale'] ?? ''),
            // create('expressCheckout', …): which wallets, how tall, which theme and type each one uses
            'buttonOptions' => $this->expressConfig->getButtonOptions(),
            'location'      => self::SERVICE_LOCATION,
            'urls' => [
                // The element's options and the payload its click resolves with.
                'params' => $rest . 'ece_params',
                // A wallet supplied an address: answers with the rates for it.
                'shipping_address' => $rest . 'ece_shipping_address_changed',
                // The shopper picked a rate: answers with the totals it produces.
                'shipping_rate' => $rest . 'ece_shipping_rate_changed',
                // Places the order from the confirmation token, and answers with where to go
                'place_order' => $rest . 'place_order',
            ],
            // Every string the component shows, translated here
            'labels' => $this->labels(),
        ];
    }

    /**
     * Labels
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'   => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'loadFailed'    => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed' => (string) __('Error processing payment. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'noRates'       => (string) __('There are no shipping methods available for you right now. Please try again or use an alternative payment method.'),
            'acceptTerms'   => (string) __('Please agree to all Terms and Conditions before placing the order.'),
        ];
    }
}
