<script setup lang="ts">
import { onMounted, ref } from 'vue'

// The Lynx renderer of the `stripe_payments_checkout` method

type MethodIcon = { code: string; name?: string | null; path?: string | null }

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        cardIcons?: MethodIcon[]
        // Payment method type → its label and icon, for the types fetched below.
        pmIcons?: Record<string, { name?: string; icon?: string }>
        urls?: Partial<{ methods: string; session: string }>
    }
}>()

// The admin's card icons render immediately; the hosted page's other method types cost a Stripe round trip
const icons = ref<MethodIcon[]>([...(props.config.cardIcons ?? [])])

onMounted(async () => {
    try {
        const response = await fetch(
            props.config.urls?.methods ?? '/rest/V1/stripe/payments/get_checkout_payment_methods',
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                // The addresses are already on the quote by the payment step
                body: JSON.stringify({ billingAddress: {} }),
            }
        )
        // The endpoint answers with a serialized JSON string: {"methods": [...]}.
        const raw: unknown = await response.json()
        const data = typeof raw === 'string' ? (JSON.parse(raw) as { methods?: string[] }) : {}

        const pmIcons = props.config.pmIcons ?? {}
        for (const method of data.methods ?? []) {
            if (icons.value.some(icon => icon.code === method)) continue

            if (pmIcons[method]) {
                icons.value.push({ code: method, name: pmIcons[method].name, path: pmIcons[method].icon })
            } else if (method !== 'card') {
                icons.value.push({
                    code: method,
                    name: method
                        .split('_')
                        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                        .join(' '),
                    path: pmIcons.bank?.icon ?? null,
                })
            }
        }
    } catch {
        // Stripe unreachable: the card icons (or the plain wording) stand alone.
    }
})

defineExpose({
    /**
     * The order exists (pending payment): fetch the Stripe-hosted page URL and hand it to the checkout to redirect to
     */
    async afterPlaceOrder(): Promise<string | null> {
        try {
            const response = await fetch(
                props.config.urls?.session ?? '/rest/V1/stripe/payments/get_checkout_session_url',
                {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                }
            )
            const url: string | null = await response.json()
            return url || null
        } catch {
            return null
        }
    },
})
</script>

<template>
    <div class="pt-1">
        <template v-if="icons.length">
            <p class="text-sm text-base-content/80">
                {{ config.labels?.redirectWithMethods }}
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <img
                    v-for="icon in icons.filter(entry => entry.path)"
                    :key="icon.code"
                    :src="icon.path!"
                    :alt="icon.name ?? icon.code"
                    :title="icon.name ?? icon.code"
                    class="h-6 w-auto"
                />
            </div>
        </template>
        <p v-else class="text-sm text-base-content/80">
            {{ config.labels?.redirect }}
        </p>
    </div>
</template>
