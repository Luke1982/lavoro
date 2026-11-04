<template>
    <PanelSection title="e-Boekhouden">

        <p v-if="!eboekhouden.configured" class="text-sm text-slate-500">
            Niet ingesteld: zet EBOEKHOUDEN_USERNAME en de twee beveiligingscodes in .env.
        </p>

        <template v-else>
            <p class="mb-3 text-sm text-slate-500">
                <template v-if="eboekhouden.relation_code">
                    De facturen van deze klant worden geboekt op relatie
                    <strong class="text-slate-900">{{ eboekhouden.relation_code }}</strong>.
                    Klik op de gekoppelde relatie om hem los te maken.
                </template>
                <template v-else>
                    Nog niet gekoppeld. Zoek eerst of de klant er al staat, en klik op de juiste
                    relatie. Een nieuwe relatie wordt geweigerd als er al een is met hetzelfde
                    KvK-nummer, btw-nummer, factuur-e-mailadres of dezelfde naam.
                </template>
            </p>

            <form class="flex gap-2" @submit.prevent="search">
                <input v-model="form.term" type="text" class="panel-field flex-1"
                    aria-label="Zoeken in e-Boekhouden">
                <button type="submit" :disabled="form.processing" class="panel-button">Zoeken</button>
            </form>
            <p v-if="form.errors.term" class="mt-1 text-sm font-semibold text-red-700">{{ form.errors.term }}</p>

            <p v-if="eboekhouden.matches && !eboekhouden.matches.length" class="mt-3 text-sm text-slate-500">
                Niets gevonden voor &ldquo;{{ eboekhouden.term }}&rdquo;.
            </p>

            <ul v-if="rows.length" class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-300">
                <li v-for="relation in rows" :key="relation.code">
                    <button type="button" class="flex w-full items-baseline gap-3 px-3 py-2 text-left hover:bg-slate-50"
                        :class="{ 'bg-blue-50': isLinked(relation) }" @click="toggle(relation)">
                        <span class="font-mono text-sm">{{ relation.code }}</span>
                        <span class="flex-1">
                            {{ relation.name }}
                            <span class="text-sm text-slate-500">
                                {{ [relation.city, relation.email, relation.coc_number].filter(Boolean).join(' · ') }}
                            </span>
                        </span>
                        <span v-if="isLinked(relation)" class="text-sm font-semibold text-blue-700">gekoppeld</span>
                    </button>
                </li>
            </ul>

            <button v-if="!eboekhouden.relation_code && eboekhouden.matches" type="button"
                class="panel-button mt-3" @click="create">
                Nieuwe relatie aanmaken
            </button>
        </template>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    tenant: { type: Object, required: true },
    eboekhouden: { type: Object, required: true },
})

const form = useForm({ term: props.eboekhouden.term ?? props.tenant.name })

const search = () => form.post(`/beheer/${props.tenant.id}/e-boekhouden/zoeken`, {
    preserveScroll: true,
    preserveState: true,
})

/** De gekoppelde relatie staat er altijd tussen, ook zonder zoekopdracht: daar klik je hem los. */
const rows = computed(() => {
    const matches = props.eboekhouden.matches ?? []
    const code = props.eboekhouden.relation_code

    return code && !matches.some((relation) => relation.code === code)
        ? [{ code, name: '' }, ...matches]
        : matches
})

const isLinked = (relation) => relation.code === props.eboekhouden.relation_code

const url = `/beheer/${props.tenant.id}/e-boekhouden/relatie`

const toggle = (relation) => {
    if (isLinked(relation)) {
        if (confirm(`Relatie ${relation.code} loskoppelen? Boeken kan pas weer na een nieuwe koppeling.`)) {
            router.delete(url, { preserveScroll: true, preserveState: true })
        }

        return
    }

    if (props.eboekhouden.relation_code
        && !confirm(`Gekoppeld aan ${props.eboekhouden.relation_code}. Vervangen door ${relation.code}?`)) {
        return
    }

    router.post(url, { code: relation.code }, { preserveScroll: true, preserveState: true })
}

const create = () => {
    if (confirm(`Een nieuwe relatie voor ${props.tenant.name} aanmaken in e-Boekhouden?`)) {
        router.post(`${url}/nieuw`, {}, { preserveScroll: true, preserveState: true })
    }
}
</script>
