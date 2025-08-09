<template>
    <div v-if="items.length" class="-mx-4 mt-3 sm:-mx-0 border border-gray-300 rounded-md">
        <table class="min-w-full divide-y divide-gray-200 table-fixed bg-gray-600">
            <thead>
                <tr>
                    <th v-for="header in headers" :key="header.key"
                        :class="[header.width, 'px-4 py-2 text-left text-sm font-semibold text-white']">{{
                            header.label }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="item in items" :key="item.id" class="even:bg-gray-50">
                    <td v-for="column in headers" :key="column.key" class="px-4 py-2">
                        <EditableTextField v-model="item[column.key]" :inputType="column.fieldtype"
                            @update:modelValue="onCellChange(item.id, column.key, $event)"
                            v-if="column.fieldtype === 'text' || column.fieldtype === 'number'" />
                        <SwitchComponent v-model="item[column.key]" v-else-if="column.fieldtype === 'boolean'"
                            @update:modelValue="onCellChange(item.id, column.key, $event)" />
                        <ComboBox v-else-if="column.fieldtype === 'combobox'" v-model="item[column.key]"
                            :options="column.combovalues"
                            :initialId="column.combovalues.find(c => c.id === item[column.key]?.id)?.id"
                            @update:modelValue="onCellChange(item.id, column.key, $event)" />
                    </td>
                    <td class="px-4 py-2 text-right">
                        <Link v-if="urlBase" :href="`/${urlBase}/${item.id}`" class="text-blue-600 hover:text-blue-900">
                        Details
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import EditableTextField from './EditableTextField.vue';
import SwitchComponent from './SwitchComponent.vue';
import ComboBox from './ComboBox.vue';

type Item = {
    id: number;
    name: string;
};
type Header = {
    key: string;
    label: string;
    fieldtype: string;
    width?: string;
    combovalues?: { id: number | string; name: string }[];
};

const { headers, items } = defineProps<{
    headers: Header[];
    items: Item[];
    urlBase?: string;
}>();

const emit = defineEmits(['update']);

function onCellChange(id: number, key: string, value: unknown) {
    emit('update', { item: items.find(item => item.id === id), key, value });
}

</script>