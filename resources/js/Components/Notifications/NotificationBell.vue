<template>
    <VDropdown :placement="placement" :distance="10" popper-class="nav-popper" @show="open">
        <button type="button" class="relative flex items-center justify-center rounded-lg transition-colors"
            :class="buttonClass" :aria-label="'Meldingen' + (unreadCount ? ', ' + unreadCount + ' ongelezen' : '')">
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
            <div class="flex max-h-[75vh] w-[calc(100vw-1.5rem)] flex-col rounded-2xl bg-sidebar-bg ring-1 ring-sidebar-border sm:max-h-[32rem] sm:w-[22rem]">
                <div class="flex items-center justify-between px-4 pt-4">
                    <p class="text-base font-semibold text-sidebar-text">Meldingen</p>
                    <button type="button" class="text-[13px] font-medium text-lavoro-blue hover:underline disabled:opacity-40"
                        :disabled="!unreadCount" @click="acknowledgeAll">
                        Alles markeren
                    </button>
                </div>

                <div class="mt-3 flex gap-x-1 border-b border-sidebar-border px-3">
                    <button v-for="tab in tabs" :key="tab.id" type="button" @click="load(tab.id)" :class="[
                        'relative px-2 pb-2 text-[13px] font-medium transition-colors',
                        filter === tab.id ? 'text-sidebar-text' : 'text-sidebar-muted hover:text-sidebar-text',
                    ]">
                        {{ tab.label }}
                        <span v-if="tab.count"
                            class="ml-1.5 rounded-full bg-lavoro-blue px-1.5 py-0.5 text-[10px] font-semibold text-white">{{
                                tab.count }}</span>
                        <span v-if="filter === tab.id"
                            class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-lavoro-blue"></span>
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-1 pb-2">
                    <NotificationList :items="items" :loading="loading" :empty-label="emptyLabel"
                        @follow="onFollow" @acknowledge="acknowledge" />
                </div>

                <Link href="/usernotifications" v-close-popper
                    class="flex items-center justify-between rounded-b-2xl border-t border-sidebar-border px-4 py-3 text-[13px] font-medium text-sidebar-text hover:bg-sidebar-hover">
                    <span>Bekijk alle meldingen</span>
                    <ChevronRight class="size-4" />
                </Link>
            </div>
        </template>
    </VDropdown>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Bell, ChevronRight } from '@lucide/vue'
import NotificationList from '@/Components/Notifications/NotificationList.vue'
import { useNotifications } from '@/Composables/useNotifications.js'

/**
 * Het belletje met wat eronder hangt. Haalt pas iets op als het opengaat: op elke
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
})

const { items, loading, filter, unreadCount, load, open, acknowledge, acknowledgeAll, follow } = useNotifications()

const tabs = computed(() => [
    { id: 'alles', label: 'Alles', count: items.value.length || null },
    { id: 'ongelezen', label: 'Ongelezen', count: unreadCount.value || null },
    { id: 'belangrijk', label: 'Belangrijk', count: null },
])

const emptyLabel = computed(() => ({
    alles: 'Nog geen meldingen.',
    ongelezen: 'Alles gelezen.',
    belangrijk: 'Niets dringends.',
}[filter.value]))

const onFollow = (notification) => follow(notification)
</script>
