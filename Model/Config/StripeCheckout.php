<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentStripe\Model\Config;

use Magento\Framework\UrlInterface;
use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use StripeIntegration\Payments\Helper\PaymentMethod as PaymentMethodHelper;
use StripeIntegration\Payments\Model\Ui\ConfigProvider;

/**
 * Client config of the Stripe Checkout renderer (Components/StripeCheckout.vue)
 */
class StripeCheckout implements PaymentMethodRendererConfigInterface
{
    /**
     * Config provider, payment method helper, url builder
     *
     * @param ConfigProvider $configProvider
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
        private readonly PaymentMethodHelper $paymentMethodHelper,
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
        return [
            // The admin's card icons ({code, name, path}), shown immediately.
            'cardIcons' => $this->configProvider->getIcons(),
            // Payment method type → {name, icon}, the map Luma resolves fetched types against
            'pmIcons' => $this->paymentMethodHelper->getPaymentMethodDetails(),
            'urls' => [
                // Answers with the payment method types of a dummy Checkout Session for the quote
                'methods' => $this->urlBuilder->getBaseUrl() . 'rest/V1/stripe/payments/get_checkout_payment_methods',
                // Answers with the Stripe-hosted payment page URL once an order has been placed against the quote's
                'session' => $this->urlBuilder->getBaseUrl() . 'rest/V1/stripe/payments/get_checkout_session_url',
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
            'redirectWithMethods' => (string) __('You will be redirected to the payment system website.'),
            'redirect'           => (string) __('You will be redirected to the payment system website.'),
        ];
    }
}
