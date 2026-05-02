<template>
    <div
        class="min-h-screen bg-gradient-to-br from-indigo-600 via-blue-500 to-emerald-500 flex items-center justify-center p-4">
        <div class="w-full max-w-md">
            <div
                class="relative bg-white/90 backdrop-blur-md rounded-2xl shadow-xl ring-1 ring-black/5 overflow-hidden">
                <div class="px-8 pt-8 pb-6 text-center">
                    <div class="mx-auto flex flex-col items-center justify-center gap-3">
                        <div v-if="companyLogo" class="relative">
                            <img :src="companyLogo" :alt="companyName || 'Bedrijf'"
                                class="h-16 w-auto object-contain drop-shadow-sm" />
                        </div>
                        <div v-else class="h-16 flex items-center justify-center">
                            <span class="text-white/80 text-xl font-semibold tracking-wide">Lavoro FSM</span>
                        </div>
                    </div>
                    <h2 class="mt-6 text-2xl font-semibold tracking-tight text-gray-900">Welkom terug</h2>
                    <p class="text-sm text-gray-500 mt-1">Log in om verder te gaan</p>
                </div>

                <form class="px-8 pb-8 space-y-5" @submit.prevent="login">
                    <div>
                        <label for="email" class="block text-xs font-medium text-gray-700 mb-1">E-mailadres</label>
                        <div class="relative">
                            <input v-model="loginForm.email" id="email" name="email" type="email" autocomplete="email"
                                required
                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                                placeholder="you@company.com" />
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-xs font-medium text-gray-700 mb-1">Wachtwoord</label>
                        <div class="relative">
                            <input :type="showPassword ? 'text' : 'password'" v-model="loginForm.password" id="password"
                                name="password" autocomplete="current-password" required
                                class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 pr-10 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                                placeholder="••••••••" />
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                                <component :is="showPassword ? EyeSlashIcon : EyeIcon" class="h-5 w-5" />
                                <span class="sr-only">Toggle password</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="Object.keys(loginForm.errors || {}).length"
                        class="rounded-md bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">
                        <ul class="list-disc list-inside">
                            <li v-for="(msg, i) in flatErrors" :key="i">{{ msg }}</li>
                        </ul>
                    </div>

                    <button type="submit" @click="login" :disabled="loginForm.processing"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-indigo-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:from-indigo-500 hover:to-blue-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-600 disabled:opacity-50">
                        <svg v-if="loginForm.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="4">
                            <circle cx="12" cy="12" r="10" class="opacity-25" />
                            <path d="M4 12a8 8 0 018-8" class="opacity-75" />
                        </svg>
                        <span>Log in</span>
                    </button>
                </form>

                <div class="px-8 pb-6 text-center text-xs text-gray-500">
                    <span>© {{ new Date().getFullYear() }} — Lavoro FSM</span>
                </div>

                <div
                    class="absolute -inset-x-16 -bottom-16 h-32 bg-gradient-to-r from-indigo-400/20 via-blue-400/20 to-emerald-400/20 blur-2xl pointer-events-none">
                </div>
            </div>
        </div>
    </div>

</template>

<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline';

const loginForm = useForm({
    email: '',
    password: '',
});
const showPassword = ref(false);

const page = usePage();
const companyLogo = computed(() => page.props.company?.logo_url || null);
const companyName = computed(() => page.props.company?.name || null);

const flatErrors = computed(() => {
    const e = loginForm.errors || {}
    return Object.values(e).flat()
})

const login = () => loginForm.post('/login');
</script>

<script>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
export default {
    layout: EmptyLayout,
};
</script>
