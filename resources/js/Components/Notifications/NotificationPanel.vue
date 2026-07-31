<template>
    <div class="flex flex-col overflow-hidden rounded-2xl bg-sidebar-bg ring-1 ring-sidebar-border">
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
                <span v-if="filter === tab.id" class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-lavoro-blue"></span>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-1 pb-2">
            <NotificationList :items="items" :loading="loading" :empty-label="emptyLabel" @follow="onFollow"
                @acknowledge="acknowledge" />
        </div>

        <Link href="/usernotifications" @click="$emit('close')"
            class="flex items-center justify-between rounded-b-2xl border-t border-sidebar-border px-4 py-3 text-[13px] font-medium text-sidebar-text hover:bg-sidebar-hover">
            <span>Bekijk alle meldingen</span>
            <ChevronRight class="size-4" />
        </Link>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ChevronRight } from '@lucide/vue'
import NotificationList from '@/Components/Notifications/NotificationList.vue'
import { useNotifications } from '@/Composables/useNotifications.js'

/**
 * De inhoud van het belletje, zonder te weten waar hij hangt. Op een breed scherm
 * zit dit in een uitklapper naast de knop, op een telefoon in een blad onder de
 * kop — en die twee hoeven verder niets van elkaar te weten.
 */
const emit = defineEmits(['close'])

const { items, total, loading, filter, unreadCount, load, acknowledge, acknowledgeAll, follow } = useNotifications()

const tabs = computed(() => [
    { id: 'alles', label: 'Alles', count: total.value || null },
    { id: 'ongelezen', label: 'Ongelezen', count: unreadCount.value || null },
    { id: 'belangrijk', label: 'Belangrijk', count: null },
])

const emptyLabel = computed(() => ({
    alles: 'Nog geen meldingen.',
    ongelezen: 'Alles gelezen.',
    belangrijk: 'Niets dringends.',
}[filter.value]))

const onFollow = (notification) => {
    emit('close')
    follow(notification)
}
</script>
