<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useCheckoutStore, type AddressForm } from 'OOCS_LynxCheckout/stores/checkoutStore'

// The Lynx renderer of the `stripe_payments_bank_transfers` method

type StripeJs = {
    registerAppInfo: (info: Record<string, unknown>) => void
    elements: (options: Record<string, unknown>) => StripeElements
    createPaymentMethod: (
        data: Record<string, unknown>
    ) => Promise<{ paymentMethod?: { id: string }; error?: { message?: string } }>
    retrievePaymentIntent: (clientSecret: string) => Promise<{ paymentIntent?: { status: string } }>
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

        // The billing fields the checkout has already collected are switched off inside the element ('never') and prefilled
        const billingForm = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr
        paymentElement = elements.create('payment', {
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

// The element carries the amount itself; keep it in step with the quote total.
watch(
    () => checkout.grandTotal?.value,
    total => {
        if (!elements || typeof total !== 'number') return
        elements.update({ amount: Math.round(total * (props.config.amountMultiplier ?? 100)) })
    }
)

/** The draft address, in the shape Stripe's billing details take. */
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
     * Validate the element and create the payment method the order is placed with
     */
    async preparePayment(): Promise<Record<string, unknown>> {
        if (!stripe || !elements || !ready.value) {
            throw new Error(setupError.value || (props.config.labels?.stillLoading ?? ''))
        }

        const { error: submitError } = await elements.submit()
        if (submitError) throw new Error(submitError.message || (props.config.labels?.checkDetails ?? ''))

        const billingForm = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr
        const result = await stripe.createPaymentMethod({
            elements,
            params: {
                billing_details: stripeDetails(billingForm, draft.email || undefined),
            },
        })
        if (result.error || !result.paymentMethod) {
            throw new Error(result.error?.message || (props.config.labels?.submitFailed ?? ''))
        }

        return { payment_method: result.paymentMethod.id }
    },

    /**
     * The order exists (pending payment); the intent's next action is display_bank_transfer_instructions
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
                const { paymentIntent } = await stripe.retrievePaymentIntent(clientSecret)
                if (paymentIntent?.status === 'requires_action') {
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
            <div v-show="!ready" class="l-skeleton h-24" aria-hidden="true" />
            <div v-show="ready" ref="mountEl" />
        </template>
    </div>
</template>
