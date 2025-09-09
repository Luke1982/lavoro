<template>
    <Dialog :open="true" as="div" class="relative z-10" @close="$emit('close')">
        <div class="fixed inset-0 bg-gray-500/75 transition-opacity" />
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                <div
                    class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-left sm:ml-4 sm:mt-0 w-full">
                            <h3 class="text-base font-semibold leading-6 text-gray-900 mb-4">Bewerk bedrijf</h3>
                            <form @submit.prevent="uploadLogo" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Naam<span
                                            class="text-red-500">*</span></label>
                                    <EditableTextField v-model="form.name" class="w-full" placeholder="Naam"
                                        @update="inlineSave('name', $event)" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Adresregel 1</label>
                                        <EditableTextField v-model="form.address_line1" class="w-full"
                                            placeholder="Adres" @update="inlineSave('address_line1', $event)" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Adresregel 2</label>
                                        <EditableTextField v-model="form.address_line2" class="w-full"
                                            placeholder="Adres 2" @update="inlineSave('address_line2', $event)" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Postcode</label>
                                        <EditableTextField v-model="form.postal_code" class="w-full"
                                            placeholder="1234AB" @update="inlineSave('postal_code', $event)" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Plaats</label>
                                        <EditableTextField v-model="form.city" class="w-full" placeholder="Plaats"
                                            @update="inlineSave('city', $event)" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Land</label>
                                        <EditableTextField v-model="form.country" class="w-full" placeholder="NL"
                                            @update="inlineSave('country', $event)" />
                                    </div>
                                    <div class="flex items-center mt-6 gap-2">
                                        <SwitchComponent v-model="form.is_main"
                                            @click="inlineSave('is_main', form.is_main)" />
                                        <span class="text-sm">Hoofd bedrijf?</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Logo</label>
                                    <input type="file" @change="onFile" class="block w-full text-sm" />
                                    <div v-if="company.logo_path" class="mt-2">
                                        <img :src="`/public/${company.logo_path}`" class="h-12 object-contain" />
                                    </div>
                                </div>
                                <div class="flex justify-end gap-3 pt-6">
                                    <button type="button" @click="$emit('close')"
                                        class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400">
                                        <XMarkIcon class="w-4 h-4" />
                                        Annuleer
                                    </button>
                                    <button type="submit"
                                        class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        :disabled="!logoFile">
                                        <CheckIcon class="w-4 h-4" />
                                        Upload logo
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Dialog>
</template>
<script setup>
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { Dialog } from '@headlessui/vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import { XMarkIcon, CheckIcon } from '@heroicons/vue/24/outline'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const props = defineProps({ company: { type: Object, required: true } })
const emit = defineEmits(['close'])

const form = useForm({
    name: props.company.name,
    address_line1: props.company.address_line1,
    address_line2: props.company.address_line2,
    postal_code: props.company.postal_code,
    city: props.company.city,
    country: props.company.country,
    is_main: props.company.is_main,
    logo: null
})

const logoFile = ref(null)

function onFile(e) {
    logoFile.value = e.target.files[0]
}

function inlineSave(field, value) {
    router.patch(`/companies/${props.company.id}/inline`, { [field]: value }, { preserveScroll: true })
}

function uploadLogo() {
    if (!logoFile.value) return
    const data = new FormData()
    data.append('logo', logoFile.value)
    router.post(`/companies/${props.company.id}/logo`, data, { preserveScroll: true, onSuccess: () => emit('close') })
}
</script>
