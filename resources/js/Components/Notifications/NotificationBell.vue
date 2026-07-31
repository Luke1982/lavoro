<template>
    <!--
        Op een telefoon een blad onder de kop en geen uitklapper naast de knop.
        Een uitklapper hangt aan het belletje, dat vlak bij de rand staat, en kan
        daardoor nooit in het midden staan — hij kan hooguit net binnen beeld
        geduwd worden, met ongelijke randen als gevolg.
    -->
    <template v-if="sheet">
        <button type="button" class="relative flex items-center justify-center rounded-lg transition-colors"
            :class="buttonClass" :aria-label="ariaLabel" :aria-expanded="showing" @click="toggleSheet">
            <span class="relative flex">
                <Bell class="size-5" :class="label ? 'size-6' : ''" />
                <span v-if="unreadCount"
                    class="absolute -top-1.5 -right-2 flex min-w-4 items-center justify-center rounded-full bg-lavoro-blue px-1 text-[10px] font-semibold text-white">
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </span>
            </span>
            <span v-if="label" class="text-[11px] leading-none">{{ label }}</span>
        </button>

        <Teleport to="body">
            <Transition enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0"
                enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150 ease-in"
                leave-from-class="opacity-100" leave-to-class="opacity-0">
                <div v-if="showing" class="fixed inset-0 z-40 bg-slate-950/60" @click="showing = false"></div>
            </Transition>

            <Transition enter-active-class="transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
                enter-from-class="-translate-y-3 opacity-0" enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition-all duration-150 ease-in" leave-from-class="translate-y-0 opacity-100"
                leave-to-class="-translate-y-2 opacity-0">
                <NotificationPanel v-if="showing" class="fixed inset-x-3 top-20 z-50 max-h-[70vh]"
                    @close="showing = false" />
            </Transition>
        </Teleport>
    </template>

    <!--
        Beide manieren delen één schakelaar, zodat het paneel op dezelfde manier
        dichtgaat: bij het weggaan naar een pagina, en bij een klik op de regel
        onderin. Zonder dat bleef de uitklapper openstaan nadat je hem gebruikt had.
    -->
    <VDropdown v-else v-model:shown="showing" :placement="placement" :distance="10" popper-class="nav-popper"
        @show="open">
        <button type="button" class="relative flex items-center justify-center rounded-lg transition-colors"
            :class="buttonClass" :aria-label="ariaLabel">
            <span class="relative flex">
                <Bell class="size-5" :class="label ? 'size-6' : ''" />
                <span v-if="unreadCount"
                    class="absolute -top-1.5 -right-2 flex min-w-4 items-center justify-center rounded-full bg-lavoro-blue px-1 text-[10px] font-semibold text-white">
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </span>
            </span>
            <span v-if="label" class="text-[11px] leading-none">{{ label }}</span>
        </button>

        <template #popper>
            <NotificationPanel class="max-h-[32rem] w-[22rem]" @close="showing = false" />
        </template>
    </VDropdown>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Bell } from '@lucide/vue'
import NotificationPanel from '@/Components/Notifications/NotificationPanel.vue'
import { useNotifications } from '@/Composables/useNotifications.js'

/**
 * Het belletje en de teller erop. Haalt pas iets op als het opengaat: op elke
 * pagina meldingen ophalen voor een lijst die niemand opent is verkeer om niets,
 * en de teller staat al goed zonder.
 */
defineProps({
    placement: { type: String, default: 'bottom-end' },
    buttonClass: {
        type: String,
        default: 'size-10 text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-text',
    },

    /** Gezet in de balk onderaan, waar elk pictogram zijn naam eronder draagt. */
    label: { type: String, default: '' },

    /** Op een telefoon: een blad onder de kop in plaats van een uitklapper. */
    sheet: { type: Boolean, default: false },
})

const { unreadCount, open } = useNotifications()

const page = usePage()
const showing = ref(false)

const ariaLabel = computed(() => 'Meldingen' + (unreadCount.value ? ', ' + unreadCount.value + ' ongelezen' : ''))

const toggleSheet = () => {
    showing.value = !showing.value

    if (showing.value) open()
}

/** Wie ergens heen gaat, is klaar met lezen. */
watch(() => page.url, () => { showing.value = false })
</script>
