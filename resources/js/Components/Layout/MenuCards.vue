<template>
    <template v-for="card in cards" :key="card.id">
        <div v-if="card.type === 'license' && companyName"
            class="flex items-center gap-x-3 rounded-xl bg-sidebar-card p-3 ring-1 ring-sidebar-border">
            <img v-if="companyLogo" :src="companyLogo" :alt="companyName"
                class="size-10 shrink-0 rounded-lg bg-white/5 object-contain p-1">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-sidebar-text">{{ companyName }}</p>
            </div>
            <!-- Het pakket komt van de tenant; card.license is alleen nog de terugval. -->
            <span v-if="licenseLabel"
                class="shrink-0 rounded-md bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-400">
                {{ licenseLabel }}
            </span>
        </div>

        <a v-else-if="card.type === 'support'" :href="card.href" :target="card.external ? '_blank' : undefined"
            :rel="card.external ? 'noopener' : undefined"
            class="group flex items-center gap-x-3 rounded-xl bg-sidebar-card p-3 ring-1 ring-sidebar-border transition-colors hover:ring-lavoro-green/50">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-amber-400">
                <component :is="navIcon(card.icon)" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-sidebar-text">{{ card.label }}</p>
                <p class="truncate text-[12px] text-sidebar-muted">{{ card.description }}</p>
            </div>
            <!--
                Het pictogram rechts zegt wat er gebeurt als je erop tikt, en dat
                leest de kaart af aan zijn eigen adres: een telefoonnummer belt,
                de rest opent ergens anders.
            -->
            <component :is="card.href?.startsWith('tel:') ? Phone : ExternalLink"
                class="size-4 shrink-0 text-sidebar-muted transition-colors group-hover:text-sidebar-text" />
        </a>

        <Link v-else-if="card.type === 'user'" :href="card.href" @click="$emit('navigate')"
            class="group flex items-center gap-x-3 rounded-xl bg-sidebar-card p-3 ring-1 ring-sidebar-border transition-colors hover:ring-sidebar-active/50">
            <span
                class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-sidebar-bg ring-1 ring-sidebar-border">
                <img v-if="authUser?.avatar" :src="authUser.avatar" class="h-full w-full object-cover" alt="">
                <span v-else class="text-xs font-semibold text-sidebar-text">{{ initials }}</span>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-sidebar-text">{{ authUser?.name || 'Gebruiker' }}</p>
                <p class="truncate text-[12px] text-sidebar-muted">{{ authUser?.email || userRoles }}</p>
            </div>
            <ChevronRight class="size-4 shrink-0 text-sidebar-muted transition-colors group-hover:text-sidebar-text" />
        </Link>

        <button v-else-if="card.type === 'logout'" type="button" :disabled="loggingOut" @click="$emit('logout')"
            class="group flex w-full items-center gap-x-3 rounded-xl px-3 py-2 text-sm font-medium text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-text disabled:cursor-wait disabled:hover:bg-transparent">
            <LoaderCircle v-if="loggingOut" class="size-5 shrink-0 animate-spin" />
            <component v-else :is="navIcon(card.icon)" class="size-5 shrink-0" />
            <span class="flex-1 text-left">{{ loggingOut ? 'Bezig met afmelden…' : card.label }}</span>
            <ChevronRight v-if="!loggingOut" class="size-4 shrink-0 opacity-40" />
        </button>
    </template>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Link } from '@inertiajs/vue3'
import { ChevronRight, ExternalLink, LoaderCircle, Phone } from '@lucide/vue'
import { navIcon } from '@/Navigation/icons.js'
import { useMenu } from '@/Composables/useMenu.js'

/**
 * De kaarten onderaan het menu: welk bedrijf dit is, waar hulp vandaan komt, wie
 * er is ingelogd en de weg naar buiten. Welke er staan en in welke volgorde komt
 * uit menu.json, net als de rest van het menu.
 */
defineProps({
    cards: { type: Array, required: true },
})

defineEmits(['navigate', 'logout'])

const { authUser, initials, userRoles, companyName, companyLogo, loggingOut } = useMenu()

/**
 * Het pakket van deze klant, gedeeld door HandleInertiaRequests. Valt terug op
 * wat in menu.json staat, zodat een installatie zonder pakket geen leeg vakje
 * laat zien maar gewoon niets.
 */
const licenseLabel = computed(() => usePage().props.tenant?.package ?? null)
</script>
