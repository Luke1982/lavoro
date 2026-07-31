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

            Leg hem opzij en hij gaat het scherm uit. Zodra de beweging zijwaarts
            blijkt zit de balk aan de vinger vast: hij loopt precies mee, alleen
            over de breedte, zodat het aanvoelt alsof je hem echt wegschuift.

            touch-pan-y laat het verticaal scrollen aan de browser en houdt de
            zijwaartse beweging hier, anders neemt de een de ander over.
        -->
        <nav ref="navEl"
            class="fixed inset-x-0 bottom-0 z-50 touch-pan-y px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
            :class="[tuckClass, dragging ? '' : 'transition-transform duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]', tucked ? 'pointer-events-none' : '']"
            :style="dragStyle" :inert="tucked !== null" aria-label="Hoofdnavigatie" @pointerdown="onPointerDown"
            @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerUp"
            @click.capture="onClickCapture">
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

        <!--
            Het lipje aan de kant waar de balk heen ging. Het staat tegen de rand
            en is rond aan de kant van het scherm, zodat het eruitziet als iets dat
            half buiten beeld ligt en teruggetrokken kan worden.
        -->
        <Transition enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] delay-150"
            :enter-from-class="tucked === 'left' ? '-translate-x-full opacity-0' : 'translate-x-full opacity-0'"
            enter-to-class="translate-x-0 opacity-100" leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            :leave-to-class="tucked === 'left' ? '-translate-x-full opacity-0' : 'translate-x-full opacity-0'">
            <button v-if="tucked" type="button" aria-label="Menubalk terughalen" @click="tucked = null"
                class="fixed bottom-[max(1.25rem,env(safe-area-inset-bottom))] z-50 flex h-12 w-9 items-center justify-center bg-sidebar-card text-sidebar-muted shadow-[0_8px_30px_rgba(2,6,23,0.45)] ring-1 ring-sidebar-border"
                :class="tucked === 'left' ? 'left-0 rounded-r-2xl' : 'right-0 rounded-l-2xl'">
                <component :is="tucked === 'left' ? ChevronRight : ChevronLeft" class="size-5" />
            </button>
        </Transition>

        <CreateDrawer v-model="drawerOpen" :action="drawerAction" />
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { ChevronLeft, ChevronRight, Plus } from '@lucide/vue'
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

/**
 * De balk opzij vegen.
 *
 * Hij blijft weg waar je hem liet, ook na een verversing: iemand die hem opzij
 * schuift om de onderste rij van een lijst te kunnen zien, wil hem niet bij elke
 * pagina terug hebben. Vandaar de localStorage en niet een ref die leegloopt.
 *
 * De richting wordt onthouden en niet alleen het feit dat hij weg is, want het
 * lipje hoort te staan waar je hem heen duwde.
 */
const TUCK_KEY = 'lavoro_tabbar_tucked'

/** Zover moet de vinger zijn gekomen voordat wegvegen telt. */
const COMMIT_AT = 64

/** Of zoveel sneller: een korte tik met vaart legt hem net zo goed opzij. */
const FLICK_SPEED = 0.5

/** En zoveel beweging voordat de veeg zich vastlegt op zijwaarts of op en neer. */
const AXIS_LOCK = 8

const navEl = ref(null)
const tucked = ref(readTuck())
const dragging = ref(false)
const drag = ref(0)

let pointer_id = null
let start_x = 0
let start_y = 0
let anchor_x = 0
let last_x = 0
let last_time = 0
let speed = 0
let axis = null
let just_dragged = false

function readTuck() {
    try {
        const side = localStorage.getItem(TUCK_KEY)

        return side === 'left' || side === 'right' ? side : null
    } catch {
        return null
    }
}

/** Een browser die niets wil onthouden hoeft niets te onthouden. */
watch(tucked, (side) => {
    try {
        if (side) localStorage.setItem(TUCK_KEY, side)
        else localStorage.removeItem(TUCK_KEY)
    } catch {
        /* niets aan te doen, en de balk werkt verder gewoon */
    }
})

const tuckClass = computed(() => {
    if (dragging.value) return ''
    if (tucked.value === 'left') return '-translate-x-[110%]'
    if (tucked.value === 'right') return 'translate-x-[110%]'

    return 'translate-x-0'
})

/**
 * Onder de vinger staat de verplaatsing in de stijl en de overgang uit: een
 * animatie die achter je hand aan hobbelt voelt als vertraging. Bij het loslaten
 * valt de stijl weg en neemt de klasse het over, met overgang, zodat hij naar
 * buiten glijdt of terugveert.
 */
const dragStyle = computed(() => (dragging.value ? { transform: 'translateX(' + drag.value + 'px)' } : {}))

const onPointerDown = (event) => {
    if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return

    pointer_id = event.pointerId
    start_x = last_x = event.clientX
    start_y = event.clientY
    last_time = event.timeStamp
    speed = 0
    axis = null
    drag.value = 0
}

const onPointerMove = (event) => {
    if (pointer_id !== event.pointerId) return

    const dx = event.clientX - start_x
    const dy = event.clientY - start_y

    /**
     * Vanaf hier meelopen, en niet vanaf waar de vinger neerkwam: anders springt
     * de balk de eerste acht pixels in één keer bij, en juist dat sprongetje
     * verraadt dat er iets tussen jou en het ding in zit.
     */
    if (axis === null) {
        if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) return

        axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y'

        if (axis === 'x') {
            anchor_x = event.clientX
            dragging.value = true
            navEl.value?.setPointerCapture(event.pointerId)
        }

        return
    }

    if (axis !== 'x') return

    const elapsed = event.timeStamp - last_time

    if (elapsed > 0) speed = (event.clientX - last_x) / elapsed

    last_x = event.clientX
    last_time = event.timeStamp
    drag.value = event.clientX - anchor_x
}

const onPointerUp = (event) => {
    if (pointer_id !== event.pointerId) return

    const moved = Math.abs(drag.value)
    const flicked = moved > 12 && Math.abs(speed) > FLICK_SPEED && Math.sign(speed) === Math.sign(drag.value)

    if (axis === 'x' && (moved >= COMMIT_AT || flicked)) {
        tucked.value = drag.value < 0 ? 'left' : 'right'
        creating.value = false
    }

    /** Wie de balk verschoven heeft, bedoelde geen tik op de knop eronder. */
    just_dragged = moved > 4

    dragging.value = false
    drag.value = 0
    pointer_id = null
    axis = null
}

const onClickCapture = (event) => {
    if (!just_dragged) return

    just_dragged = false
    event.preventDefault()
    event.stopPropagation()
}
</script>
