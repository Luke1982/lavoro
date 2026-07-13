<template>
    <div class="flex grow flex-col overflow-y-auto bg-sidebar-bg px-4 pb-4">
        <div class="flex shrink-0 flex-col items-start px-2 pt-4 pb-2">
            <img src="/img/logo-neg.svg" alt="Lavoro" class="h-12">
        </div>
        <nav class="flex flex-1 flex-col">
            <ul role="list" class="flex flex-1 flex-col gap-y-6">
                <li>
                    <ul role="list" class="space-y-0.5">
                        <li v-for="(item, index) in filteredNavigation" :key="item.name" class="sidebar-item"
                            :style="{ animationDelay: (index * 30) + 'ms' }">
                            <Link v-if="!visibleChildren(item).length" :href="item.href" @click="onTopNav(item)"
                                :class="[rowStateClass(item.current), 'group flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-150']">
                                <component :is="item.icon" class="size-5 shrink-0" aria-hidden="true" />
                                <span>{{ item.name }}</span>
                            </Link>
                            <div v-else>
                                <div
                                    :class="[rowStateClass(item.current, item.open), 'group flex items-center rounded-lg pr-1 transition-colors duration-150']">
                                    <Link :href="item.href" @click="onTopNav(item)"
                                        class="flex flex-1 items-center gap-x-3 px-3 py-2 text-sm font-medium">
                                        <component :is="item.icon" class="size-5 shrink-0" aria-hidden="true" />
                                        <span>{{ item.name }}</span>
                                    </Link>
                                    <button class="rounded-md p-1 opacity-70 transition hover:opacity-100"
                                        @click.stop="toggleSection(item)">
                                        <ChevronDownIcon class="size-4 transition-transform duration-300"
                                            :class="item.open ? 'rotate-180' : ''" />
                                    </button>
                                </div>
                                <transition enter-active-class="transition-all duration-300 ease-out"
                                    enter-from-class="max-h-0 opacity-0" enter-to-class="max-h-96 opacity-100"
                                    leave-active-class="transition-all duration-200 ease-in"
                                    leave-from-class="max-h-96 opacity-100" leave-to-class="max-h-0 opacity-0">
                                    <ul v-show="item.open"
                                        :class="[item.current ? 'bg-[radial-gradient(130%_80%_at_50%_0%,rgba(30,64,255,0.22),transparent_70%)]' : '', 'relative ml-[22px] mt-1 space-y-0.5 overflow-hidden rounded-b-xl border-l border-sidebar-border pb-1.5 pl-4 transition-colors duration-200']">
                                        <li v-for="child in visibleChildren(item)" :key="child.name" class="relative">
                                            <Link :href="child.href" @click="$emit('navigate')"
                                                :class="[child.current ? 'text-sidebar-text' : 'text-sidebar-muted hover:text-sidebar-text', 'group/sub flex items-center gap-x-2.5 rounded-md py-1.5 pr-2 text-[13px] transition-colors duration-150']">
                                                <span
                                                    class="absolute top-1/2 -left-4 -translate-x-1/2 -translate-y-1/2 rounded-full transition-all duration-200"
                                                    :class="child.current ? 'size-1.5 bg-sidebar-indicator shadow-[0_0_6px_rgba(184,255,61,0.55)]' : 'size-1 bg-slate-700 group-hover/sub:bg-slate-500'"></span>
                                                <span>{{ child.name }}</span>
                                            </Link>
                                        </li>
                                    </ul>
                                </transition>
                            </div>
                        </li>
                    </ul>
                </li>

                <li v-if="filteredLists.length">
                    <div class="px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted">Lijsten</div>
                    <ul role="list" class="mt-2 space-y-0.5">
                        <li v-for="list in filteredLists" :key="list.name">
                            <Link :href="list.href" @click="$emit('navigate')"
                                :class="[rowStateClass(list.current), 'group flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-150']">
                                <span
                                    class="flex size-6 shrink-0 items-center justify-center rounded-lg border border-sidebar-border bg-sidebar-card text-[0.625rem] font-medium text-sidebar-muted group-hover:text-sidebar-text">{{
                                        list.initial }}</span>
                                <span>{{ list.name }}</span>
                            </Link>
                        </li>
                    </ul>
                </li>

                <li class="mt-auto flex flex-col gap-y-3 pt-2">
                    <div class="space-y-0.5" v-if="isAdmin">
                        <img v-if="companyLogo" class="mb-2 ml-3 h-8 w-auto" :src="companyLogo"
                            :alt="companyName || 'Bedrijf'" />
                        <Link :href="'/companies'" @click="$emit('navigate')"
                            :class="[rowStateClass(isCompanyCurrent), bottomLinkClass]">
                            <BuildingOffice2Icon class="size-5 shrink-0" />
                            <span>Bedrijf</span>
                        </Link>
                        <Link :href="'/roles'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/roles')), bottomLinkClass]">
                            <UsersIcon class="size-5 shrink-0" />
                            <span>Rollen</span>
                        </Link>
                        <Link :href="'/admin/calendar-grants'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/admin/calendar-grants')), bottomLinkClass]">
                            <CalendarIcon class="size-5 shrink-0" />
                            <span>Agenda-toegang</span>
                        </Link>
                        <Link :href="'/admin/settings'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/admin/settings')), bottomLinkClass]">
                            <Cog6ToothIcon class="size-5 shrink-0" />
                            <span>Instellingen</span>
                        </Link>
                    </div>
                    <div class="space-y-0.5" v-if="canManageStandardEmails || canManageStandardAttachments">
                        <Link v-if="canManageStandardEmails" :href="'/standard-emails'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/standard-emails')), bottomLinkClass]">
                            <EnvelopeIcon class="size-5 shrink-0" />
                            <span>Standaard e-mails</span>
                        </Link>
                        <Link v-if="canManageStandardAttachments" :href="'/standard-attachments'"
                            @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/standard-attachments')), bottomLinkClass]">
                            <PaperClipIcon class="size-5 shrink-0" />
                            <span>Standaard bijlagen</span>
                        </Link>
                    </div>
                    <div class="space-y-0.5" v-if="canSeeUsers">
                        <Link :href="'/users'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/users')), bottomLinkClass]">
                            <UsersIcon class="size-5 shrink-0" />
                            <span>Gebruikers</span>
                        </Link>
                        <Link v-if="canSeeUserRoles" :href="'/userroles'" @click="$emit('navigate')"
                            :class="[currentPath.startsWith('/userroles') ? 'text-sidebar-text' : 'text-sidebar-muted hover:text-sidebar-text', 'group flex items-center gap-x-2.5 rounded-md py-1.5 pl-11 pr-2 text-[13px] font-medium transition-colors duration-150']">
                            <TagIcon class="size-4 shrink-0" />
                            <span>Gebruikersrollen</span>
                        </Link>
                    </div>
                    <div class="space-y-0.5" v-if="isTechnischBeheer">
                        <Link :href="'/technical-management'" @click="$emit('navigate')"
                            :class="[rowStateClass(currentPath.startsWith('/technical-management')), bottomLinkClass]">
                            <WrenchScrewdriverIcon class="size-5 shrink-0" />
                            <span>Technisch beheer</span>
                        </Link>
                    </div>

                    <Link :href="'/me/edit'" @click="$emit('navigate')"
                        class="group flex items-center gap-x-3 rounded-lg px-3 py-2 transition-colors duration-150 hover:bg-sidebar-hover">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-sidebar-card ring-1 ring-sidebar-border">
                            <img v-if="authUser?.avatar" :src="authUser.avatar" class="h-full w-full object-cover" />
                            <span v-else class="text-xs font-semibold text-sidebar-text">{{ initials }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-sidebar-text">{{ authUser?.name || 'Gebruiker'
                            }}</p>
                            <p v-if="userRoles" class="truncate text-[12px] text-sidebar-muted">{{ userRoles }}</p>
                        </div>
                        <ChevronUpDownIcon
                            class="size-4 shrink-0 text-sidebar-muted transition-colors group-hover:text-sidebar-text" />
                    </Link>

                    <div class="rounded-xl bg-sidebar-card p-4 ring-1 ring-sidebar-border">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex size-6 items-center justify-center rounded-full bg-amber-400/15 text-amber-400">
                                <QuestionMarkCircleIcon class="size-4" />
                            </span>
                            <p class="text-sm font-semibold text-sidebar-text">Hulp nodig?</p>
                        </div>
                        <p class="mt-1.5 text-[12px] leading-snug text-sidebar-muted">Onze support staat voor je klaar.
                        </p>
                        <a :href="supportMailto"
                            class="group mt-3 flex items-center justify-between rounded-lg bg-sidebar-bg px-3 py-2 text-[13px] font-medium text-sidebar-text ring-1 ring-sidebar-border transition-colors duration-150 hover:bg-sidebar-active hover:text-white hover:ring-sidebar-active">
                            <span>Naar support</span>
                            <ArrowRightIcon class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" />
                        </a>
                    </div>

                    <div class="border-t border-sidebar-border pt-3">
                        <button @click="$emit('logout')"
                            class="group flex w-full items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-muted transition-colors duration-150 hover:bg-sidebar-hover hover:text-sidebar-text">
                            <ArrowRightOnRectangleIcon class="size-5 shrink-0" />
                            <span>Uitloggen</span>
                        </button>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import {
    BuildingOffice2Icon,
    UsersIcon,
    CalendarIcon,
    Cog6ToothIcon,
    EnvelopeIcon,
    PaperClipIcon,
    TagIcon,
    WrenchScrewdriverIcon,
    ChevronDownIcon,
    ChevronUpDownIcon,
    ArrowRightIcon,
    ArrowRightOnRectangleIcon,
    QuestionMarkCircleIcon,
} from '@heroicons/vue/24/outline'
import { useSidebarNav } from '@/Composables/useSidebarNav.js'

const emit = defineEmits(['navigate', 'logout'])

const {
    authUser,
    isAdmin,
    initials,
    userRoles,
    companyLogo,
    companyName,
    canSeeUsers,
    canSeeUserRoles,
    canManageStandardEmails,
    canManageStandardAttachments,
    isTechnischBeheer,
    currentPath,
    isCompanyCurrent,
    filteredNavigation,
    filteredLists,
    visibleChildren,
    toggleSection,
    updateCurrent,
    rowStateClass,
    bottomLinkClass,
    supportMailto,
} = useSidebarNav()

const onTopNav = (item) => {
    updateCurrent(item)
    emit('navigate')
}
</script>
