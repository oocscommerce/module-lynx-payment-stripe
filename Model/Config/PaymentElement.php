<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentStripe\Model\Config;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use StripeIntegration\Payments\Helper\Generic as StripeHelper;
use StripeIntegration\Payments\Helper\InitParams;

/**
 * Client config of the Stripe Payment Element renderer (Components/PaymentElement.vue)
 */
class PaymentElement implements PaymentMethodRendererConfigInterface
{
    /**
     * Init params, stripe helper, json, url builder
     *
     * @param InitParams $initParams
     * @param StripeHelper $stripeHelper
     * @param Json $json
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly InitParams $initParams,
        private readonly StripeHelper $stripeHelper,
        private readonly Json $json,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        // Both builders talk to Stripe (terms resolution, saved-card customer sessions)
        try {
            $params = $this->json->unserialize($this->initParams->getCheckoutParams());
            $elementOptions = $this->initParams->getElementOptions();
        } catch (\Exception $e) {
            return ['apiKey' => '', 'labels' => $this->labels()];
        }

        $currency = isset($elementOptions['currency']) ? (string) $elementOptions['currency'] : '';

        // The create('payment', …) options, as Luma passes them
        $paymentElementOptions = array_filter([
            'layout'  => $params['layout'] ?? null,
            'wallets' => $params['wallets'] ?? null,
            'terms'   => $params['terms'] ?? null,
        ], fn ($value) => $value !== null);

        return [
            'apiKey'                => (string) ($params['apiKey'] ?? ''),
            'appInfo'               => $params['appInfo'] ?? null,
            'options'               => $params['options'] ?? [],
            'elementOptions'        => $elementOptions,
            'paymentElementOptions' => $paymentElementOptions,
            // Stripe minor units per one currency unit (100 for USD, 1 for JPY…)
            'amountMultiplier' => $currency
                ? $this->stripeHelper->convertMagentoAmountToStripeAmount(1, $currency)
                : 100,
            'urls' => [
                // Answers with the intent's client secret while the just-placed order still needs a customer action
                'requires_action' => $this->urlBuilder->getBaseUrl() . 'rest/V1/stripe/payments/get_requires_action',
                // The module's return controller: verifies the payment outcome and routes to checkout success
                'success' => (string) ($params['successUrl'] ?? $this->urlBuilder->getUrl('stripe/payment/index')),
            ],
            // Every string the renderer shows, translated here
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
            'unavailable'        => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'stillLoading'       => (string) __('The payment form is still loading. Please try again.'),
            'checkDetails'       => (string) __('Transaction has been declined. Please try again later.'),
            'submitFailed'       => (string) __('Error processing payment. Please try again later.'),
        ];
    }
}
