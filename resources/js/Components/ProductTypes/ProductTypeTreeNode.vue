<template>
    <div class="relative" v-auto-animate>
        <!-- Vertical spine: fixed height so it ends at the midpoint of the last direct child row,
             regardless of how deep that child's own subtree is. -->
        <div v-if="type.children?.length"
            class="absolute w-0.5 bg-gray-200 dark:bg-slate-700 pointer-events-none"
            :style="{ left: spineX + 'px', top: '62px', height: type.spineHeight + 'px' }" />

        <!-- Arch: fixed 31px height (half of one row) so it never stretches into grandchildren. -->
        <div v-if="depth > 0"
            class="absolute top-0 h-[31px] border-l-2 border-b-2 border-gray-200 dark:border-slate-700 rounded-bl-lg pointer-events-none"
            :style="{ left: archX + 'px', width: '20px' }" />

        <!-- ROW -->
        <div class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2">
            <div class="col-span-6 flex items-center">
                <div v-if="indentPx" :style="{ width: indentPx + 'px' }" class="shrink-0" />
                <EditableTextField :model-value="type.name" :decoration="false"
                    @update="(val) => onUpdate(type, { name: val })">
                    <template #display>
                        <span :class="{ 'font-semibold': depth === 0 }">{{ type.name }}</span>
                    </template>
                </EditableTextField>
            </div>
            <div class="col-span-3 flex items-center">
                <EditableTextField :model-value="type.typical_certificate_days" inputType="number"
                    :decoration="false" placeholder="—"
                    @update="(val) => onUpdate(type, { typical_certificate_days: val || null })">
                    <template #display>{{ type.typical_certificate_days || '—' }}</template>
                </EditableTextField>
            </div>
            <div class="col-span-2 flex items-center">
                <span v-tooltip="{ html: true, content: type.products_tooltip }"
                    class="inline-flex items-center justify-center min-w-[1.5rem] rounded-full bg-gray-100 dark:bg-slate-700 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:text-slate-200 cursor-default">
                    {{ type.products_count }}
                </span>
            </div>
            <div class="col-span-1 flex items-center justify-end gap-1.5">
                <div v-if="type.products_count === 0" class="border-1 border-lavoro-darkergray rounded-full p-1.5"
                    v-tooltip="'Verwijderen'">
                    <TrashIcon class="h-4 w-4 cursor-pointer text-red-500" @click="onDelete(type)" />
                </div>
                <button type="button" @click="onToggleAdding(type.id)"
                    :class="isAdding ? 'text-gray-400 hover:text-gray-600' : 'text-lavoro-blue hover:text-lavoro-darkerblue'"
                    class="font-bold text-lg leading-none w-5 text-center"
                    :title="isAdding ? 'Annuleren' : 'Subtype toevoegen'">
                    {{ isAdding ? '×' : '+' }}
                </button>
            </div>
        </div>

        <!-- RECURSIVE CHILDREN -->
        <ProductTypeTreeNode
            v-for="child in type.children"
            :key="child.id"
            :type="child"
            :depth="depth + 1" />

        <!-- Inline add-child form -->
        <div v-if="isAdding" class="relative bg-white dark:bg-slate-900">
            <div class="absolute top-0 h-[31px] border-l-2 border-b-2 border-lavoro-blue dark:border-lavoro-blue rounded-bl-lg pointer-events-none"
                :style="{ left: spineX + 'px', width: '20px' }" />
            <div class="grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 items-center">
                <div class="col-span-11 flex items-center gap-3">
                    <div :style="{ width: (indentPx + 32) + 'px' }" class="shrink-0" />
                    <input :value="childNames[type.id] ?? ''"
                        @input="childNames[type.id] = $event.target.value"
                        type="text" placeholder="Naam subtype..."
                        class="flex-1 rounded-md border border-gray-300 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 p-2 text-sm focus:ring-2 focus:ring-lavoro-blue focus:outline-none"
                        @keydown.enter="onSubmitChild(type.id)"
                        @keydown.escape="onToggleAdding(type.id)" />
                </div>
                <div class="col-span-1 flex justify-end items-center">
                    <button type="button"
                        class="w-8 h-8 rounded-full bg-lavoro-blue hover:bg-lavoro-darkerblue text-white flex items-center justify-center font-bold text-lg leading-none"
                        @click="onSubmitChild(type.id)">+</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import { TrashIcon } from '@lucide/vue'
import ProductTypeTreeNode from './ProductTypeTreeNode.vue'

const props = defineProps({
    type: { type: Object, required: true },
    depth: { type: Number, default: 0 },
})

const addingChildTo  = inject('pt:addingChildTo')
const childNames     = inject('pt:childNames')
const onUpdate       = inject('pt:onUpdate')
const onSubmitChild  = inject('pt:onSubmitChild')
const onToggleAdding = inject('pt:onToggleAdding')
const onDelete       = inject('pt:onDelete')

const indentPx = computed(() => props.depth * 32)
const archX    = computed(() => props.depth * 32 - 16)
const spineX   = computed(() => props.depth * 32 + 16)
const isAdding = computed(() => !!addingChildTo[props.type.id])
</script>
