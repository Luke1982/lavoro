<template>
    <div v-auto-animate>
        <div v-if="!open && !externalTrigger" @click="open = true"
            class="border border-green-900 text-green-900 bg-green-100 text-sm p-2 rounded-md cursor-pointer inline-flex items-center">
            <PlusCircleIcon class="h-5 w-5 mr-1" />
            {{ addButtonLabel }}
        </div>

        <div v-if="open" :class="columnsClass">
            <template v-for="field in fields" :key="field.key">
                <div v-if="field.type === 'text' || field.type === 'number' || field.type === 'date'"
                    :class="['col-span-1', field.class]">
                    <TextInput v-model="form[field.key]" :label="field.label" :type="field.type"
                        :placeholder="field.placeholder || ''" :hasError="Boolean(form.errors[field.key])"
                        :errorMessage="form.errors[field.key]" />
                </div>

                <div v-else-if="field.type === 'textarea'" :class="['col-span-1', field.class]">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">{{ field.label
                        }}</label>
                    <textarea v-model="form[field.key]" :rows="field.rows || 3"
                        class="mt-2 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm p-2"
                        :placeholder="field.placeholder || ''"></textarea>
                    <p v-if="form.errors[field.key]" class="text-red-600 text-sm">{{ form.errors[field.key] }}</p>
                </div>

                <div v-else-if="field.type === 'combobox'" :class="['col-span-1', field.class]">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">{{ field.label
                    }}</label>
                    <ComboBox class="mt-2" :options="field.options || []" v-model="form[field.key]"
                        :placeholder="field.placeholder || ''" :initialId="field.initialId"
                        :initialIds="field.initialIds" :multiple="field.multiple === true" />
                    <p v-if="form.errors[field.key]" class="text-red-600 text-sm">{{ form.errors[field.key] }}</p>
                </div>

                <div v-else-if="field.type === 'boolean'" :class="['col-span-1 flex flex-col', field.class]">
                    <label class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300">{{ field.label
                    }}</label>
                    <div class="mt-2 h-9 flex items-center">
                        <SwitchComponent v-model="form[field.key]" />
                    </div>
                    <p v-if="form.errors[field.key]" class="text-red-600 text-sm mt-1">{{ form.errors[field.key] }}</p>
                </div>

                <div v-else-if="field.type === 'file'" :class="['col-span-1 flex flex-col', field.class]">
                    <label class="block text-sm font-medium leading-6 text-gray-900">{{ field.label }}</label>
                    <input type="file" class="mt-2 text-sm" @change="e => form[field.key] = e.target.files[0]" />
                    <p v-if="form.errors[field.key]" class="text-red-600 text-sm mt-1">{{ form.errors[field.key] }}</p>
                </div>
            </template>

            <div class="absolute bottom-2 right-6">
                <button @click="submit"
                    class="inline-flex items-center px-4 py-2 ml-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    {{ submitLabel }}
                </button>
            </div>
            <XCircleIcon class="absolute top-2 right-2 h-8 w-8 text-gray-400 cursor-pointer" @click="close" />
        </div>
    </div>
</template>
<script setup>
import { PlusCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import { useForm } from '@inertiajs/vue3'
import { ref, watchEffect, computed } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const props = defineProps({
    action: { type: String, required: true },
    fields: { type: Array, required: true },
    addButtonLabel: { type: String, default: 'Voeg toe' },
    submitLabel: { type: String, default: 'Opslaan' },
    externalTrigger: { type: Boolean, default: false },
})

const emit = defineEmits(['closed'])

const open = ref(false)

const initialState = () => {
    const state = {}
    for (const f of props.fields) {
        switch (f.type) {
            case 'boolean':
                state[f.key] = Object.prototype.hasOwnProperty.call(f, 'default') ? Boolean(f.default) : null
                break
            case 'number':
                state[f.key] = Number(f.default ?? 0)
                break
            case 'combobox':
                state[f.key] = f.multiple ? (f.initialIds || []) : (f.initialId ?? null)
                break
            case 'file':
                state[f.key] = null
                break
            default:
                state[f.key] = f.default ?? ''
                break
        }
    }
    return state
}

const form = useForm(initialState())

watchEffect(() => {
    if (!open.value) {
        form.defaults(initialState())
        form.reset()
        form.clearErrors()
    }
})

const hasFileField = computed(() => props.fields.some(f => f.type === 'file'))

function submit() {
    form.post(props.action, {
        preserveScroll: true,
        forceFormData: hasFileField.value,
        onSuccess: () => {
            close()
        },
    })
}

function close() {
    open.value = false
    form.reset()
    form.clearErrors()
    emit('closed')
}

function show() { open.value = true }
function hide() { close() }
defineExpose({ show, hide })

const columnsClass = computed(() => {
    const count = props.fields?.length || 0
    const base = ['grid', 'gap-y-4', 'gap-x-6', 'my-4', 'rounded-md', 'p-6', 'ring-gray-300', 'ring', 'relative', 'items-start', 'pb-14', 'bg-white', 'grid-cols-1', 'dark:bg-slate-900', 'dark:ring-slate-800']
    let cols = 1
    if (count === 1) cols = 1
    else if (count === 2) cols = 2
    else if (count === 3) cols = 3
    else if (count >= 4) cols = 4
    // Use a static map so Tailwind JIT can detect these class names at build time.
    const gridColsMap = {
        1: 'md:grid-cols-1',
        2: 'md:grid-cols-2',
        3: 'md:grid-cols-3',
        4: 'md:grid-cols-4',
    }
    base.push(gridColsMap[cols])
    return base
})
</script>