<template>
    <div v-if="items.length" class="mt-3 sm:-mx-0 rounded-md border border-gray-300 bg-white p-px" role="table">
        <div class="hidden lg:flex" role="row">
            <div v-for="(header, hIndex) in headers" :key="header.key" role="columnheader" :class="[
                'px-4 py-2 text-left text-sm font-semibold text-white bg-gray-600 first:rounded-tl-md',
                (!(hasDetailPages || urlBase) ? 'last:rounded-tr-md' : ''),
                classForIndex(hIndex)
            ]" :style="styleForIndex(hIndex)">
                {{ header.label }}
            </div>
            <div v-if="hasDetailPages || urlBase"
                class="px-4 py-2 bg-gray-600 rounded-tr-md shrink-0 flex items-center justify-end gap-3">
                <TrashIcon class="size-5 opacity-0" />
            </div>
        </div>

        <div class="bg-white" role="rowgroup" v-auto-animate>
            <div v-for="item in items" :key="item.id" role="row"
                class="even:bg-gray-50 grid grid-cols-12 py-3 lg:flex lg:flex-row lg:items-stretch">
                <div v-for="(column, cIndex) in headers" :key="column.key" role="cell"
                    :class="['px-4 py-2 flex flex-col col-span-12 md:col-span-6', classForIndex(cIndex)]"
                    :style="styleForIndex(cIndex)">
                    <span class="text-xs font-light mb-1.5 block lg:hidden text-gray-600"
                        v-if="column.fieldtype !== 'combobox'">{{ column.label }}</span>
                    <EditableTextField v-model="item[column.key]" :inputType="column.fieldtype"
                        @update:modelValue="onCellChange(item.id, column.key, $event)"
                        v-if="column.fieldtype === 'text' || column.fieldtype === 'number'" />
                    <SwitchComponent v-model="item[column.key]" v-else-if="column.fieldtype === 'boolean'"
                        @update:modelValue="onCellChange(item.id, column.key, $event)" />
                    <ComboBox v-else-if="column.fieldtype === 'combobox'" v-model="item[column.key]"
                        :options="column.combovalues || []" :multiple="column.multiple === true"
                        :initialId="Array.isArray(item[column.key]) ? null : (item[column.key]?.id ?? item[column.key] ?? null)"
                        :initialIds="Array.isArray(item[column.key]) ? item[column.key] : []"
                        @update:modelValue="onCellChange(item.id, column.key, $event)" :label="column.label" />
                    <ColorPickerComponent v-else-if="column.fieldtype === 'colorpicker'" v-model="item[column.key]"
                        @update:modelValue="onCellChange(item.id, column.key, $event)" />
                </div>
                <div class="px-4 py-2 text-right flex items-center justify-end gap-3 shrink-0"
                    v-if="hasDetailPages || urlBase">
                    <Link v-if="hasDetailPages && urlBase" :href="`/${urlBase}/${item.id}`"
                        class="text-blue-600 hover:text-blue-900">
                    Details
                    </Link>
                    <TrashIcon v-if="urlBase" class="size-5 text-red-400 hover:text-red-600 cursor-pointer"
                        @click.stop="onDelete(item.id)" />
                </div>
            </div>
        </div>
    </div>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import EditableTextField from './EditableTextField.vue';
import SwitchComponent from './SwitchComponent.vue';
import ComboBox from './ComboBox.vue';
import ColorPickerComponent from './ColorPickerComponent.vue';
import { TrashIcon } from '@heroicons/vue/24/outline'

type Item = {
    id: number;
    name: string;
};
type Header = {
    key: string;
    label: string;
    fieldtype: string;
    width?: number;
    combovalues?: { id: number | string; name: string }[];
    multiple?: boolean;
};

const { headers, items, urlBase, hasDetailPages } = defineProps<{
    headers: Header[];
    items: Item[];
    urlBase?: string;
    hasDetailPages?: boolean;
}>();

const emit = defineEmits(['update']);

function onCellChange(id: number, key: string, value: unknown) {
    emit('update', { item: items.find(item => item.id === id), key, value });
}

function onDelete(id: number) {
    if (!urlBase) return
    if (!confirm('Weet je zeker dat je dit wilt verwijderen?')) return
    useForm({}).delete(`/${urlBase}/${id}`, { preserveScroll: true })
}

function classForIndex(index: number) {
    if (index === headers.length - 1) return 'lg-grow'
    const w = headers[index]?.width
    if (typeof w === 'number' && isFinite(w)) return 'lg-col'
    return 'lg-col-auto'
}

function styleForIndex(index: number) {
    if (index === headers.length - 1) return {}
    const w = headers[index]?.width
    if (typeof w === 'number' && isFinite(w)) return { '--col-w': `${w}%` } as any
    return {}
}

</script>
<style scoped>
@media (min-width: 1024px) {
    .lg-col {
        flex: 0 0 var(--col-w);
        width: var(--col-w);
    }

    .lg-grow {
        flex: 1 1 0%;
        min-width: 0;
    }

    .lg-col-auto {
        flex: 0 0 auto;
    }
}
</style>