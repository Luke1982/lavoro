<template>
    <li class="rail-item relative">
        <div :class="[
            'group relative flex items-center rounded-lg transition-colors duration-150',
            depth === 0 ? 'pr-1' : '',
            rowClass,
        ]">
            <!-- Alleen op het eerste niveau; dieper zegt het bolletje op de lijn het al. -->
            <span v-if="item.current && depth === 0"
                class="absolute top-1.5 bottom-1.5 -left-2 w-0.5 rounded-full bg-sidebar-indicator transition-all duration-200"></span>

            <span v-if="depth > 0"
                class="absolute top-1/2 -left-3 size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full transition-colors duration-200"
                :class="item.active ? 'bg-sidebar-indicator' : 'bg-slate-600 group-hover:bg-slate-400'"></span>

            <component :is="item.href ? Link : 'button'" :href="item.href" :type="item.href ? undefined : 'button'"
                :class="[
                    'flex min-w-0 flex-1 items-center py-2 text-sm',
                    depth === 0 ? 'pl-3 font-medium' : 'pl-2 text-[13px]',
                ]" @click="item.href ? $emit('navigate') : $emit('toggle', item)">
                <component :is="icon" v-if="icon" class="size-5 shrink-0" aria-hidden="true" />
                <span v-else-if="item.initial"
                    class="flex size-6 shrink-0 items-center justify-center rounded-lg border border-sidebar-border bg-sidebar-card text-[0.625rem] font-medium">
                    {{ item.initial }}
                </span>
                <!-- Krimpt weg in plaats van te verdwijnen; het pictogram blijft staan. -->
                <span class="menu-label" :class="depth === 0 ? 'ml-3' : ''">{{ item.label }}</span>
            </component>

            <span class="menu-trailing flex shrink-0 items-center">
                <span v-if="item.indicatorValue"
                    class="mr-2 size-2 rounded-full bg-lavoro-blue shadow-[0_0_6px_rgba(37,99,235,0.6)]"
                    :aria-label="item.label + ' heeft openstaande items'"></span>

                <span v-if="item.badgeValue"
                    class="mr-2 min-w-5 rounded-full bg-lavoro-blue px-1.5 py-0.5 text-center text-[11px] font-semibold text-white">
                    {{ item.badgeValue }}
                </span>

                <button v-if="item.children.length" type="button"
                    class="rounded-md p-1 opacity-70 transition hover:opacity-100" :aria-expanded="expanded"
                    :aria-label="(expanded ? 'Sluit ' : 'Open ') + item.label" @click.stop="$emit('toggle', item)">
                    <ChevronDown class="size-4 transition-transform duration-200"
                        :class="expanded ? 'rotate-180' : ''" />
                </button>
                <ChevronRight v-else-if="item.href && depth === 0" class="mr-1 size-4 opacity-40" aria-hidden="true" />
            </span>
        </div>

        <Transition enter-active-class="grid transition-all duration-200 ease-[cubic-bezier(0.4,0,0.2,1)]"
            enter-from-class="grid-rows-[0fr] opacity-0" enter-to-class="grid-rows-[1fr] opacity-100"
            leave-active-class="grid transition-all duration-150 ease-in"
            leave-from-class="grid-rows-[1fr] opacity-100" leave-to-class="grid-rows-[0fr] opacity-0">
            <div v-if="item.children.length && expanded" class="menu-children grid grid-rows-[1fr]">
                <div class="-ml-2 min-h-0 overflow-hidden pl-2">
                    <ul :class="[childIndent, 'space-y-0.5 border-l border-sidebar-border pl-3']">
                        <MenuRow v-for="child in item.children" :key="child.id" :item="child" :depth="depth + 1"
                            :is-open="isOpen" @navigate="$emit('navigate')" @toggle="$emit('toggle', $event)" />
                    </ul>
                </div>
            </div>
        </Transition>

        <!--
            Ingeklapt hangt hetzelfde lijstje ernaast in plaats van eronder. Het
            staat altijd in de boom; de stylesheet laat het alleen zien als de balk
            smal is en de muis erop staat.
        -->
        <div v-if="depth === 0" class="rail-flyout">
            <div v-if="item.children.length"
                class="min-w-52 rounded-xl bg-sidebar-card p-2 ring-1 ring-sidebar-border">
                <Link v-if="item.href" :href="item.href" @click="$emit('navigate')"
                    class="block rounded-lg px-3 py-2 text-sm font-semibold text-sidebar-text hover:bg-sidebar-hover">
                    {{ item.label }}
                </Link>
                <p v-else class="px-3 py-2 text-sm font-semibold text-sidebar-text">{{ item.label }}</p>

                <ul class="mt-1 space-y-0.5 border-t border-sidebar-border pt-1">
                    <li v-for="child in item.children" :key="child.id">
                        <Link v-if="child.href" :href="child.href" @click="$emit('navigate')"
                            :class="flyoutChildClass(child)">{{ child.label }}</Link>
                        <p v-else
                            class="px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-sidebar-muted uppercase">
                            {{ child.label }}
                        </p>

                        <ul v-if="child.children?.length" class="ml-3 space-y-0.5 border-l border-sidebar-border pl-2">
                            <li v-for="grandchild in child.children" :key="grandchild.id">
                                <Link :href="grandchild.href" @click="$emit('navigate')"
                                    :class="flyoutChildClass(grandchild)">{{ grandchild.label }}</Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>

            <span v-else
                class="block rounded-lg bg-sidebar-card px-3 py-1.5 text-[13px] font-medium whitespace-nowrap text-sidebar-text ring-1 ring-sidebar-border">
                {{ item.label }}
            </span>
        </div>
    </li>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ChevronDown, ChevronRight } from '@lucide/vue'
import { navIcon } from '@/Navigation/icons.js'

/**
 * Eén menuregel, op elk niveau dezelfde, en in beide breedtes dezelfde. Er wordt
 * niets omgewisseld als de balk inklapt: de tekst krimpt weg, het pictogram blijft
 * staan en het lijstje eronder verhuist naar de uitklapper ernaast. Welke van de
 * twee je ziet, bepaalt app.css aan de hand van .menu-collapsed.
 */
const props = defineProps({
    item: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    isOpen: { type: Function, required: true },
})

defineEmits(['navigate', 'toggle'])

const icon = computed(() => (props.depth === 0 ? navIcon(props.item.icon) : null))

const expanded = computed(() => props.isOpen(props.item))

/**
 * De streep hangt onder het midden van het pictogram erboven. Op het eerste
 * niveau is dat 12px padding plus de helft van een pictogram van 20px; dieper is
 * het 12px plus 4px marge plus de helft van een bolletje van 6px. De halve pixel
 * eraf zet de lijn zelf op dat midden in plaats van ernaast.
 */
const childIndent = computed(() => (props.depth === 0 ? 'ml-[21.5px]' : 'ml-[18.5px]'))

const rowClass = computed(() => {
    if (props.item.current) return 'bg-sidebar-active text-white shadow-sm'
    if (props.item.active) return 'text-sidebar-text'
    if (props.depth === 0) return 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-text'
    return 'text-sidebar-muted hover:text-sidebar-text'
})

const flyoutChildClass = (child) => [
    'block rounded-lg px-3 py-1.5 text-[13px] transition-colors',
    child.current ? 'bg-sidebar-active text-white' : 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-text',
]
</script>
