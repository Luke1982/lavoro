<template>
    <IndexHeaderComponent title="Aankondigingen"
        subtitle="Berichten die iedereen die ze krijgt eerst moet bevestigen" :show-search="false"
        add-label="Nieuwe aankondiging" @add="showCreateDrawer = true"
        :can-add="hasPermission('internalannouncement.create')" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0">
        <div v-if="announcements.length">
            <div
                class="hidden md:grid md:grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-5">Aankondiging</div>
                <div class="col-span-2">Doelgroep</div>
                <div class="col-span-2">Bevestigd</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="announcement in announcements" :key="announcement.id"
                class="relative md:grid md:grid-cols-12 p-4 pr-14 md:pr-4 text-sm border-b-lavoro-gray-150 border-b-2 md:items-center">
                <div class="flex flex-col py-1 md:py-0 md:col-span-5">
                    <Link :href="`/internalannouncements/${announcement.id}`"
                        class="font-medium text-gray-900 dark:text-slate-100 hover:underline">
                        {{ announcement.title }}
                    </Link>
                    <span class="text-gray-500 dark:text-slate-400 line-clamp-1">{{ announcement.body }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span
                        class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Doelgroep</span>
                    <span class="text-gray-500 dark:text-slate-400">{{ audienceLabel(announcement) }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span
                        class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Bevestigd</span>
                    <span class="text-gray-500 dark:text-slate-400">{{ progressLabel(announcement) }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Status</span>
                    <BadgeComponent v-bind="announcementStatus(announcement)" />
                </div>
                <div class="absolute right-4 top-4 md:static md:col-span-1 md:flex md:justify-end">
                    <div v-if="hasPermission('internalannouncement.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="destroy(announcement)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <Megaphone class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Nog geen aankondigingen</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="showCreateDrawer" title="Nieuwe aankondiging"
        subtitle="Iedereen die hem krijgt moet hem bevestigen voordat hij weggaat.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.title" type="text" placeholder="Bijv. Magazijn vrijdag gesloten"
                        :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Bericht</label>
                <div class="sm:col-span-2">
                    <textarea v-model="form.body" rows="5"
                        class="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 bg-white dark:bg-slate-800 text-gray-800 dark:text-slate-200 placeholder-gray-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Wat moet iedereen weten?"></textarea>
                    <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Aan iedereen</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="form.is_for_everyone" />
                </div>
            </div>
            <div v-if="!form.is_for_everyone" class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Gebruikers</label>
                <div class="sm:col-span-2">
                    <ComboBox v-model="form.user_ids" :options="users" multiple placeholder="Kies gebruikers"
                        :hasError="Boolean(form.errors.user_ids)" :errorMessage="form.errors.user_ids" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Zichtbaar tot en met</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.expires_on" type="date" placeholder="Leeg = tot iedereen bevestigd heeft"
                        :hasError="Boolean(form.errors.expires_on)" :errorMessage="form.errors.expires_on" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                        Laat leeg als de aankondiging moet blijven staan tot iedereen hem bevestigd heeft.
                    </p>
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="showCreateDrawer = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitCreate" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Versturen
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { TrashIcon } from '@heroicons/vue/24/outline'
import { Megaphone } from '@lucide/vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import {
    announcementAudienceLabel as audienceLabel, announcementProgressLabel as progressLabel,
    announcementStatus, hasPermission,
} from '@/Utilities/Utilities'

defineProps({
    announcements: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
})

const showCreateDrawer = ref(false)

const form = useForm({
    title: '',
    body: '',
    is_for_everyone: true,
    user_ids: [],
    expires_on: '',
})

function submitCreate() {
    form.post('/internalannouncements', {
        preserveScroll: true,
        onSuccess: () => { showCreateDrawer.value = false },
    })
}

function destroy(announcement) {
    if (!window.confirm(`Aankondiging "${announcement.title}" verwijderen? De bevestigingen gaan mee.`)) return

    router.delete(`/internalannouncements/${announcement.id}`, { preserveScroll: true })
}

watch(showCreateDrawer, isOpen => {
    if (isOpen) return

    form.reset()
    form.clearErrors()
})
</script>
