<template>
    <OfflineBanner />
    <PushPermissionBanner v-if="page.props.auth?.user" />
    <div class="min-h-dvh bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-slate-100 transition-colors">
        <TransitionRoot as="template" :show="sidebarOpen">
            <Dialog class="relative z-50 lg:hidden" @close="sidebarOpen = false">
                <TransitionChild as="template" enter="transition-opacity ease-linear duration-300"
                    enter-from="opacity-0" enter-to="opacity-100" leave="transition-opacity ease-linear duration-300"
                    leave-from="opacity-100" leave-to="opacity-0">
                    <div class="fixed inset-0 bg-gray-900/80" />
                </TransitionChild>

                <div class="fixed inset-0 flex">
                    <!--
                        Het menu is op een telefoon het hele scherm, zoals in het
                        ontwerp: geen strook pagina die er half achter vandaan
                        piept. De sluitknop staat daarom in het menu zelf.

                        Deze uitleg staat buiten TransitionChild en niet erin: met
                        as="template" mag daar precies één knooppunt in staan, en
                        een commentaar telt mee. Stond het erbinnen, dan kon de ref
                        niet doorgegeven worden en bleef het menu onzichtbaar —
                        met alleen de laag eroverheen die klikken tegenhield.
                    -->
                    <TransitionChild as="template" enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-full" enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform" leave-from="translate-x-0"
                        leave-to="-translate-x-full">
                        <DialogPanel class="relative flex w-full flex-1">
                            <button type="button"
                                class="absolute top-5 right-4 z-10 rounded-lg p-2 text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-text"
                                @click="sidebarOpen = false">
                                <span class="sr-only">Menu sluiten</span>
                                <XMarkIcon class="size-6" aria-hidden="true" />
                            </button>

                            <MenuSidebar :allow-collapse="false" @navigate="sidebarOpen = false" @logout="logout" />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <!--
            Ingeklapt is nu een smalle balk en niet meer niets: de pictogrammen
            blijven staan, met de naam bij het aanwijzen. De knop om te wisselen
            zit in de zijbalk zelf, waar hij hoort.
        -->
        <div class="menu-shell hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:flex-col overflow-hidden"
            :style="{ width: collapsed ? '4.5rem' : '18rem' }">
            <MenuSidebar @logout="logout" />
        </div>

        <!--
            Zoals in het ontwerp: terug en het logo links, zoeken en meldingen
            rechts, en verder niets. Het menu en het profiel staan onderin binnen duimbereik,
            dus die hoeven hier niet nog een keer. Hij schuift met de duim mee weg
            en terug; useHeaderReveal zet hem op zijn plek.
        -->
        <div ref="headerEl"
            class="sticky top-0 z-40 flex items-center justify-between bg-sidebar-bg px-4 py-4 follows-app-header will-change-transform lg:hidden"
            @focusin="revealHeader">
            <div class="flex items-center gap-x-1">
                <button type="button" class="-ml-2 p-2 text-sidebar-muted hover:text-lavoro-green" @click="goBack">
                    <span class="sr-only">Terug</span>
                    <ArrowLeftIcon class="size-6" aria-hidden="true" />
                </button>
                <img src="/img/logo-neg.svg" class="h-8" alt="Lavoro">
            </div>
            <div class="flex items-center gap-x-1">
                <!-- Een pagina zet hier met <Teleport defer to="#app-header-actions"> wat in deze balk hoort. -->
                <div id="app-header-actions" class="flex items-center empty:hidden"></div>
                <button type="button" class="p-2 text-sidebar-muted hover:text-lavoro-green" @click="openNavigator">
                    <span class="sr-only">Zoeken</span>
                    <MagnifyingGlassIcon class="size-6" aria-hidden="true" />
                </button>
                <NotificationBell sheet button-class="size-10 text-sidebar-muted hover:text-lavoro-green" />
            </div>
        </div>

        <main
            :class="[page.props.noPadding ? 'pb-24 lg:pb-0' : 'pt-4 pb-28 lg:pb-10', collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-72', 'bg-svg menu-pad min-h-[100dvh]']">
            <div :class="[page.props.noPadding ? 'px-4 pt-4' : 'px-4 sm:px-6 lg:px-8', 'empty:hidden']">
                <PageNotice v-if="showGoogleReconnectBanner" tone="warning" :icon="CalendarIcon"
                    title="Google Agenda-synchronisatie gepauzeerd" action-label="Opnieuw koppelen"
                    action-href="/google/oauth/start" dismissible @dismiss="dismissGoogleBanner"
                    text="Koppel je Google Agenda opnieuw om je afspraken weer te synchroniseren." />
                <AssistantFilterBanner />
            </div>
            <div :class="[page.props.noPadding ? '' : 'px-4 sm:px-6 lg:px-8', 'max-w-full']">
                <slot></slot>
            </div>
        </main>
    </div>
    <MobileTabBar v-if="page.props.auth?.user" :menu-open="sidebarOpen" @menu="sidebarOpen = !sidebarOpen" />
    <GlobalNotification />
    <InternalAnnouncementBar v-if="page.props.auth?.user" />
    <LogoutOverlay />

        <!--
            Mounted once for the whole application: the shortcut has to work on
            every page, and one listener beats one per page that forgets to clean
            itself up. It renders nothing until summoned.
        -->
        <AssistantSpotlight v-if="canUseAssistant" />

        <!-- Ungated on purpose: it finds nothing you could not already reach. -->
        <NavigatorSpotlight />

        <TrayDock v-if="page.props.auth?.user" />

        <!--
            De rondleidingen: één laag voor de hele applicatie. Een pagina die er
            een aanbiedt zet alleen een knop neer; deze laag doet de rest.
        -->
        <TourLayer v-if="page.props.auth?.user" />
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { ArrowLeftIcon, CalendarIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { usePage, router } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities'
import { useMenu } from '@/Composables/useMenu.js'
import MenuSidebar from '@/Components/Layout/MenuSidebar.vue'
import GlobalNotification from '@/Components/GlobalNotification.vue'
import InternalAnnouncementBar from '@/Components/InternalAnnouncementBar.vue'
import OfflineBanner from '@/Components/UI/OfflineBanner.vue'
import PushPermissionBanner from '@/Components/UI/PushPermissionBanner.vue'
import LogoutOverlay from '@/Components/UI/LogoutOverlay.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
import MobileTabBar from '@/Components/Layout/MobileTabBar.vue'
import AssistantSpotlight from '@/Components/Assistant/AssistantSpotlight.vue'
import AssistantFilterBanner from '@/Components/Assistant/AssistantFilterBanner.vue'
import PageNotice from '@/Components/UI/PageNotice.vue'
import NavigatorSpotlight from '@/Components/Navigator/NavigatorSpotlight.vue'
import TrayDock from '@/Components/Tray/TrayDock.vue'
import TourLayer from '@/Components/Tour/TourLayer.vue'
import { openNavigator } from '@/Composables/useNavigator.js'
import { useCapacitor } from '@/Composables/useCapacitor.js'
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js'
import { useLocationTracker } from '@/Composables/useLocationTracker.js'
import { usePushNotifications } from '@/Composables/usePushNotifications.js'
import { useWebPush } from '@/Composables/useWebPush.js'
import { useDeepLinks } from '@/Composables/useDeepLinks.js'
import { useHeaderReveal } from '@/Composables/useHeaderReveal.js'
import { confirmLeaving } from '@/Utilities/historyGuard'

const canUseAssistant = computed(() => usePage().props?.auth?.can?.use_assistant === true)

const { is_native } = useCapacitor()
const { init: init_network } = useNetworkStatus()
const { start: start_tracking, stop: stop_tracking } = useLocationTracker()
const {
    register: register_push,
    unregister: unregister_push,
    askBatteryExemption: ask_battery_exemption,
} = usePushNotifications()
const { sync: sync_web_push, disable: disable_web_push } = useWebPush()
const { init: init_deep_links } = useDeepLinks()

const page = usePage()
const { authUser, collapsed, loggingOut, setLoggingOut } = useMenu()

const headerEl = ref(null)
const { reveal: revealHeader } = useHeaderReveal(headerEl)

onMounted(async () => {
    /**
     * De vlag leeft buiten dit onderdeel en overleeft dus een afmelding. Staat
     * deze laag er weer, dan zijn we ingelogd en is er niets meer gaande.
     */
    setLoggingOut(false)

    try { await init_network() } catch (e) { console.error('Network initialization failed:', e) }
    if (is_native && page.props.auth?.user) {
        try { await init_deep_links() } catch (e) { console.error('Deep link init failed:', e) }
        await register_push()
    }
    if (!is_native && page.props.auth?.user) {
        try { await sync_web_push() } catch (e) { console.error('Web push sync failed:', e) }
    }
    if (page.props.auth?.user && hasPermission('location.track')) {
        try {
            await start_tracking()
        } catch (e) {
            console.error('GPS tracking failed to start:', e)
        }
    }

    /**
     * Wie ingepland wordt krijgt herinneringen, en die moeten op tijd komen. Pas
     * na de locatie: wie de vraag laat staan, houdt anders ook die op.
     */
    if (page.props.auth?.user?.plannable) {
        try { await ask_battery_exemption() } catch (e) { console.error('Battery exemption failed:', e) }
    }
})

const googleBannerDismissed = ref(typeof window !== 'undefined' && window.sessionStorage?.getItem('google_banner_dismissed') === '1')
const showGoogleReconnectBanner = computed(() => !!authUser.value?.google_integration?.disabled_at && !googleBannerDismissed.value)
const dismissGoogleBanner = () => {
    googleBannerDismissed.value = true
    if (typeof window !== 'undefined') {
        window.sessionStorage?.setItem('google_banner_dismissed', '1')
    }
}

const sidebarOpen = ref(false)

/**
 * Terug naar de vorige pagina, zoals de terugknop van de browser die een app op
 * een telefoon niet heeft. Wie hier binnenkwam zonder vorige pagina, vanuit een
 * link of een melding, gaat naar het begin.
 */
const goBack = () => {
    if (window.history.length > 1) {
        window.history.back()
    } else {
        router.visit('/')
    }
}

const LOGOUT_PATH = '/logout'

/**
 * Duurt merkbaar lang, want er wordt van alles afgebroken voordat we weggaan.
 * Zonder terugkoppeling lijkt de knop kapot en drukt iedereen twee keer, dus de
 * stand gaat aan voordat het eerste trage stuk begint.
 *
 * Eerst mag de open pagina zeggen of er werk verloren gaat. Daarna pas het
 * afbreken: wie dan nog blijft, zou ingelogd zijn zonder locatie, meldingen of
 * service worker. Een ja geldt ook voor het bezoek aan /logout zelf, dus de
 * vraag komt niet twee keer. Lukt het afmelden toch niet, dan staan we nog op
 * een ingelogde pagina en gaat de stand weer uit, zodat het opnieuw kan.
 */
const logout = async () => {
    if (loggingOut.value || !confirmLeaving(LOGOUT_PATH)) return

    setLoggingOut(true)

    try {
        await stop_tracking()

        // Voor het afmelden, want daarna is de service worker weg en kan het
        // abonnement niet meer opgezegd worden — de server zou dan een regel houden
        // die nooit meer iets kan bezorgen.
        try { await disable_web_push() } catch (e) { console.error('Push unsubscribe failed:', e) }
        await unregister_push()

        if ('serviceWorker' in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations()
            for (const registration of registrations) {
                registration.unregister()
            }
        }
        const caches = await window.caches.keys()
        await Promise.all(caches.map(cache => window.caches.delete(cache)))
    } catch (e) {
        console.error('Logout cleanup failed:', e)
    }

    router.get(LOGOUT_PATH, {}, {
        onFinish: () => {
            if (page.props.auth?.user) setLoggingOut(false)
        },
    })
}
</script>
