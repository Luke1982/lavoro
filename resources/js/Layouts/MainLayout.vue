<template>
    <div>
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

                            <div
                                class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-6 pb-2 ring-1 ring-white/10">
                                <div class="flex h-16 shrink-0 items-center">
                                    <img class="h-8 w-auto"
                                        src="https://tailwindcss.com/plus-assets/img/logos/mark.svg?color=indigo&shade=500"
                                        alt="Your Company" />
                                </div>

                                <nav class="flex flex-1 flex-col">
                                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                                        <li>
                                            <ul role="list" class="-mx-2 space-y-1">
                                                <li v-for="item in navigation" :key="item.name">
                                                    <div class="flex items-center justify-between">
                                                        <Link :href="item.href" @click="handleNavClick(item)" :class="[
                                                            item.current ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                            'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold flex-1'
                                                        ]">
                                                        <component :is="item.icon" class="size-6 shrink-0"
                                                            aria-hidden="true" />
                                                        {{ item.name }}
                                                        </Link>
                                                        <button v-if="item.children"
                                                            class="p-2 text-gray-400 hover:text-white"
                                                            @click.stop="item.open = !item.open">
                                                            <ChevronDownIcon
                                                                class="size-4 transition-transform duration-200"
                                                                :class="item.open ? 'rotate-180' : ''" />
                                                        </button>
                                                    </div>

                                                    <transition
                                                        enter-active-class="transition-all duration-200 ease-out"
                                                        enter-from-class="max-h-0 opacity-0"
                                                        enter-to-class="max-h-96 opacity-100"
                                                        leave-active-class="transition-all duration-200 ease-in"
                                                        leave-from-class="max-h-96 opacity-100"
                                                        leave-to-class="max-h-0 opacity-0">
                                                        <ul v-if="item.children" v-show="item.open"
                                                            class="overflow-hidden">
                                                            <li v-for="child in item.children" :key="child.name">
                                                                <Link :href="child.href" @click="sidebarOpen = false"
                                                                    :class="[
                                                                        child.current
                                                                            ? 'bg-gray-800 text-white'
                                                                            : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                                        'group flex gap-x-3 rounded-md p-1 text-sm/6 font-medium pl-11'
                                                                    ]">
                                                                <component v-if="child.icon" :is="child.icon"
                                                                    class="size-5 shrink-0" aria-hidden="true" />
                                                                <span>{{ child.name }}</span>
                                                                </Link>
                                                            </li>
                                                        </ul>
                                                    </transition>
                                                </li>
                                            </ul>
                                        </li>

                                        <li>
                                            <div class="text-xs/6 font-semibold text-gray-400">Lijsten</div>
                                            <ul role="list" class="-mx-2 mt-2 space-y-1">
                                                <li v-for="list in lists" :key="list.name">
                                                    <Link :href="list.href" @click="sidebarOpen = false" :class="[
                                                        list.current ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                    ]">
                                                    <span
                                                        class="flex size-6 shrink-0 items-center justify-center rounded-lg border border-gray-700 bg-gray-800 text-[0.625rem] font-medium text-gray-400 group-hover:text-white">{{
                                                            list.initial }}</span>
                                                    <span>{{ list.name }}</span>
                                                    </Link>
                                                </li>
                                            </ul>
                                        </li>

                                        <li class="-mx-6 mt-auto">
                                            <div class="px-6 mb-2 space-y-1">
                                                <Link :href="'/companies'" :class="[
                                                    isCompanyCurrent ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                ]">
                                                <BuildingOffice2Icon class="size-6 shrink-0" />
                                                Bedrijf
                                                </Link>
                                                <Link :href="'\/users'" :class="[
                                                    currentPath.startsWith('/users') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                ]">
                                                <UsersIcon class="size-6 shrink-0" />
                                                Gebruikers
                                                </Link>
                                                <Link :href="'/roles'" :class="[
                                                    currentPath.startsWith('/roles') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                                ]">
                                                <UsersIcon class="size-6 shrink-0" />
                                                Rollen
                                                </Link>
                                            </div>
                                            <Link :href="authUser ? '/users/' + authUser.id : '#'"
                                                class="flex items-center gap-x-4 px-6 py-3 text-sm/6 font-semibold text-white hover:bg-gray-800">
                                            <div
                                                class="size-8 rounded-full bg-gray-800 overflow-hidden flex items-center justify-center">
                                                <img v-if="authUser?.avatar" :src="authUser.avatar"
                                                    class="object-cover w-full h-full" />
                                                <span v-else class="text-xs font-medium text-white">{{ initials
                                                }}</span>
                                            </div>
                                            <span class="sr-only">Profiel</span>
                                            <span aria-hidden="true">{{ authUser?.name || 'Gebruiker' }}</span>
                                            </Link>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
            <div class="flex grow flex-col gap-y-5 overflow-y-auto bg-gray-900 px-6">
                <div class="flex h-16 shrink-0 items-center">
                    <img class="h-8 w-auto"
                        src="https://tailwindcss.com/plus-assets/img/logos/mark.svg?color=indigo&shade=500"
                        alt="Your Company" />
                </div>
                <nav class="flex flex-1 flex-col">
                    <ul role="list" class="flex flex-1 flex-col gap-y-7">
                        <li>
                            <ul role="list" class="-mx-2 space-y-1">
                                <li v-for="item in navigation" :key="item.name">
                                    <div class="flex items-center justify-between">
                                        <Link :href="item.href" @click="updateCurrent(item)" :class="[
                                            item.current ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                            'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold flex-1'
                                        ]">
                                        <component :is="item.icon" class="size-6 shrink-0" aria-hidden="true" />
                                        {{ item.name }}
                                        </Link>
                                        <button v-if="item.children" class="p-2 text-gray-400 hover:text-white"
                                            @click.stop="item.open = !item.open">
                                            <ChevronDownIcon class="size-4 transition-transform duration-200"
                                                :class="item.open ? 'rotate-180' : ''" />
                                        </button>
                                    </div>
                                    <transition enter-active-class="transition-all duration-200 ease-out"
                                        enter-from-class="max-h-0 opacity-0" enter-to-class="max-h-96 opacity-100"
                                        leave-active-class="transition-all duration-200 ease-in"
                                        leave-from-class="max-h-96 opacity-100" leave-to-class="max-h-0 opacity-0">
                                        <ul v-if="item.children" v-show="item.open" class="overflow-hidden">
                                            <li v-for="child in item.children" :key="child.name">
                                                <Link :href="child.href" :class="[
                                                    child.current ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                                    'group flex gap-x-3 rounded-md p-1 text-sm/6 font-medium pl-11'
                                                ]">
                                                <component v-if="child.icon" :is="child.icon" class="size-5 shrink-0"
                                                    aria-hidden="true" />
                                                <span>{{ child.name }}</span>
                                                </Link>
                                            </li>
                                        </ul>
                                    </transition>
                                </li>
                            </ul>
                        </li>

                        <li>
                            <div class="text-xs/6 font-semibold text-gray-400">Lijsten</div>
                            <ul role="list" class="-mx-2 mt-2 space-y-1">
                                <li v-for="list in lists" :key="list.name">
                                    <Link :href="list.href" @click="sidebarOpen = false" :class="[
                                        list.current ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                        'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                    ]">
                                    <span
                                        class="flex size-6 shrink-0 items-center justify-center rounded-lg border border-gray-700 bg-gray-800 text-[0.625rem] font-medium text-gray-400 group-hover:text-white">{{
                                            list.initial }}</span>
                                    <span>{{ list.name }}</span>
                                    </Link>
                                </li>
                            </ul>
                        </li>

                        <li class="-mx-6 mt-auto">
                            <div class="px-6 mb-2 space-y-1">
                                <Link :href="'/companies'" :class="[
                                    isCompanyCurrent ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                ]">
                                <BuildingOffice2Icon class="size-6 shrink-0" />
                                Bedrijf
                                </Link>
                                <Link :href="'\/users'" :class="[
                                    currentPath.startsWith('/users') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                ]">
                                <UsersIcon class="size-6 shrink-0" />
                                Gebruikers
                                </Link>
                                <Link :href="'/roles'" :class="[
                                    currentPath.startsWith('/roles') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white',
                                    'group flex gap-x-3 rounded-md p-2 text-sm/6 font-semibold'
                                ]">
                                <UsersIcon class="size-6 shrink-0" />
                                Rollen
                                </Link>
                            </div>
                            <Link :href="authUser ? '/users/' + authUser.id : '#'"
                                class="flex items-center gap-x-4 px-6 py-3 text-sm/6 font-semibold text-white hover:bg-gray-800">
                            <div
                                class="size-8 rounded-full bg-gray-800 overflow-hidden flex items-center justify-center">
                                <img v-if="authUser?.avatar" :src="authUser.avatar"
                                    class="object-cover w-full h-full" />
                                <span v-else class="text-xs font-medium text-white">{{ initials }}</span>
                            </div>
                            <span class="sr-only">Profiel</span>
                            <span aria-hidden="true">{{ authUser?.name || 'Gebruiker' }}</span>
                            </Link>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>

        <div class="sticky top-0 z-40 flex items-center gap-x-6 bg-gray-900 px-4 py-4 shadow-xs sm:px-6 lg:hidden">
            <button type="button" class="-m-2.5 p-2.5 text-gray-400 lg:hidden" @click="sidebarOpen = true">
                <span class="sr-only">Open sidebar</span>
                <Bars3Icon class="size-6" aria-hidden="true" />
            </button>
            <div class="flex-1 text-sm/6 font-semibold text-white">Dashboard</div>
            <Link :href="authUser ? '/users/' + authUser.id : '#'">
            <span class="sr-only">Profiel</span>
            <div class="size-8 rounded-full bg-gray-800 overflow-hidden flex items-center justify-center">
                <img v-if="authUser?.avatar" :src="authUser.avatar" class="object-cover w-full h-full" />
                <span v-else class="text-xs font-medium text-white">{{ initials }}</span>
            </div>
            </Link>

        </div>

        <main :class="[page.props.noPadding ? '' : 'py-10', 'lg:pl-72']">
            <div :class="[page.props.noPadding ? '' : 'px-4 sm:px-6 lg:px-8']">
                <slot></slot>
            </div>
        </main>
    </div>
    <GlobalNotification />
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue'
import {
    Bars3Icon,
    CalendarIcon,
    CheckIcon,
    CubeIcon,
    ExclamationCircleIcon,
    FingerPrintIcon,
    HomeIcon,
    PuzzlePieceIcon,
    Square3Stack3DIcon,
    UsersIcon,
    XMarkIcon,
    SwatchIcon,
    ChevronDownIcon,
    Squares2X2Icon,
    FolderIcon,
    ScaleIcon,
    AdjustmentsHorizontalIcon,
    BuildingOffice2Icon
} from '@heroicons/vue/24/outline'
import { Link, usePage } from '@inertiajs/vue3'
import GlobalNotification from '@/Components/GlobalNotification.vue'

const page = usePage()
const authUser = computed(() => page.props.auth.user)
const initials = computed(() => authUser.value?.name ? authUser.value.name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() : '')

const navigation = ref([
    { name: 'Dashboard', href: '/', icon: HomeIcon, current: true },
    { name: 'Klanten', href: '/customers', icon: UsersIcon, current: false },
    {
        name: 'Producten',
        href: '/products',
        icon: CubeIcon,
        current: false,
        children: [
            { name: 'Product types', href: '/producttypes', icon: Square3Stack3DIcon, current: false },
            { name: 'Merken', href: '/brands', icon: FingerPrintIcon, current: false },
        ],
        open: false,
    },
    { name: 'Machines', href: '/assets', icon: PuzzlePieceIcon, current: false },
    { name: 'Storingen', href: '/tickets', icon: ExclamationCircleIcon, current: false },
    {
        name: 'Keurpunten',
        href: '/servicechecks',
        icon: CheckIcon,
        current: false,
        children: [
            { name: 'Groepen', href: '/servicecheckgroups', icon: Squares2X2Icon, current: false }
        ],
        open: false,
    },
    {
        name: 'Materialen',
        href: '/materials',
        icon: SwatchIcon,
        current: false,
        children: [
            { name: 'Categorieën', href: '/materialcategories', icon: FolderIcon, current: false },
            { name: 'Gebruikseenheden', href: '/materialusageunits', icon: ScaleIcon, current: false }
        ],
        open: false,
    },
    {
        name: 'Agenda',
        href: '/events',
        icon: CalendarIcon,
        current: false,
        children: [{ name: 'Afspraaktypes', href: '/eventtypes', icon: AdjustmentsHorizontalIcon, current: false }],
        open: false,
    }
])

// Open the submenu if current route is within its children
const currentPath = typeof window !== 'undefined' ? window.location.pathname : ''

// Load persisted open state
const savedOpenState = typeof window !== 'undefined' ? JSON.parse(localStorage.getItem('navOpenState') || '{}') : {}

navigation.value.forEach((item) => {
    if (item.children) {
        if (Object.prototype.hasOwnProperty.call(savedOpenState, item.name)) {
            item.open = !!savedOpenState[item.name]
        } else if (item.children.some(c => c.href === currentPath)) {
            item.open = true
            item.current = true
        }
    } else if (item.href === currentPath) {
        item.current = true
    }
})

// Persist open state on changes
watch(navigation, (val) => {
    if (typeof window === 'undefined') return
    const state = {}
    val.forEach((item) => {
        if (item.children) state[item.name] = !!item.open
    })
    localStorage.setItem('navOpenState', JSON.stringify(state))
}, { deep: true })

const lists = [
    { id: 1, name: 'Aankomende keuringen en storingen', href: '/upcomingactivities', initial: 'A', current: false }
]

const isCompanyCurrent = computed(() => currentPath === '/companies')

/**
 * Set the active top-level nav gation item.
 * @param {{name: tring}} item
 */
const updateCurrent = (item) => {
    navigation.value.forEach((navItem) => {
        navItem.current = navItem.name === item.name
    })
}

/**
 * Handle a mobile navigation click: update active item and close the sidebar.
 * @param {{name:string}} item
 */
const handleNavClick = (item) => {
    updateCurrent(item)
    sidebarOpen.value = false
}

const sidebarOpen = ref(false)
</script>