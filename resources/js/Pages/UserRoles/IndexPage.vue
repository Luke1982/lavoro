<template>
    <IndexHeaderComponent title="Gebruikersrollen" subtitle="Beheer van rollen die gebruikers op afspraken vervullen"
        :show-search="false" add-label="Voeg rol toe" @add="showDrawer = true" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0" class="mt-4">
        <EditableGridComponent :headers="headers" :items="innerRoles" @update="onCellUpdate" :urlBase="urlBase" />
    </BoxComponent>

    <DrawerComponent v-model="showDrawer" title="Nieuwe gebruikersrol"
        subtitle="Vul de gegevens in voor de nieuwe rol.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newRoleForm.name" type="text" :hasError="Boolean(newRoleForm.errors.name)"
                        :errorMessage="newRoleForm.errors.name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-start">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200 pt-1">Kleur</label>
                <div class="sm:col-span-2">
                    <ColorPickerComponent v-model="newRoleForm.color" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewRole" :disabled="newRoleForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ColorPickerComponent from '@/Components/UI/ColorPickerComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { roles } = defineProps({
    roles: {
        type: Array,
        default: () => []
    }
})

const innerRoles = computed(() => roles)
const urlBase = 'userroles';

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 40 },
    { key: 'color', label: 'Kleur', fieldtype: 'colorpicker', width: 25 },
];

const showDrawer = ref(false)
const newRoleForm = useForm({ name: '', color: '#536bb2' })

function submitNewRole() {
    newRoleForm.post('/userroles', {
        preserveScroll: true,
        onSuccess: () => {
            showDrawer.value = false
            newRoleForm.reset()
        },
    })
}

function closeDrawer() {
    showDrawer.value = false
    newRoleForm.reset()
    newRoleForm.clearErrors()
}

const updateForm = useForm({});

function onCellUpdate({ item }) {
    updateForm.transform(() => ({ ...item }))
        .put(`/${urlBase}/${item.id}`, { preserveScroll: true });
}
</script>
