<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useCheckoutStore, type AddressForm } from 'OOCS_LynxCheckout/stores/checkoutStore'

// The Lynx renderer of the `stripe_payments` method

type StripeJs = {
    registerAppInfo: (info: Record<string, unknown>) => void
    elements: (options: Record<string, unknown>) => StripeElements
    createConfirmationToken: (
        data: Record<string, unknown>
    ) => Promise<{ confirmationToken?: { id: string }; error?: { message?: string } }>
    retrievePaymentIntent: (clientSecret: string) => Promise<{ paymentIntent?: { status: string } }>
    retrieveSetupIntent: (clientSecret: string) => Promise<{ setupIntent?: { status: string } }>
    handleNextAction: (options: { clientSecret: string }) => Promise<unknown>
}
type StripeElements = {
    create: (type: string, options?: Record<string, unknown>) => StripeElement
    submit: () => Promise<{ error?: { message?: string } }>
    update: (options: Record<string, unknown>) => void
}
type StripeElement = {
    mount: (el: HTMLElement) => void
    unmount: () => void
    on: (event: string, handler: (event?: { error?: { message?: string } }) => void) => void
}

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        apiKey?: string
        appInfo?: Record<string, unknown> | null
        options?: Record<string, unknown>
        elementOptions?: Record<string, unknown>
        paymentElementOptions?: Record<string, unknown>
        amountMultiplier?: number
        urls?: Partial<{ requires_action: string; success: string }>
    }
}>()

const checkout = useCheckoutStore()
const { draft } = checkout

const mountEl = ref<HTMLElement | null>(null)
/** The gateway could not be reached or configured: the form never appears. */
const setupError = ref('')
// False until Stripe's iframe has rendered; a skeleton holds the space meanwhile.
const ready = ref(false)

let stripe: StripeJs | null = null
let elements: StripeElements | null = null
let paymentElement: StripeElement | null = null

// The module pins a beta API version (stripe_settings/api/version), served by this build of Stripe.js rather than /v3
const STRIPE_JS_SRC = 'https://js.stripe.com/dahlia/stripe.js'

/** Load Stripe.js once per page; concurrent renderers share the same script tag. */
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

onMounted(async () => {
    if (!props.config.apiKey) {
        setupError.value = props.config.labels?.unavailable ?? ''
        return
    }

    try {
        await loadStripeJs()
        const StripeCtor = (
            window as unknown as {
                Stripe: (key: string, options?: Record<string, unknown>) => StripeJs
            }
        ).Stripe

        stripe = StripeCtor(props.config.apiKey, props.config.options ?? {})
        if (props.config.appInfo) {
            try {
                stripe.registerAppInfo(props.config.appInfo)
            } catch {
                /* telemetry only */
            }
        }

        elements = stripe.elements(props.config.elementOptions ?? {})

        // As Luma builds them: the admin's layout/wallets/terms
        const billingForm = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr
        paymentElement = elements.create('payment', {
            ...(props.config.paymentElementOptions ?? {}),
            fields: {
                billingDetails: {
                    name: 'never',
                    email: 'never',
                    phone: 'auto',
                    address: {
                        line1: billingForm.street0 ? 'never' : 'auto',
                        line2: billingForm.street0 ? 'never' : 'auto',
                        city: billingForm.city ? 'never' : 'auto',
                        state: billingForm.region ? 'never' : 'auto',
                        country: billingForm.country_id ? 'never' : 'auto',
                        postalCode: billingForm.postcode ? 'never' : 'auto',
                    },
                },
            },
            defaultValues: {
                billingDetails: stripeDetails(billingForm, draft.email || undefined),
            },
        })
        paymentElement.on('ready', () => {
            ready.value = true
        })
        paymentElement.on('loaderror', event => {
            setupError.value = event?.error?.message || (props.config.labels?.unavailable ?? '')
        })
        paymentElement.mount(mountEl.value!)
    } catch {
        setupError.value = props.config.labels?.unavailable ?? ''
    }
})

onBeforeUnmount(() => paymentElement?.unmount())

// Deferred-intent Elements carry the amount themselves (BNPL texts, wallet sheets read it)
watch(
    () => checkout.grandTotal?.value,
    total => {
        if (!elements || typeof total !== 'number') return
        const options = props.config.elementOptions ?? {}
        if (options.mode === 'setup') return
        elements.update({ amount: Math.round(total * (props.config.amountMultiplier ?? 100)) })
    }
)

/** The draft address, in the shape Stripe's billing/shipping details take. */
function stripeDetails(form: AddressForm, email?: string) {
    const address: Record<string, string> = {}
    if (form.city) address.city = form.city
    if (form.country_id) address.country = form.country_id
    if (form.postcode) address.postal_code = form.postcode
    if (form.region) address.state = form.region
    if (form.street0) address.line1 = form.street0
    if (form.street1) address.line2 = form.street1

    const details: Record<string, unknown> = { address }
    const name = [form.firstname, form.lastname].filter(Boolean).join(' ')
    if (name) details.name = name
    if (form.telephone) details.phone = form.telephone
    if (email) details.email = email

    return details
}

defineExpose({
    /** Renderer contract: the Payment Element has fired `ready`. */
    loaded: ready,
    /**
     * Validate the element and mint the single-use confirmation token the order is placed with
     */
    async preparePayment(): Promise<Record<string, unknown>> {
        if (!stripe || !elements || !ready.value) {
            throw new Error(setupError.value || (props.config.labels?.stillLoading ?? ''))
        }

        const { error: submitError } = await elements.submit()
        if (submitError) throw new Error(submitError.message || (props.config.labels?.checkDetails ?? ''))

        const billingForm = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr
        const result = await stripe.createConfirmationToken({
            elements,
            params: {
                payment_method_data: {
                    // The module requires billing details on the token; allow_redisplay mirrors its Luma checkout
                    billing_details: stripeDetails(billingForm, draft.email || undefined),
                    allow_redisplay: 'always',
                },
                shipping: {
                    address: stripeDetails(draft.shippingAddr).address,
                    name: [draft.shippingAddr.firstname, draft.shippingAddr.lastname].filter(Boolean).join(' '),
                },
            },
        })
        if (result.error || !result.confirmationToken) {
            throw new Error(result.error?.message || (props.config.labels?.submitFailed ?? ''))
        }

        return { confirmation_token: result.confirmationToken.id }
    },

    /**
     * The order exists (pending payment); run whatever action the intent still asks of the customer
     */
    async afterPlaceOrder(): Promise<string | null> {
        const urls = props.config.urls ?? {}
        try {
            const response = await fetch(urls.requires_action ?? '/rest/V1/stripe/payments/get_requires_action', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            const clientSecret: string | null = await response.json()

            if (stripe && clientSecret) {
                const intent = clientSecret.startsWith('seti_')
                    ? (await stripe.retrieveSetupIntent(clientSecret)).setupIntent
                    : (await stripe.retrievePaymentIntent(clientSecret)).paymentIntent
                if (intent?.status === 'requires_action') {
                    await stripe.handleNextAction({ clientSecret })
                }
            }
        } catch {
            // The return controller is the judge of the payment either way.
        }

        return urls.success ?? null
    },
})
</script>

<template>
    <div class="pt-1">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>

        <template v-else>
            <div v-show="!ready" class="l-skeleton h-40" aria-hidden="true" />
            <div v-show="ready" ref="mountEl" />
        </template>
    </div>
</template>
