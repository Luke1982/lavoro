<template>
    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mt-0 mb-3 text-base font-semibold">Coupon</h3>

        <template v-if="tenant.coupon_discount_percent">
            <p>
                {{ tenant.coupon_discount_percent }}% korting tot {{ until }}
                <template v-if="reseller">
                    &middot; via {{ reseller.name }} ({{ reseller.commission_percent }}% commissie)
                </template>
            </p>
            <p class="text-sm text-slate-500">
                Deze maand commissie: {{ euro(subscription.commission_cents) }}
            </p>
        </template>

        <form v-else @submit.prevent="submit">
            <label class="mb-1 block font-semibold">Couponcode</label>
            <div class="flex gap-2">
                <input v-model="form.code" type="text" placeholder="ZOMER2026"
                    class="w-56 rounded-md border border-slate-300 px-3 py-2">
                <button type="submit" :disabled="form.processing"
                    class="rounded-md bg-blue-700 px-4 py-2 text-white disabled:opacity-60">Verzilveren</button>
            </div>
            <p v-if="form.errors.code" class="mt-1 font-semibold text-red-700">{{ form.errors.code }}</p>
        </form>
    </section>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { euro } from './money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    reseller: { type: Object, default: null },
    subscription: { type: Object, required: true },
})

const until = computed(() => (props.tenant.coupon_discount_until
    ? new Date(props.tenant.coupon_discount_until).toLocaleDateString('nl-NL')
    : ''))

const form = useForm({ code: '' })

const submit = () => form.post(`/beheer/${props.tenant.id}/coupon`, { preserveScroll: true })
</script>
