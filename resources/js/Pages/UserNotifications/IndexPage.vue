<template>
    <IndexHeaderComponent title="Meldingen" :subtitle="subtitle" :show-search="false" />

    <BoxComponent class="mt-4">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-slate-700">
            <div class="flex gap-x-1">
                <Link v-for="tab in tabs" :key="tab.id" :href="tab.href" preserve-scroll :class="[
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                    tab.active
                        ? 'bg-lavoro-blue text-white'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800',
                ]">
                    {{ tab.label }}
                </Link>
            </div>

            <button type="button" class="text-sm font-medium text-lavoro-blue hover:underline disabled:opacity-40"
                :disabled="!unread" @click="markAll">
                Alles markeren als gelezen
            </button>
        </div>

        <!--
            Dezelfde lijst als in de uitklapper, op een donkere kaart zodat de
            gekleurde pictogrammen hetzelfde lezen als in het menu.
        -->
        <div class="-mx-4 mt-2 rounded-xl bg-sidebar-bg px-1 py-2 sm:-mx-2">
            <NotificationList :items="rows" @follow="follow" @acknowledge="toggleRead" />
        </div>

        <PaginationComponent v-if="notifications.last_page > 1" :paginator="notifications" class="mt-4" />
    </BoxComponent>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import NotificationList from '@/Components/Notifications/NotificationList.vue'

/**
 * Alles wat er ooit gemeld is, met dezelfde drie tabbladen als het belletje.
 * Hier zijn ze links in plaats van knoppen: een filter dat je kunt delen en waar
 * de terugknop het goede doet.
 */
const props = defineProps({
    notifications: { type: Object, required: true },
    unread_count: { type: Number, default: 0 },
    filter: { type: Object, default: () => ({}) },
})

const rows = ref([...props.notifications.data])
const unread = ref(props.unread_count)

const subtitle = computed(() => (unread.value
    ? unread.value + ' ongelezen'
    : 'Alles gelezen'))

const tabs = computed(() => [
    { id: 'alles', label: 'Alles', href: '/usernotifications', active: !props.filter.unread && !props.filter.important },
    { id: 'ongelezen', label: 'Ongelezen', href: '/usernotifications?unread=1', active: !!props.filter.unread },
    { id: 'belangrijk', label: 'Belangrijk', href: '/usernotifications?important=1', active: !!props.filter.important },
])

const toggleRead = async (notification) => {
    const url = '/usernotifications/' + notification.id + '/read'
    const { data } = notification.read_at ? await axios.delete(url) : await axios.patch(url)
    const index = rows.value.findIndex((row) => row.id === notification.id)
    if (index !== -1) rows.value[index] = data.notification
    unread.value = data.unread_count
}

const markAll = async () => {
    const { data } = await axios.post('/usernotifications/read-all')
    rows.value = rows.value.map((row) => ({ ...row, read_at: row.read_at ?? new Date().toISOString() }))
    unread.value = data.unread_count
}

const follow = async (notification) => {
    if (!notification.read_at) await toggleRead(notification)
    if (notification.url) router.visit(notification.url)
}
</script>
