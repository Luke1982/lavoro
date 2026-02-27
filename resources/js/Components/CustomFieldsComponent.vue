<template>
    <div v-if="fields.length">
        <h2 class="text-base font-semibold dark:text-slate-200 mb-3">Extra velden</h2>
        <div class="flex flex-wrap gap-y-3">
            <div v-for="field in fields" :key="field.id" class="w-full md:w-1/2 flex">
                <div class="w-1/3 text-xs">{{ field.name }}</div>
                <div :class="[editingField === field.id ? '' : 'pr-5', 'w-2/3 relative']">
                    <span v-if="editingField !== field.id" class="pr-4">{{ displayValue(field) }}</span>

                    <div class="flex min-w-0" v-if="editingField === field.id">
                        <TextInput v-if="field.field_type === 'text'" v-model="localValues[field.id]"
                            :rightCorners="false" class="flex-grow min-w-0" />
                        <TextInput v-else-if="field.field_type === 'number'" v-model="localValues[field.id]"
                            :rightCorners="false" class="flex-grow min-w-0" />
                        <TextInput v-else-if="field.field_type === 'date'" v-model="localValues[field.id]"
                            :rightCorners="false" class="flex-grow min-w-0" type="date" />
                        <SwitchComponent v-else-if="field.field_type === 'boolean'"
                            v-model="localValues[field.id]" />
                        <ComboBox v-else-if="field.field_type === 'select'"
                            :options="(field.options || []).map(o => ({ id: o, name: o }))"
                            v-model="localValues[field.id]" :initialId="localValues[field.id]" emitValue
                            class="flex-grow min-w-0" />
                        <textarea v-else-if="field.field_type === 'textarea'" v-model="localValues[field.id]" rows="3"
                            class="flex-grow min-w-0 rounded-l-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm p-2"></textarea>
                        <button v-if="field.field_type !== 'boolean'"
                            @click="saveField(field.id)" :disabled="saving"
                            class="px-3 py-1 bg-green-600 text-white rounded-r cursor-pointer hover:bg-green-700">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5" fill="currentColor">
                                <path d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
                            </svg>
                        </button>
                    </div>

                    <PencilSquareIcon v-if="editingField !== field.id && canEdit"
                        class="size-5 text-gray-600 dark:text-gray-300 absolute right-0 top-2 transform -translate-y-1/2 cursor-pointer"
                        @click="startEdit(field)" />
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { computed, ref, reactive, watch } from 'vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'

const props = defineProps({
    modelType: {
        type: String,
        required: true,
    },
    modelId: {
        type: Number,
        required: true,
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
})

const fields = computed(() => props.customFields)
const editingField = ref(null)
const saving = ref(false)

const localValues = reactive({})
for (const field of props.customFields) {
    localValues[field.id] = field.pivot?.value ?? ''
}

function displayValue(field) {
    const raw = field.pivot?.value
    if (raw === null || raw === undefined || raw === '') return '—'
    if (field.field_type === 'boolean') return raw === '1' || raw === 'true' ? 'Ja' : 'Nee'
    return raw
}

function startEdit(field) {
    localValues[field.id] = field.pivot?.value ?? ''
    editingField.value = field.id
}

function saveField(field_id) {
    saving.value = true
    router.post('/customfields/values', {
        model_type: props.modelType,
        model_id: props.modelId,
        values: { [field_id]: localValues[field_id] },
    }, {
        preserveScroll: true,
        onSuccess: () => {
            saving.value = false
            editingField.value = null
        },
        onError: () => {
            saving.value = false
            usePage().props.flash.error = usePage().props.errors
        },
    })
}

watch(() => props.customFields, (updated) => {
    for (const field of updated) {
        if (field.field_type === 'boolean' && editingField.value !== field.id) {
            localValues[field.id] = field.pivot?.value ?? ''
        }
    }
}, { deep: true })

watch(localValues, () => {
    if (editingField.value === null) return
    const field = props.customFields.find(f => f.id === editingField.value)
    if (field && field.field_type === 'boolean') {
        saveField(field.id)
    }
})
</script>
