<template>
    <PanelSection title="Coupon">

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
                    class="panel-field w-56">
                <button type="submit" :disabled="form.processing"
                    class="panel-button">Verzilveren</button>
            </div>
            <p v-if="form.errors.code" class="mt-1 font-semibold text-red-700">{{ form.errors.code }}</p>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
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
