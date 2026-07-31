<template>
    <div class="lg:hidden">
        <!-- De achtergrond dimt zodat de knoppen die opkomen het enige zijn wat telt. -->
        <Transition enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0"
            enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150 ease-in"
            leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="creating" class="fixed inset-0 z-40 bg-slate-950/75" @click="creating = false">
            </div>
        </Transition>

        <!--
            De keuzes stijgen op vanaf de knop, één voor één en met de laatste
            bovenaan: van onder naar boven gelezen staat wat het dichtst bij je
            duim zit ook het dichtst bij de knop die je net indrukte.
        -->
        <TransitionGroup tag="ul" class="fixed inset-x-0 bottom-28 z-[45] h-0"
            enter-active-class="will-change-transform transition-transform duration-[450ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
            enter-from-class="translate-y-[26rem]" enter-to-class="translate-y-0"
            leave-active-class="will-change-transform transition-transform duration-200 ease-in"
            leave-from-class="translate-y-0" leave-to-class="translate-y-[26rem]">
            <!--
                Tussen de donkere laag en de balk in: ze schuiven onder de balk
                vandaan het scherm in, alsof ze eronder lagen te wachten.

                Elk item staat op een vaste plek boven de knop in plaats van in een
                rij die meegroeit. Anders schuift de hele stapel op zodra er een
                bijkomt, en dat schokkerige opschuiven is wat je ziet flikkeren.
            -->
            <li v-for="(action, index) in (creating ? visibleActions : [])" :key="action.id"
                class="absolute inset-x-0 flex justify-center" :style="{
                    bottom: ((visibleActions.length - 1 - index) * 3.75) + 'rem',
                    transitionDelay: ((visibleActions.length - 1 - index) * 60) + 'ms',
                }">
                <button type="button" @click="start(action)"
                    class="flex items-center gap-x-3 rounded-full bg-sidebar-card py-2.5 pr-5 pl-2.5 shadow-lg ring-1 ring-sidebar-border">
                    <span class="flex size-9 items-center justify-center rounded-full bg-lavoro-blue text-white">
                        <component :is="navIcon(action.icon)" class="size-5" />
                    </span>
                    <span class="text-sm font-semibold text-sidebar-text">{{ action.label }}</span>
                </button>
            </li>
        </TransitionGroup>

        <!--
            Een zwevende kaart en geen strook tegen de onderrand: zo staat het in
            het ontwerp, met ruimte eronder voor de streep van het toestel.
        -->
        <nav class="fixed inset-x-0 bottom-0 z-50 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
            aria-label="Hoofdnavigatie">
            <ul class="flex items-end justify-around rounded-2xl bg-sidebar-card px-2 pt-2 pb-2 shadow-[0_8px_30px_rgba(2,6,23,0.45)] ring-1 ring-sidebar-border">
                <li v-for="tab in tabs" :key="tab.id" class="flex-1">
                    <!-- De grote knop steekt boven de kaart uit, met zijn naam eronder. -->
                    <button v-if="tab.variant === 'fab'" type="button" :aria-expanded="creating"
                        :class="[TAB_CLASS, 'text-lavoro-blue']" @click="creating = !creating">
                        <span
                            class="-mt-8 flex size-14 items-center justify-center rounded-full bg-lavoro-blue text-white shadow-lg transition-transform duration-200"
                            :class="creating ? 'rotate-45' : ''">
                            <Plus class="size-7" />
                        </span>
                        <span class="text-[11px] leading-none">{{ tab.label }}</span>
                    </button>

                    <button v-else-if="tab.action === 'menu'" type="button"
                        :class="[TAB_CLASS, menuOpen ? 'text-lavoro-blue' : 'text-sidebar-muted']"
                        @click="$emit('menu')">
                        <component :is="navIcon(tab.icon)" class="size-6" />
                        <span class="text-[11px] leading-none">{{ tab.label }}</span>
                    </button>

                    <!--
                        Alle tabbladen dezelfde kolom: een anchor lijnt zijn tekst
                        links uit en een button in het midden, dus zonder dit staat
                        het ene woord onder zijn pictogram en het andere ernaast.
                    -->
                    <Link v-else :href="tab.href"
                        :class="[TAB_CLASS, tab.active ? 'text-lavoro-blue' : 'text-sidebar-muted']">
                        <span class="relative flex">
                            <component :is="navIcon(tab.icon)" class="size-6" />
                            <span v-if="tab.badgeValue"
                                class="absolute -top-1.5 -right-2 flex min-w-4 items-center justify-center rounded-full bg-lavoro-blue px-1 text-[10px] font-semibold text-white">
                                {{ tab.badgeValue > 99 ? '99+' : tab.badgeValue }}
                            </span>
                        </span>
                        <span class="text-[11px] leading-none">{{ tab.label }}</span>
                    </Link>
                </li>
            </ul>
        </nav>

        <CreateDrawer v-model="drawerOpen" :action="drawerAction" />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Plus } from '@lucide/vue'
import CreateDrawer from '@/Components/Layout/CreateDrawer.vue'
import { navIcon } from '@/Navigation/icons.js'
import { useMenu } from '@/Composables/useMenu.js'

/**
 * De balk onderaan op een telefoon. Welke knoppen erin staan en wat de grote
 * knop opent komt uit menu.json, net als de rest van het menu.
 */
defineProps({
    /** Het menu zelf staat elders open; de balk laat alleen zien dát het openstaat. */
    menuOpen: { type: Boolean, default: false },
})

defineEmits(['menu'])

/**
 * Elk tabblad is dezelfde kolom, wat het ook voor element is. Een anchor lijnt
 * zijn tekst links uit en een button in het midden, dus zonder dit staat het ene
 * woord onder zijn pictogram en het andere er scheef naast.
 */
const TAB_CLASS = 'flex w-full flex-col items-center gap-y-1 py-2'

const { mobileTabs: tabs, createActions: visibleActions } = useMenu()

const creating = ref(false)
const drawerOpen = ref(false)
const drawerAction = ref(null)
const page = usePage()

/** De keuze sluit de waaier en opent de la voor dat soort record. */
const start = (action) => {
    drawerAction.value = action
    creating.value = false
    drawerOpen.value = true
}

/** Wie ergens heen gaat, is klaar met kiezen. */
watch(() => page.url, () => { creating.value = false })
</script>
