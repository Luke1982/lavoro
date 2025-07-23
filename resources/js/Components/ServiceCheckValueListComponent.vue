<template>
    <draggable class="" v-model="list" item-key="id" handle=".draghandle" :animation="200" @change="onChange">
        <ServiceCheckValueComponent v-for="(value, i) in list" :key="value.id" v-model:scValue="list[i]"
            class="w-full mb-2" @delete="onDelete" />
    </draggable>
</template>

<script setup>
import { defineModel } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ServiceCheckValueComponent from './ServiceCheckValueComponent.vue';
import { VueDraggableNext as draggable } from 'vue-draggable-next'

const { allServiceChecks, parentServiceCheckId } = defineProps({
    parentServiceCheckId: {
        type: Number,
        required: true
    },
    allServiceChecks: {
        type: Array,
        required: true
    }
});

const list = defineModel({
    type: Array,
    required: true
});

const onChange = () => {
    pruneNulls()
    reorderValues()
}

const pruneNulls = () => {
    const cleaned = list.value.filter(Boolean)
    if (cleaned.length !== list.value.length) list.value = cleaned
}

const updateServiceCheckValueForm = useForm({
    payload: []
})

const reorderValues = () => {
    const parentSC = allServiceChecks.find(sc => sc.id === parentServiceCheckId)
    if (!parentSC) {
        console.error('Error resorting values')
        return
    }
    updateServiceCheckValueForm.payload = parentSC.values.map((v, i) => ({ id: v.id, order: i }))
    updateServiceCheckValueForm.post(`/servicecheckvalues/reorder`, {
        preserveScroll: true,
    })
}

const onDelete = id => {
    list.value = list.value.filter(v => v.id !== id);
};
</script>