<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// The Stripe express wallets, the node an admin puts in the checkout's `express` slot above the steps

type StripeJs = {
    registerAppInfo: (info: Record<string, unknown>) => void
    elements: (options: Record<string, unknown>) => StripeElements
    createConfirmationToken: (
        data: Record<string, unknown>
    ) => Promise<{ confirmationToken?: { id: string }; error?: { message?: string } }>
    handleNextAction: (options: { clientSecret: string }) => Promise<{ error?: { message?: string } }>
}
type StripeElements = {
    create: (type: string, options?: Record<string, unknown>) => StripeElement
    submit: () => Promise<{ error?: { message?: string } }>
    update: (options: Record<string, unknown>) => Promise<unknown>
}
type StripeElement = {
    mount: (el: HTMLElement) => void
    unmount: () => void
    on: (event: string, handler: (event: EceEvent) => void) => void
}

/**
 * What the module's service answers every call with
 */
type EceResponse = {
    elementOptions?: Record<string, unknown> & { mode?: string }
    resolvePayload?: {
        lineItems?: { amount: number }[]
        shippingRates?: unknown[]
    }
    expressCheckoutOptions?: Record<string, unknown> & {
        allowedShippingCountries?: Record<string, string> | string[]
    }
}

/** The wallet's own events. Only what is read here is typed. */
type EceEvent = {
    resolve: (payload?: unknown) => void
    reject: () => void
    availablePaymentMethods?: unknown
    address?: { city?: string; region?: string }
    shippingRate?: { id?: string }
    billingDetails?: unknown
    shippingAddress?: unknown
    isClick?: boolean
}

const props = withDefaults(defineProps<Lynx.StripeExpressWallets>(), {
    enabled: false,
    labels: () => ({}),
    apiKey: '',
    appInfo: null,
    options: () => ({}),
    locale: '',
    buttonOptions: () => ({}),
    location: 'checkout',
    urls: () => ({}),
})

const checkout = useCheckoutStore()
const messages = useMessagesStore()

const mountEl = ref<HTMLElement | null>(null)
const setupError = ref('')
// False until the element reports itself ready with at least one wallet the browser can offer
const ready = ref(false)
// The browser offers none of the wallets the admin switched on
const available = ref(true)

// The module pins a beta API version, served by this build of Stripe.js rather than /v3
const STRIPE_JS_SRC = 'https://js.stripe.com/dahlia/stripe.js'

let stripe: StripeJs | null = null
let elements: StripeElements | null = null
let element: StripeElement | null = null

// Deferred-intent mode has no amount to update; 'setup' says so.
let mode = ''
// What a click resolves with, as ece_params answered
let clickPayload: unknown = null
// The address the wallet last supplied, which the rate call is asked against.
let shippingAddress: unknown = null

/** Load Stripe.js once per page; concurrent components share the same script tag. */
function loadStripeJs(): Promise<void> {
    const w = window as unknown as { Stripe?: unknown }
    if (w.Stripe) return Promise.resolve()

    return new Promise((resolve, reject) => {
        let script = document.querySelector<HTMLScriptElement>(`script[src="${STRIPE_JS_SRC}"]`)
        if (!script) {
            script = document.createElement('script')
            script.src = STRIPE_JS_SRC
            document.head.appendChild(script)
        }
        script.addEventListener('load', () => resolve())
        script.addEventListener('error', () => reject(new Error('Stripe.js failed to load')))
    })
}

/** POST to one of the module's REST endpoints, which act on the session quote. */
async function post(url: string, body: unknown): Promise<EceResponse> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    })

    // The service answers a JSON string on some routes and an object on others
    const data = await response.json()
    const parsed = (typeof data === 'string' ? JSON.parse(data) : data) as EceResponse & { message?: string }
    if (!response.ok) throw new Error(parsed?.message ?? props.labels.paymentFailed ?? '')
    return parsed
}

/**
 * Hand a payload back to the wallet, repricing the element first when the totals it carries have changed
 */
async function resolveEvent(event: EceEvent, payload: EceResponse['resolvePayload']) {
    const lineItems = payload?.lineItems ?? []
    const total = lineItems.reduce((sum, item) => sum + item.amount, 0)

    if (lineItems.length && !event.isClick && mode !== 'setup' && total > 0) {
        try {
            await elements!.update({ amount: total })
        } catch {
            // Repricing failed; the wallet is still shown the payload, and the order is placed against the quote either way
        }
    }

    event.resolve(payload)
}

/** The wallet supplied an address, or the shopper picked another rate. */
async function onShippingChange(event: EceEvent, url: string, body: unknown) {
    try {
        const result = await post(url, body)
        // No rates for this address: the wallet is told so, and keeps the sheet open.
        if (!result.resolvePayload?.shippingRates) {
            messages.add('error', props.labels.noRates ?? '')
            event.reject()
            return
        }
        await resolveEvent(event, result.resolvePayload)
    } catch (error) {
        messages.add('error', (error as Error).message)
        event.reject()
    }
}

/**
 * The shopper approved in the wallet
 */
async function onConfirm(event: EceEvent) {
    try {
        const submitted = await elements!.submit()
        if (submitted.error) throw new Error(submitted.error.message ?? props.labels.paymentFailed ?? '')

        const token = await stripe!.createConfirmationToken({
            elements,
            params: {
                billing_details: event.billingDetails,
                payment_method_data: { allow_redisplay: 'always' },
            },
            shipping: event.shippingAddress,
        })
        if (token.error || !token.confirmationToken) {
            throw new Error(token.error?.message ?? props.labels.paymentFailed ?? '')
        }

        const result = (await post(props.urls.place_order!, {
            result: { ...event, confirmationToken: token.confirmationToken },
            location: props.location,
        })) as EceResponse & { client_secret?: string; redirect?: string }

        // 3D Secure, or a redirect method: run it before following the answer
        if (result.client_secret) {
            const action = await stripe!.handleNextAction({ clientSecret: result.client_secret })
            if (action?.error) throw new Error(action.error.message ?? props.labels.paymentFailed ?? '')
        }

        if (!result.redirect) throw new Error(props.labels.paymentFailed ?? '')

        // The order consumed the quote: the cached cart section and the draft are stale.
        await checkout.orderPlaced()
        window.location.assign(result.redirect)
    } catch (error) {
        messages.add('error', (error as Error).message || (props.labels.paymentFailed ?? ''))
    }
}

onMounted(async () => {
    if (!props.enabled) return

    if (!props.apiKey || !props.urls.params) {
        setupError.value = props.labels.unavailable ?? ''
        return
    }

    try {
        await loadStripeJs()
        const StripeCtor = (
            window as unknown as {
                Stripe: (key: string, options?: Record<string, unknown>) => StripeJs
            }
        ).Stripe

        stripe = StripeCtor(props.apiKey, props.options)
        if (props.appInfo) {
            try {
                stripe.registerAppInfo(props.appInfo)
            } catch {
                /* telemetry only */
            }
        }

        const params = await post(props.urls.params, { location: props.location })
        if (!params.elementOptions) {
            available.value = false
            return
        }

        mode = String(params.elementOptions.mode ?? '')
        clickPayload = params.resolvePayload ?? null

        elements = stripe.elements({
            ...params.elementOptions,
            ...(props.locale ? { locale: props.locale } : {}),
        })

        // The wallet options the service answers with ride over the admin's
        const walletOptions = { ...(params.expressCheckoutOptions ?? {}) }
        const countries = walletOptions.allowedShippingCountries
        if (countries && !Array.isArray(countries)) {
            walletOptions.allowedShippingCountries = Object.values(countries)
        }

        element = elements.create('expressCheckout', { ...props.buttonOptions, ...walletOptions })

        element.on('ready', (event: EceEvent) => {
            // No wallet this browser can offer: nothing to show.
            if (!event.availablePaymentMethods) {
                available.value = false
                return
            }
            ready.value = true
        })

        // The express card closes the buttons until the terms are accepted
        element.on('click', (event: EceEvent) => {
            if (!checkout.termsAccepted) {
                messages.add('error', props.labels.acceptTerms ?? '')
                event.reject()
                return
            }
            event.isClick = true
            resolveEvent(event, clickPayload as EceResponse['resolvePayload'])
        })

        element.on('shippingaddresschange', (event: EceEvent) => {
            // Some countries come back with a region and no city
            const address = { ...(event.address ?? {}) }
            if (!address.city && address.region) address.city = address.region
            shippingAddress = address

            onShippingChange(event, props.urls.shipping_address!, {
                newAddress: address,
                location: props.location,
            })
        })

        element.on('shippingratechange', (event: EceEvent) => {
            onShippingChange(event, props.urls.shipping_rate!, {
                address: shippingAddress,
                shipping_id: event.shippingRate?.id ?? null,
            })
        })

        element.on('confirm', onConfirm)

        element.mount(mountEl.value!)
    } catch {
        setupError.value = props.labels.loadFailed ?? ''
    }
})

onBeforeUnmount(() => element?.unmount())
</script>

<template>
    <div v-if="enabled && available">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <template v-else>
            <!-- Skeleton the shape of the buttons until Stripe reports the element ready. -->
            <div v-if="!ready" class="l-skeleton h-12 rounded-button" aria-hidden="true"></div>
            <!-- v-show, not v-if: the container must stay in the DOM for Stripe to mount into. -->
            <div v-show="ready" ref="mountEl"></div>
        </template>
    </div>
</template>
