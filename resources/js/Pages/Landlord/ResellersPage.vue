<template>
    <Head title="Resellers" />

    <h2 class="mb-1 text-xl font-semibold">Resellers</h2>
    <p class="mb-5 text-sm text-slate-500">
        Wie klanten aanbrengt, met welke commissie, en de kortingsbonnen die daarbij horen.
    </p>

    <div class="grid gap-5">
        <NewResellerForm />

        <ResellerCard v-for="row in rows" :key="row.reseller.id" :row="row" />

        <p v-if="!rows.length" class="text-slate-500">Nog geen resellers.</p>
    </div>
</template>

<script setup>
import { Head } from '@inertiajs/vue3'
import NewResellerForm from '@/Components/Landlord/NewResellerForm.vue'
import ResellerCard from '@/Components/Landlord/ResellerCard.vue'

defineProps({
    rows: { type: Array, required: true },
})
</script>
