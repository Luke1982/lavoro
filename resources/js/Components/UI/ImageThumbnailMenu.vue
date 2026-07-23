<template>
    <div ref="triggerRef" class="absolute inset-0 sm:hidden">
        <Menu as="div" class="contents" v-slot="{ open }">
            <MenuButton class="block size-full cursor-pointer" @click.stop>
                <span class="sr-only">Opties voor {{ image.name }}</span>
            </MenuButton>
            <Teleport to="body">
                <div v-if="open" ref="panelRef" :style="menuStyle">
                    <MenuItems static
                        class="w-48 bg-white dark:bg-slate-800 shadow-lg rounded-lg border border-gray-200 dark:border-slate-700 focus:outline-none py-1">
                        <MenuItem v-slot="{ active }">
                            <button @click="$emit('view')" :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200 cursor-pointer']">
                                <EyeIcon class="size-4 flex-shrink-0" />
                                Bekijken
                            </button>
                        </MenuItem>
                        <MenuItem v-if="canSetMain" v-slot="{ active }">
                            <button @click="$emit('favorite')" :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200 cursor-pointer']">
                                <StarIcon class="size-4 flex-shrink-0" :class="image.pivot?.main ? 'text-yellow-500' : ''" />
                                {{ image.pivot?.main ? 'Niet meer favoriet' : 'Favoriet maken' }}
                            </button>
                        </MenuItem>
                        <MenuItem v-if="canEdit" v-slot="{ active }">
                            <button @click="$emit('annotate')" :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200 cursor-pointer']">
                                <PencilSquareIcon class="size-4 flex-shrink-0" />
                                Annoteren
                            </button>
                        </MenuItem>
                        <MenuItem v-if="canDelete" v-slot="{ active }">
                            <button @click="$emit('delete')" :class="[active ? 'bg-red-50 dark:bg-red-900/20' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-red-600 dark:text-red-400 cursor-pointer']">
                                <TrashIcon class="size-4 flex-shrink-0" />
                                Verwijderen
                            </button>
                        </MenuItem>
                    </MenuItems>
                </div>
            </Teleport>
        </Menu>
    </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue';
import { EyeIcon, StarIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { computePosition, autoUpdate, offset, flip, shift } from '@floating-ui/dom';

defineProps({
    image: { type: Object, required: true },
    canSetMain: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

defineEmits(['view', 'favorite', 'annotate', 'delete']);

const triggerRef = ref(null);
const panelRef = ref(null);
const menuStyle = ref({ position: 'fixed', zIndex: 9999 });
let stopAutoUpdate = null;

async function updatePosition() {
    if (!triggerRef.value || !panelRef.value) return;
    const { x, y } = await computePosition(triggerRef.value, panelRef.value, {
        placement: 'bottom',
        strategy: 'fixed',
        middleware: [offset(4), flip(), shift({ padding: 8 })],
    });
    menuStyle.value = { position: 'fixed', zIndex: 9999, top: `${y}px`, left: `${x}px` };
}

watch(panelRef, (el) => {
    stopAutoUpdate?.();
    stopAutoUpdate = null;
    if (el && triggerRef.value) {
        stopAutoUpdate = autoUpdate(triggerRef.value, el, updatePosition);
    }
});

onBeforeUnmount(() => stopAutoUpdate?.());
</script>
