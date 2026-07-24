<template>
    <Menu as="div" class="relative" v-slot="{ open }">
        <div ref="triggerRef" class="inline-flex">
            <MenuButton :class="buttonClass" :title="title">
                <slot name="button" />
            </MenuButton>
        </div>
        <Teleport to="body">
            <div v-if="open" ref="panelRef" :style="menuStyle">
                <MenuItems static :class="[
                    'overflow-hidden rounded-lavoro-sm bg-white dark:bg-slate-800 py-1 shadow-lg ring-1 ring-black/5 dark:ring-slate-600 focus:outline-none',
                    widthClass
                ]">
                    <slot />
                </MenuItems>
            </div>
        </Teleport>
    </Menu>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Menu, MenuButton, MenuItems } from '@headlessui/vue';
import { autoUpdate, computePosition, flip, offset, shift } from '@floating-ui/dom';

/**
 * A Headless UI menu whose panel is teleported to <body>.
 *
 * An absolutely positioned panel is clipped the moment an ancestor scrolls — a
 * table inside `overflow-x-auto` computes `overflow-y: auto` too, so an in-flow
 * dropdown disappears behind the table's own edge.
 */
const props = defineProps({
    buttonClass: { type: String, default: '' },
    widthClass: { type: String, default: 'w-56' },
    placement: { type: String, default: 'bottom-end' },
    title: { type: String, default: '' },
});

const triggerRef = ref(null);
const panelRef = ref(null);
const menuStyle = ref({ position: 'fixed', zIndex: 60 });
let stopAutoUpdate = null;

async function updatePosition() {
    if (!triggerRef.value || !panelRef.value) return;

    const { x, y } = await computePosition(triggerRef.value, panelRef.value, {
        placement: props.placement,
        strategy: 'fixed',
        middleware: [offset(4), flip(), shift({ padding: 8 })],
    });

    menuStyle.value = { position: 'fixed', zIndex: 60, top: `${y}px`, left: `${x}px` };
}

watch(panelRef, (element) => {
    stopAutoUpdate?.();
    stopAutoUpdate = null;

    if (element && triggerRef.value) {
        stopAutoUpdate = autoUpdate(triggerRef.value, element, updatePosition);
    }
});

onBeforeUnmount(() => stopAutoUpdate?.());
</script>
