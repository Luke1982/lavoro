<template>
    <div class="min-h-screen flex relative">
        <img src="/img/bg.png" alt="" class="hidden lg:block absolute inset-0 w-full h-full object-cover" />

        <div class="hidden lg:flex lg:w-[55%] relative flex-col">
            <div class="relative z-10 flex flex-col justify-between h-full p-12">
                <img src="/img/logo-neg.svg" alt="Lavoro" class="h-9 w-auto self-start" />
            </div>
        </div>

        <div class="flex-1 flex flex-col items-end justify-center p-6 lg:pr-30 relative">
            <img src="/img/bg-mobile.png" alt="" class="lg:hidden absolute inset-0 w-full h-full object-cover" />

            <div
                class="w-full max-w-xl relative z-10 lg:bg-lavoro-dark lg:border lg:border-white/10 lg:rounded-2xl lg:p-16 lg:min-h-[calc(100vh-12rem)] lg:flex lg:flex-col lg:justify-center lg:min-w-130">
                <div class="lg:hidden mb-10">
                    <img src="/img/logo-neg.svg" alt="Lavoro" class="h-10 mx-auto" />
                </div>

                <p class="text-lavoro-green font-medium text-sm mb-1">Wachtwoord vergeten?</p>
                <h2 class="text-3xl font-bold text-white leading-snug mb-4">
                    Reset je<br>wachtwoord
                </h2>
                <p class="text-gray-400 text-sm mb-8">
                    Vul je e-mailadres in en we sturen je een link om je wachtwoord opnieuw in te stellen.
                </p>

                <div v-if="status"
                    class="mb-6 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                    {{ statusMessage }}
                </div>

                <form v-if="!status" class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm text-gray-400 mb-2">E-mailadres</label>
                        <div
                            class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-4 py-3 focus-within:border-lavoro-blue transition-colors"
                            :class="{ 'border-red-500/50': form.errors.email }">
                            <EnvelopeIcon class="h-5 w-5 text-gray-500 shrink-0" />
                            <input v-model="form.email" type="email" autocomplete="email" required
                                placeholder="jouw@email.nl"
                                class="flex-1 bg-transparent text-white placeholder-gray-600 text-sm outline-none" />
                        </div>
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-400">{{ form.errors.email }}</p>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="w-full flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-lavoro-blue to-lavoro-green py-3 text-sm font-semibold text-white shadow-lg disabled:opacity-50 transition-opacity">
                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3">
                            <circle cx="12" cy="12" r="10" class="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" class="opacity-75" />
                        </svg>
                        <span>Verstuur resetlink</span>
                        <ArrowRightIcon v-if="!form.processing" class="h-4 w-4" />
                    </button>
                </form>

                <p class="mt-8 text-center text-sm text-gray-600">
                    <a href="/login" class="text-gray-500 hover:text-gray-400 transition-colors">
                        Terug naar inloggen
                    </a>
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { EnvelopeIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const page = usePage();
const status = computed(() => page.props.flash?.status);
const statusMessage = computed(() => 'Als dit e-mailadres bij ons bekend is, ontvang je zo een e-mail met een resetlink.');

const form = useForm({ email: '' });

const submit = () => form.post('/password/forgot');
</script>

<script>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
export default {
    layout: EmptyLayout,
};
</script>
