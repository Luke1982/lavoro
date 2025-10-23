<template>
    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Gebruikers</h1>
            <Link href="/users/create"
                class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-600">
            Nieuwe gebruiker</Link>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Link v-for="u in users" :key="u.id" :href="`/users/${u.id}/edit`"
                class="flex items-center gap-4 rounded-lg border border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-6 py-4 shadow-sm hover:shadow transition group">
            <div
                class="h-12 w-12 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center overflow-hidden text-sm font-semibold text-gray-600 dark:text-slate-300 ring-1 ring-gray-300 dark:ring-slate-700 group-hover:ring-indigo-400">
                <img v-if="u.avatar" :src="u.avatar" class="object-cover w-full h-full" />
                <span v-else>{{ initials(u.name) }}</span>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ u.name }}</p>
                <p class="text-sm text-gray-500 dark:text-slate-400 truncate">{{ u.email }}</p>
            </div>
            <svg class="h-5 w-5 text-gray-400 dark:text-slate-500 group-hover:text-gray-500 dark:group-hover:text-slate-400"
                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.2 6.29A.75.75 0 0 1 8.25 5.2l4.25 4.25a.75.75 0 0 1 0 1.06L8.25 14.77a.75.75 0 0 1-1.06 0Z"
                    clip-rule="evenodd" />
            </svg>
            </Link>
        </div>

        <div class="flex items-center gap-3" v-if="users.meta?.links">
            <template v-for="(link, i) in users.meta.links" :key="i">
                <button v-if="link.url" @click="go(link.url)"
                    :class="['px-3 py-1 rounded text-xs font-medium', link.active ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-800 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800']"
                    v-html="link.label" />
                <span v-else class="px-3 py-1 rounded text-xs text-gray-400 dark:text-slate-500" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
<script setup>
import { router, Link } from '@inertiajs/vue3'
import { initials } from '@/Utilities/Utilities'

defineProps({ users: Object })

const go = (url) => router.visit(url)
</script>
