<template>
    <OfflineBanner />
    <UpdateBanner />
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
            Zoals in het ontwerp: het logo links, zoeken en meldingen rechts, en
            verder niets. Het menu en het profiel staan onderin binnen duimbereik,
            dus die hoeven hier niet nog een keer.
        -->
        <div class="sticky top-0 z-40 flex items-center justify-between bg-sidebar-bg px-4 py-4 lg:hidden">
            <img src="/img/logo-neg.svg" class="h-8" alt="Lavoro">
            <div class="flex items-center gap-x-1">
                <button type="button" class="p-2 text-sidebar-muted hover:text-lavoro-green" @click="openNavigator">
                    <span class="sr-only">Zoeken</span>
                    <MagnifyingGlassIcon class="size-6" aria-hidden="true" />
                </button>
                <NotificationBell button-class="size-10 text-sidebar-muted hover:text-lavoro-green" />
            </div>
        </div>

        <main
            :class="[page.props.noPadding ? 'pb-24 lg:pb-0' : 'pt-4 pb-28 lg:pb-10', collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-72', 'bg-svg menu-pad min-h-[100dvh]']">
            <div v-if="showGoogleReconnectBanner"
                class="bg-amber-100 border-b border-amber-300 px-4 py-2 text-sm text-amber-900 flex items-center justify-between dark:bg-amber-950 dark:border-amber-800 dark:text-amber-200">
                <span>Je Google Agenda synchronisatie is gepauzeerd.</span>
                <span class="flex items-center gap-3">
                    <a href="/google/oauth/start" class="underline">Opnieuw koppelen</a>
                    <button type="button" class="text-lg leading-none" @click="dismissGoogleBanner">&times;</button>
                </span>
            </div>
            <div :class="[page.props.noPadding ? '' : 'px-4 sm:px-6 lg:px-8', 'max-w-full']">
                <slot></slot>
            </div>
        </main>
    </div>
    <MobileTabBar v-if="page.props.auth?.user" :menu-open="sidebarOpen" @menu="sidebarOpen = !sidebarOpen" />
    <GlobalNotification />

        <!--
            Mounted once for the whole application: the shortcut has to work on
            every page, and one listener beats one per page that forgets to clean
            itself up. It renders nothing until summoned.
        -->
        <AssistantSpotlight v-if="canUseAssistant" />

        <!-- Ungated on purpose: it finds nothing you could not already reach. -->
        <NavigatorSpotlight />
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { usePage, router } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities'
import { useMenu } from '@/Composables/useMenu.js'
import MenuSidebar from '@/Components/Layout/MenuSidebar.vue'
import GlobalNotification from '@/Components/GlobalNotification.vue'
import OfflineBanner from '@/Components/UI/OfflineBanner.vue'
import UpdateBanner from '@/Components/UI/UpdateBanner.vue'
import PushPermissionBanner from '@/Components/UI/PushPermissionBanner.vue'
import NotificationBell from '@/Components/Notifications/NotificationBell.vue'
import MobileTabBar from '@/Components/Layout/MobileTabBar.vue'
import AssistantSpotlight from '@/Components/Assistant/AssistantSpotlight.vue'
import NavigatorSpotlight from '@/Components/Navigator/NavigatorSpotlight.vue'
import { openNavigator } from '@/Composables/useNavigator.js'
import { useCapacitor } from '@/Composables/useCapacitor.js'
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js'
import { useLocationTracker } from '@/Composables/useLocationTracker.js'
import { usePushNotifications } from '@/Composables/usePushNotifications.js'
import { useWebPush } from '@/Composables/useWebPush.js'
import { useAppUpdate } from '@/Composables/useAppUpdate.js'
import { useDeepLinks } from '@/Composables/useDeepLinks.js'

// Push requires a configured Firebase project (google-services.json on
// Android). Without it, the native PushNotifications.register() throws an
// uncatchable native fatal exception and crashes the app. Flip to true
// only after Firebase is set up.
const PUSH_ENABLED = false

const canUseAssistant = computed(() => usePage().props?.auth?.can?.use_assistant === true)

const { is_native } = useCapacitor()
const { init: init_network } = useNetworkStatus()
const { start: start_tracking, stop: stop_tracking } = useLocationTracker()
const { register: register_push } = usePushNotifications()
const { sync: sync_web_push, disable: disable_web_push } = useWebPush()
const { check: check_update } = useAppUpdate()
const { init: init_deep_links } = useDeepLinks()

const page = usePage()
const { authUser, collapsed } = useMenu()

onMounted(async () => {
    try { await init_network() } catch (e) { console.error('Network initialization failed:', e) }
    if (is_native && page.props.auth?.user) {
        try { await init_deep_links() } catch (e) { console.error('Deep link init failed:', e) }
        try { await check_update() } catch (e) { console.error('Update check failed:', e) }
        if (PUSH_ENABLED) {
            try {
                await register_push()
            } catch (e) {
                console.error('Push registration failed:', e)
            }
        }
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

const logout = async () => {
    await stop_tracking()

    // Voor het afmelden, want daarna is de service worker weg en kan het
    // abonnement niet meer opgezegd worden — de server zou dan een regel houden
    // die nooit meer iets kan bezorgen.
    try { await disable_web_push() } catch (e) { console.error('Push unsubscribe failed:', e) }

    if ('serviceWorker' in navigator) {
        const registrations = await navigator.serviceWorker.getRegistrations()
        for (const registration of registrations) {
            registration.unregister()
        }
    }
    const caches = await window.caches.keys()
    await Promise.all(caches.map(cache => window.caches.delete(cache)))

    router.get('/logout')
}
</script>
