<template>
    <div v-if="items.length" class="mt-3 sm:-mx-0 rounded-md border border-gray-300 bg-white p-px mx-2">
        <table class="min-w-full table-fixed border-separate border-spacing-0">
            <thead class="hidden lg:table-header-group">
                <tr>
                    <th v-for="header in headers" :key="header.key" :class="[
                        header.width,
                        'px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 first:rounded-tl-md',
                        (!hasDetailPages ? 'last:rounded-tr-md' : '')
                    ]">
                        {{
                            header.label }}</th>
                    <th v-if="hasDetailPages" class="px-4 py-2 bg-gray-600 rounded-tr-md"></th>
                </tr>
            </thead>
            <tbody class="bg-white">
                <tr v-for="item in items" :key="item.id"
                    class="even:bg-gray-50 grid grid-cols-12 lg:table-row py-3 lg:py-0">
                    <td v-for="column in headers" :key="column.key"
                        class="px-4 py-2 flex flex-col col-span-12 md:col-span-6 lg:table-cell">
                        <span class="text-xs font-light mb-1.5 block lg:hidden text-gray-600">{{ column.label }}</span>
                        <EditableTextField v-model="item[column.key]" :inputType="column.fieldtype"
                            @update:modelValue="onCellChange(item.id, column.key, $event)"
                            v-if="column.fieldtype === 'text' || column.fieldtype === 'number'" />
                        <SwitchComponent v-model="item[column.key]" v-else-if="column.fieldtype === 'boolean'"
                            @update:modelValue="onCellChange(item.id, column.key, $event)" />
                        <ComboBox v-else-if="column.fieldtype === 'combobox'" v-model="item[column.key]"
                            :options="column.combovalues"
                            :initialId="column.combovalues.find(c => c.id === item[column.key]?.id)?.id"
                            @update:modelValue="onCellChange(item.id, column.key, $event)" />
                        <ColorPickerComponent v-else-if="column.fieldtype === 'colorpicker'" v-model="item[column.key]"
                            @update:modelValue="onCellChange(item.id, column.key, $event)" />
                    </td>
                    <td class="px-4 py-2 text-right" v-if="hasDetailPages">
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
import ColorPickerComponent from './ColorPickerComponent.vue';

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
    hasDetailPages?: boolean;
}>();

const emit = defineEmits(['update']);

function onCellChange(id: number, key: string, value: unknown) {
    emit('update', { item: items.find(item => item.id === id), key, value });
}

</script>