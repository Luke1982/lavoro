<template>
    <OfflineBanner />
    <UpdateBanner />
    <div class="min-h-screen bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-slate-100 transition-colors">
        <TransitionRoot as="template" :show="sidebarOpen">
            <Dialog class="relative z-50 lg:hidden" @close="sidebarOpen = false">
                <TransitionChild as="template" enter="transition-opacity ease-linear duration-300"
                    enter-from="opacity-0" enter-to="opacity-100" leave="transition-opacity ease-linear duration-300"
                    leave-from="opacity-100" leave-to="opacity-0">
                    <div class="fixed inset-0 bg-gray-900/80" />
                </TransitionChild>

                <div class="fixed inset-0 flex">
                    <TransitionChild as="template" enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-full" enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform" leave-from="translate-x-0"
                        leave-to="-translate-x-full">
                        <DialogPanel class="relative mr-16 flex w-full max-w-xs flex-1">
                            <TransitionChild as="template" enter="ease-in-out duration-300" enter-from="opacity-0"
                                enter-to="opacity-100" leave="ease-in-out duration-300" leave-from="opacity-100"
                                leave-to="opacity-0">
                                <div class="absolute top-0 left-full flex w-16 justify-center pt-5">
                                    <button type="button" class="-m-2.5 p-2.5" @click="sidebarOpen = false">
                                        <span class="sr-only">Close sidebar</span>
                                        <XMarkIcon class="size-6 text-white" aria-hidden="true" />
                                    </button>
                                </div>
                            </TransitionChild>

                            <SidebarContent @navigate="sidebarOpen = false" @logout="logout" />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:flex-col overflow-hidden"
            :style="{ width: desktopCollapsed ? '0px' : '18rem', transition: 'width 300ms ease-in-out' }">
            <SidebarContent @logout="logout" />
        </div>

        <button
            class="hidden lg:flex fixed z-[60] top-6 items-center justify-center w-6 h-6 rounded-full bg-white text-gray-500 hover:text-gray-900 shadow-md border border-gray-200"
            :style="{ left: desktopCollapsed ? '8px' : 'calc(18rem - 12px)', transition: 'left 300ms ease-in-out' }"
            @click="toggleDesktopSidebar">
            <ChevronLeftIcon class="size-3.5 transition-transform duration-300"
                :class="desktopCollapsed ? 'rotate-180' : ''" />
        </button>

        <div class="sticky top-0 z-40 flex items-center gap-x-6 bg-sidebar-bg px-4 py-4 shadow-xs sm:px-6 lg:hidden">
            <button type="button" class="-m-2.5 p-2.5 text-sidebar-muted lg:hidden" @click="toggleSidebar">
                <span class="sr-only">Open sidebar</span>
                <Bars3Icon class="size-6" aria-hidden="true" />
            </button>
            <div class="flex-1 text-sm/6 font-semibold text-white">{{ currentTopTitle }}</div>
            <img src="/img/logo-neg.svg" class="h-6" alt="">
            <Link :href="'/me/edit'">
                <span class="sr-only">Profiel</span>
                <div class="size-8 rounded-full bg-sidebar-card overflow-hidden flex items-center justify-center">
                    <img v-if="authUser?.avatar" :src="authUser.avatar" class="object-cover w-full h-full" />
                    <span v-else class="text-xs font-medium text-white">{{ initials }}</span>
                </div>
            </Link>

        </div>

        <main
            :class="[page.props.noPadding ? '' : 'pt-4 pb-10', desktopCollapsed ? 'lg:pl-0' : 'lg:pl-72', 'bg-svg min-h-[100vh] transition-[padding-left] duration-300 ease-in-out']">
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
    <GlobalNotification />
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { Bars3Icon, ChevronLeftIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { Link, usePage, router } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities'
import { useSidebarNav } from '@/Composables/useSidebarNav.js'
import SidebarContent from '@/Components/Layout/SidebarContent.vue'
import GlobalNotification from '@/Components/GlobalNotification.vue'
import OfflineBanner from '@/Components/UI/OfflineBanner.vue'
import UpdateBanner from '@/Components/UI/UpdateBanner.vue'
import { useCapacitor } from '@/Composables/useCapacitor.js'
import { useNetworkStatus } from '@/Composables/useNetworkStatus.js'
import { useLocationTracker } from '@/Composables/useLocationTracker.js'
import { usePushNotifications } from '@/Composables/usePushNotifications.js'
import { useAppUpdate } from '@/Composables/useAppUpdate.js'
import { useDeepLinks } from '@/Composables/useDeepLinks.js'

// Push requires a configured Firebase project (google-services.json on
// Android). Without it, the native PushNotifications.register() throws an
// uncatchable native fatal exception and crashes the app. Flip to true
// only after Firebase is set up.
const PUSH_ENABLED = false

const { is_native } = useCapacitor()
const { init: init_network } = useNetworkStatus()
const { start: start_tracking, stop: stop_tracking } = useLocationTracker()
const { register: register_push } = usePushNotifications()
const { check: check_update } = useAppUpdate()
const { init: init_deep_links } = useDeepLinks()

const page = usePage()
const { authUser, initials, currentTopTitle } = useSidebarNav()

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

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value
}

const desktopCollapsed = ref(
    typeof window !== 'undefined' && localStorage.getItem('desktopSidebarCollapsed') === '1'
)

const toggleDesktopSidebar = () => {
    desktopCollapsed.value = !desktopCollapsed.value
    if (typeof window !== 'undefined') {
        localStorage.setItem('desktopSidebarCollapsed', desktopCollapsed.value ? '1' : '0')
    }
}

const logout = async () => {
    await stop_tracking()
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
