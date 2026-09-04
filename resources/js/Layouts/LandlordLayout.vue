<template>
    <div class="min-h-screen">
        <header class="bg-slate-900 text-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
                <strong>Lavoro Beheer</strong>

                <nav class="flex items-center gap-5 text-sm text-slate-300">
                    <Link v-for="item in menu" :key="item.href" :href="item.href"
                        class="hover:text-white"
                        :class="{ 'text-white font-medium': isCurrent(item.href) }">{{ item.label }}</Link>

                    <button type="button" class="hover:text-white" @click="logout">Uitloggen</button>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-6">
            <FlashMessage />
            <slot />
        </main>
    </div>
</template>

<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import FlashMessage from '@/Components/Landlord/FlashMessage.vue'

const menu = [
    { label: 'Tenants', href: '/beheer' },
    { label: 'Catalogus', href: '/beheer/catalogus' },
    { label: 'Resellers', href: '/beheer/resellers' },
    { label: 'Incasso', href: '/beheer/incasso' },
]

const url = computed(() => usePage().url)

/** '/beheer' is alleen het overzicht; de rest matcht op het begin van het pad. */
const isCurrent = (href) => (href === '/beheer' ? url.value === href : url.value.startsWith(href))

const logout = () => router.post('/beheer/logout')
</script>
