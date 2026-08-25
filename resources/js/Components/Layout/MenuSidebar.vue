<template>
    <div class="menu-morph flex grow flex-col overflow-y-auto bg-sidebar-bg px-2 pb-4 lg:px-4"
        :class="isCollapsed ? 'menu-collapsed lg:px-2' : ''">
        <!--
            Ingeklapt staat de knop onder het logo in plaats van ernaast: naast
            elkaar vechten ze om 56 pixels en verliest het logo.
        -->
        <div class="flex shrink-0 px-2 pt-4 pb-2"
            :class="isCollapsed ? 'flex-col items-center gap-y-2' : 'items-start justify-between'">
            <span class="menu-logo">
                <img src="/img/logo-neg.svg" alt="Lavoro">
            </span>
            <button v-if="allowCollapse" type="button" :aria-label="isCollapsed ? 'Menu uitklappen' : 'Menu inklappen'"
                class="hidden rounded-md p-1 text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-text lg:block"
                @click="setCollapsed(!collapsed)">
                <ChevronsLeft class="size-4 transition-transform duration-300" :class="isCollapsed ? 'rotate-180' : ''" />
            </button>
        </div>

        <!--
            De zoekregel krimpt mee tot een knop: dezelfde knop, alleen zonder de
            woorden. De sneltoets staat erbij zolang er ruimte voor is, want een
            combinatie die niemand kent wordt niet gebruikt.
        -->
        <div v-if="allowCollapse" class="mb-3 flex items-center gap-x-2"
            :class="isCollapsed ? 'flex-col gap-y-1' : ''">
            <div class="rail-item relative w-full" :class="isCollapsed ? '' : 'flex-1'">
                <button type="button" @click="openNavigator" :class="[
                    'menu-morph flex w-full items-center rounded-lg border border-sidebar-border bg-sidebar-card py-2 text-sm text-sidebar-muted hover:border-lavoro-green hover:text-sidebar-text',
                    isCollapsed ? 'justify-center px-0' : 'px-3',
                ]">
                    <Search class="size-4 shrink-0 text-lavoro-green" />
                    <span class="menu-label ml-2.5 flex-1 text-left">{{ search.label }}</span>
                    <kbd v-if="!isCollapsed"
                        class="menu-trailing hidden rounded border border-sidebar-border px-1.5 py-0.5 font-sans text-[10px] lg:inline">{{
                            shortcutLabel }}</kbd>
                </button>
                <div class="rail-flyout">
                    <span
                        class="block rounded-lg bg-sidebar-card px-3 py-1.5 text-[13px] font-medium whitespace-nowrap text-sidebar-text ring-1 ring-sidebar-border">
                        {{ search.label }} ({{ shortcutLabel }})
                    </span>
                </div>
            </div>
            <NotificationBell v-if="allowCollapse" :placement="isCollapsed ? 'right-start' : 'bottom-end'" />
        </div>

        <nav class="flex flex-1 flex-col">
            <ul role="list" class="menu-sections flex flex-1 flex-col gap-y-5">
                <li>
                    <ul role="list" class="space-y-0.5">
                        <MenuRow v-for="item in pinned" :key="item.id" :item="item" :is-open="isOpen"
                            @navigate="$emit('navigate')" @toggle="toggle" />
                    </ul>
                </li>

                <li v-for="section in sections" :key="section.id">
                    <div class="menu-section-header flex items-center gap-x-2 px-3">
                        <span class="h-3 w-0.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: accentColor(section.accent) }"></span>
                        <span class="menu-label text-[11px] font-semibold tracking-wider text-sidebar-muted uppercase">{{
                            section.label }}</span>
                    </div>
                    <ul role="list" class="mt-2 space-y-0.5">
                        <MenuRow v-for="item in section.items" :key="item.id" :item="item" :is-open="isOpen"
                            @navigate="$emit('navigate')" @toggle="toggle" />
                    </ul>
                </li>

                <!--
                    De kaarten en de pictogrammen liggen over elkaar en wisselen met
                    opacity, zodat de een opkomt terwijl de ander weggaat.
                -->
                <li class="menu-footer mt-auto pt-4">
                    <div class="menu-footer-wide flex flex-col gap-y-3">
                        <MenuCards :cards="cards" @navigate="$emit('navigate')" @logout="$emit('logout')" />
                    </div>

                    <div class="menu-footer-narrow flex flex-col items-center gap-2">
                        <div class="rail-item relative">
                            <Link href="/me/edit" @click="$emit('navigate')"
                                class="flex size-10 items-center justify-center overflow-hidden rounded-full bg-sidebar-card ring-1 ring-sidebar-border">
                                <img v-if="authUser?.avatar" :src="authUser.avatar" class="h-full w-full object-cover"
                                    alt="">
                                <span v-else class="text-xs font-semibold text-sidebar-text">{{ initials }}</span>
                            </Link>
                            <div class="rail-flyout">
                                <span
                                    class="block rounded-lg bg-sidebar-card px-3 py-1.5 text-[13px] font-medium whitespace-nowrap text-sidebar-text ring-1 ring-sidebar-border">
                                    {{ authUser?.name || 'Profiel' }}
                                </span>
                            </div>
                        </div>
                        <div class="rail-item relative">
                            <button type="button" :disabled="loggingOut" @click="$emit('logout')"
                                class="flex size-10 items-center justify-center rounded-lg text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-text disabled:cursor-wait disabled:hover:bg-transparent">
                                <LoaderCircle v-if="loggingOut" class="size-4 animate-spin" />
                                <LogOut v-else class="size-4" />
                                <span class="sr-only">{{ loggingOut ? 'Bezig met afmelden' : 'Uitloggen' }}</span>
                            </button>
                            <div class="rail-flyout">
                                <span
                                    class="block rounded-lg bg-sidebar-card px-3 py-1.5 text-[13px] font-medium whitespace-nowrap text-sidebar-text ring-1 ring-sidebar-border">
                                    Uitloggen
                                </span>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ChevronsLeft, LoaderCircle, LogOut, Search } from '@lucide/vue'
import MenuRow from '@/Components/Layout/MenuRow.vue'
import MenuCards from '@/Components/Layout/MenuCards.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
import { useMenu } from '@/Composables/useMenu.js'
import { navigatorShortcutLabel, openNavigator } from '@/Composables/useNavigator.js'

/**
 * De zijbalk, in beide breedtes dezelfde boom. Inklappen wisselt niets om: het
 * zet alleen .menu-collapsed, en app.css laat vanaf daar de teksten wegkrimpen,
 * het logo versmallen en de onderkant overvloeien. De vorm van het menu zelf komt
 * uit menu.json via useMenu.
 */
const props = defineProps({
    allowCollapse: { type: Boolean, default: true },
})

defineEmits(['navigate', 'logout'])

const {
    pinned, sections, cards, search, collapsed, setCollapsed, isOpen, toggle, authUser, initials,
    loggingOut,
} = useMenu()

/**
 * De ingeklapte stand is er één van de zijbalk op een breed scherm. Het paneel op
 * een telefoon deelt diezelfde toestand, maar mag er nooit naar luisteren: dan
 * staat het menu daar ineens als pictogrammenbalk op een heel scherm.
 */
const isCollapsed = computed(() => props.allowCollapse && collapsed.value)

const shortcutLabel = navigatorShortcutLabel()

/**
 * Tailwind maakt geen klasse van een waarde die pas tijdens het draaien bestaat,
 * dus het accent van een sectie reist als kleur en niet als klassenaam.
 */
const ACCENTS = {
    operatie: 'var(--color-nav-operatie)',
    relaties: 'var(--color-nav-relaties)',
    organisatie: 'var(--color-nav-organisatie)',
    lijsten: 'var(--color-nav-lijsten)',
}

const accentColor = (accent) => ACCENTS[accent] ?? 'var(--color-sidebar-border)'
</script>
