<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentStripe\Model\Config;

use Magento\Framework\UrlInterface;
use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use StripeIntegration\Payments\Helper\Generic as StripeHelper;
use StripeIntegration\Payments\Helper\InitParams;
use StripeIntegration\Payments\Helper\Locale as LocaleHelper;
use StripeIntegration\Payments\Helper\Quote as QuoteHelper;

/**
 * Client config of the Stripe bank transfers renderer (Components/BankTransfers.vue)
 */
class BankTransfers implements PaymentMethodRendererConfigInterface
{
    /**
     * Init params, locale helper, quote helper, stripe helper, url builder
     *
     * @param InitParams $initParams
     * @param LocaleHelper $localeHelper
     * @param QuoteHelper $quoteHelper
     * @param StripeHelper $stripeHelper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly InitParams $initParams,
        private readonly LocaleHelper $localeHelper,
        private readonly QuoteHelper $quoteHelper,
        private readonly StripeHelper $stripeHelper,
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
        try {
            $init = $this->initParams->getStripeInitParams();
            $quote = $this->quoteHelper->getQuote();
        } catch (\Exception $e) {
            return ['apiKey' => '', 'labels' => $this->labels()];
        }

        $currency = (string) ($quote->getQuoteCurrencyCode() ?: 'USD');

        return [
            'apiKey'  => (string) ($init['apiKey'] ?? ''),
            'appInfo' => $init['appInfo'] ?? null,
            'options' => $init['options'] ?? [],
            'elementOptions' => [
                'mode'                  => 'payment',
                'locale'                => $this->localeHelper->getStripeJsLocale(),
                'paymentMethodCreation' => 'manual',
                'currency'              => strtolower($currency),
                'amount'                => $this->stripeHelper->convertMagentoAmountToStripeAmount(
                    (float) $quote->getGrandTotal(),
                    $currency
                ),
                'appearance' => [
                    'theme' => 'stripe',
                    'variables' => [
                        'colorText'  => '#32325d',
                        'fontFamily' => '"Open Sans","Helvetica Neue", Helvetica, Arial, sans-serif',
                    ],
                ],
                'payment_method_types' => ['customer_balance'],
            ],
            // Stripe minor units per one currency unit, for the renderer's amount updates when the totals change
            'amountMultiplier' => $this->stripeHelper->convertMagentoAmountToStripeAmount(1, $currency),
            'urls' => [
                // Answers with the intent's client secret while the just-placed order still asks something of the
                'requires_action' => $this->urlBuilder->getBaseUrl() . 'rest/V1/stripe/payments/get_requires_action',
                'success' => $this->urlBuilder->getUrl('stripe/payment/index'),
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
