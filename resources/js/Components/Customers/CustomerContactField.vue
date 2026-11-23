<template>
    <EditableTextField v-model="model" type="combobox" :options="contacts"
        empty-message="Deze klant heeft nog geen contactpersonen. Je voegt ze toe bij de klant.">
        <template #display>
            <span v-if="selected">{{ selected.name }}</span>
            <span v-else-if="customerContactname">
                {{ customerContactname }}
                <span class="text-gray-400 dark:text-slate-500">· contactpersoon van de klant</span>
            </span>
            <span v-else class="text-gray-400 dark:text-slate-500">Geen contactpersoon</span>
        </template>
    </EditableTextField>
</template>

<script setup>
import { computed } from 'vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';

/**
 * The contact person of a record, picked from its customer's contacts. Without
 * one it shows the free-text contact on the customer, which is who a standard
 * e-mail greets then. Label, error and disabled pass through to the field.
 */
const model = defineModel();

const props = defineProps({
    /** The customer's contacts as { id, name }. */
    contacts: { type: Array, default: () => [] },
    customerContactname: { type: String, default: null },
});

const selected = computed(() => props.contacts.find(contact => contact.id === model.value) ?? null);
</script>
