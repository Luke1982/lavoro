<template>
    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Gebruikers</h1>
            <Link href="/users/create"
                class="inline-flex items-center gap-1 rounded-md bg-lavoro-blue px-3 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2">
            Nieuwe gebruiker</Link>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div v-for="u in users" :key="u.id"
                class="flex items-center gap-4 rounded-lg border border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4 shadow-sm hover:shadow transition group">
                <Link :href="`/users/${u.id}/edit`" class="flex items-center gap-4 flex-1 min-w-0">
                <div
                    class="h-12 w-12 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center overflow-hidden text-sm font-semibold text-gray-600 dark:text-slate-300 ring-1 ring-gray-300 dark:ring-slate-700 group-hover:ring-indigo-400">
                    <img v-if="u.avatar" :src="u.avatar" class="object-cover w-full h-full" />
                    <span v-else>{{ initials(u.name) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ u.name }}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400 truncate">{{ u.email }}</p>
                </div>
                </Link>
                <button v-if="hasPermission('user.delete') && u.id !== currentUserId" @click="onDelete(u)"
                    title="Verwijderen"
                    class="shrink-0 rounded-md p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 dark:text-slate-500 dark:hover:text-red-400 dark:hover:bg-red-950/40">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482 41.03 41.03 0 0 0-2.365-.298V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4Z"
                            clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex items-center gap-3" v-if="users.meta?.links">
            <template v-for="(link, i) in users.meta.links" :key="i">
                <button v-if="link.url" @click="go(link.url)"
                    :class="['px-3 py-1 rounded text-xs font-medium', link.active ? 'bg-lavoro-blue text-white' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-800 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800']"
                    v-html="link.label" />
                <span v-else class="px-3 py-1 rounded text-xs text-gray-400 dark:text-slate-500" v-html="link.label" />
            </template>
        </div>

        <div v-if="deletedUsers?.length" class="space-y-3">
            <button type="button" @click="showDeleted = !showDeleted"
                class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200">
                <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-90': showDeleted }" viewBox="0 0 20 20"
                    fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd"
                        d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.29A.75.75 0 0 1 8.25 5.2l4.25 4.25a.75.75 0 0 1 0 1.06L8.25 14.77a.75.75 0 0 1-1.06 0Z"
                        clip-rule="evenodd" />
                </svg>
                Verwijderde gebruikers ({{ deletedUsers.length }})
            </button>

            <div v-if="showDeleted" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div v-for="u in deletedUsers" :key="u.id"
                    class="flex items-center gap-4 rounded-lg border border-gray-200 dark:border-slate-800 bg-gray-50 dark:bg-slate-900/60 px-6 py-4 opacity-70">
                    <div
                        class="h-12 w-12 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center overflow-hidden text-sm font-semibold text-gray-600 dark:text-slate-300">
                        <img v-if="u.avatar" :src="u.avatar" class="object-cover w-full h-full" />
                        <span v-else>{{ initials(u.name) }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ u.name }}
                            <span
                                class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300">Gedeactiveerd</span>
                        </p>
                        <p class="text-sm text-gray-500 dark:text-slate-400 truncate">{{ u.email }}</p>
                    </div>
                    <button v-if="hasPermission('user.restore')" @click="onRestore(u)"
                        class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium text-lavoro-blue hover:bg-blue-50 dark:hover:bg-slate-800">
                        Herstellen
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
import { ref } from 'vue'
import { router, Link, usePage } from '@inertiajs/vue3'
import { initials, hasPermission } from '@/Utilities/Utilities'

const props = defineProps({ users: Object, deletedUsers: Array })

const showDeleted = ref(false)
const currentUserId = usePage().props.auth?.user?.id

const go = (url) => router.visit(url)

function onDelete(user) {
    if (!confirm(`Weet je zeker dat je ${user.name} wilt verwijderen?`)) return
    router.delete(`/users/${user.id}`, { preserveScroll: true })
}

function onRestore(user) {
    router.post(`/users/${user.id}/restore`, {}, { preserveScroll: true })
}
</script>
