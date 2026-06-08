<template>
    <div class="min-h-screen flex relative">
        <!-- Full-screen background (desktop) -->
        <img src="/img/bg.png" alt="" class="hidden lg:block absolute inset-0 w-full h-full object-cover" />

        <!-- Left panel: marketing copy (desktop only) -->
        <div class="hidden lg:flex lg:w-[55%] relative flex-col">
            <div class="relative z-10 flex flex-col justify-between h-full p-12">
                <img src="/img/logo-neg.svg" alt="Lavoro" class="h-9 w-auto self-start" />

                <div>
                    <h1 class="text-5xl font-bold text-white leading-tight">
                        Werk slimmer.<br>
                        Service <span
                            class="bg-gradient-to-r from-lavoro-blue to-lavoro-green bg-clip-text text-transparent">beter.</span>
                    </h1>
                    <p class="mt-4 text-white/55 text-sm max-w-sm leading-relaxed">
                        Lavoro helpt serviceteams om werkbonnen, planning en klantinformatie naadloos te beheren.
                    </p>

                    <div class="mt-10 grid grid-cols-3 gap-6">
                        <div v-for="feature in features" :key="feature.title">
                            <component :is="feature.icon" class="h-10 w-10 mb-4" :class="feature.color" />
                            <p class="text-white text-sm font-semibold">{{ feature.title }}</p>
                            <p class="text-white/45 text-xs mt-1 leading-relaxed">{{ feature.body }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right panel: login form -->
        <div class="flex-1 flex flex-col items-end justify-center p-6 lg:pr-30 relative">
            <img src="/img/bg-mobile.png" alt="" class="lg:hidden absolute inset-0 w-full h-full object-cover" />

            <div
                class="w-full max-w-xl relative z-10 lg:bg-lavoro-dark lg:border lg:border-white/10 lg:rounded-2xl lg:p-16 lg:min-h-[calc(100vh-12rem)] lg:flex lg:flex-col lg:justify-center lg:min-w-130">
                <!-- Mobile logo -->
                <div class="lg:hidden mb-10">
                    <img src="/img/logo-neg.svg" alt="Lavoro" class="h-10 mx-auto" />
                </div>
                <p class="text-lavoro-green font-medium text-sm mb-1">Welkom terug! 👋</p>
                <h2 class="text-3xl font-bold text-white leading-snug mb-8">
                    Log in om verder<br>te gaan in Lavoro
                </h2>

                <form class="space-y-5" @submit.prevent="login">
                    <!-- Email -->
                    <div>
                        <label class="block text-sm text-gray-400 mb-2">E-mailadres</label>
                        <div
                            class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-4 py-3 focus-within:border-lavoro-blue transition-colors">
                            <EnvelopeIcon class="h-5 w-5 text-gray-500 shrink-0" />
                            <input v-model="loginForm.email" id="email" name="email" type="email" autocomplete="email"
                                required placeholder="jouw@email.nl"
                                class="flex-1 bg-transparent text-white placeholder-gray-600 text-sm outline-none" />
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-sm text-gray-400 mb-2">Wachtwoord</label>
                        <div
                            class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-4 py-3 focus-within:border-lavoro-blue transition-colors">
                            <LockClosedIcon class="h-5 w-5 text-gray-500 shrink-0" />
                            <input :type="showPassword ? 'text' : 'password'" v-model="loginForm.password" id="password"
                                name="password" autocomplete="current-password" required placeholder="••••••••"
                                class="flex-1 bg-transparent text-white placeholder-gray-600 text-sm outline-none" />
                            <button type="button" @click="showPassword = !showPassword"
                                class="text-gray-500 hover:text-gray-300 transition-colors">
                                <EyeSlashIcon v-if="showPassword" class="h-5 w-5" />
                                <EyeIcon v-else class="h-5 w-5" />
                            </button>
                        </div>
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer select-none">
                            <input type="checkbox" v-model="rememberMe" class="rounded accent-lavoro-blue h-4 w-4" />
                            Onthoud mij
                        </label>
                        <a href="/password/forgot" class="text-sm text-lavoro-green hover:text-lavoro-green/80 transition-colors">
                            Wachtwoord vergeten?
                        </a>
                    </div>

                    <!-- Errors -->
                    <div v-if="flatErrors.length"
                        class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                        <ul class="list-disc list-inside space-y-0.5">
                            <li v-for="(msg, i) in flatErrors" :key="i">{{ msg }}</li>
                        </ul>
                    </div>

                    <!-- Submit -->
                    <button type="submit" :disabled="loginForm.processing"
                        class="w-full flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-lavoro-blue to-lavoro-green py-3 text-sm font-semibold text-white shadow-lg disabled:opacity-50 transition-opacity">
                        <svg v-if="loginForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="3">
                            <circle cx="12" cy="12" r="10" class="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" class="opacity-75" />
                        </svg>
                        <span>Inloggen</span>
                        <ArrowRightIcon v-if="!loginForm.processing" class="h-4 w-4" />
                    </button>
                </form>

                <p class="mt-8 text-center text-sm text-gray-600">
                    Nog geen account?
                    <a href="mailto:" class="text-gray-500 hover:text-gray-400 transition-colors">Neem contact op</a>
                    met je beheerder.
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    EnvelopeIcon,
    LockClosedIcon,
    EyeIcon,
    EyeSlashIcon,
    ArrowRightIcon,
    BoltIcon,
    ShieldCheckIcon,
    PresentationChartLineIcon,
} from '@heroicons/vue/24/outline';

const loginForm = useForm({
    email: '',
    password: '',
});

const showPassword = ref(false);
const rememberMe = ref(true);

const flatErrors = computed(() => Object.values(loginForm.errors || {}).flat());

const features = [
    {
        icon: BoltIcon,
        color: 'text-lavoro-green',
        title: 'Snel en efficiënt',
        body: 'Minder administratie, meer resultaat.',
    },
    {
        icon: ShieldCheckIcon,
        color: 'text-lavoro-blue',
        title: 'Betrouwbaar en veilig',
        body: 'Jouw data is bij ons in veilige handen.',
    },
    {
        icon: PresentationChartLineIcon,
        color: 'text-violet-500',
        title: 'Altijd inzicht',
        body: 'Real-time overzicht van al je processen.',
    },
];

const login = () => loginForm.post('/login');
</script>

<script>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
export default {
    layout: EmptyLayout,
};
</script>
