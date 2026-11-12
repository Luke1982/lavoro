<template>
    <BoxComponent padding="p-4 sm:p-5">
        <SectionHeader :icon="icon" :title="title" :chapter="chapter" />
        <SegmentedToggle v-model="audience" :options="options" class="mb-4" />
        <slot :internal="audience === 'internal'" />
    </BoxComponent>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Lock, Users } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'

/**
 * Een blok met twee kanten: wat alleen voor collega's is, en wat de klant op de
 * werkbon terugziet. Eén kant tegelijk, met het aantal ernaast, zodat je ziet
 * dat er aan de andere kant ook iets staat.
 */
const props = defineProps({
    title: { type: String, required: true },
    icon: { type: [Object, Function], required: true },
    chapter: { type: String, default: '' },
    internalCount: { type: Number, default: 0 },
    externalCount: { type: Number, default: 0 },
    initial: { type: String, default: 'internal' },
})

const audience = ref(props.initial)

const options = computed(() => [
    { value: 'internal', label: 'Intern', icon: Lock, count: props.internalCount },
    { value: 'external', label: 'Extern (klant)', icon: Users, count: props.externalCount },
])
</script>
