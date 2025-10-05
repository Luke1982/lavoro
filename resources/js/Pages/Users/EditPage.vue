<template>
    <div class="max-w-5xl mx-auto py-6">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{ isEdit ? 'Gebruiker' : 'Nieuwe gebruiker' }}</h1>
            <p v-if="!isEdit" class="mt-1 text-sm text-gray-500 dark:text-slate-400">Voer de gegevens in om een
                gebruiker toe te voegen.</p>
        </div>

        <form class="space-y-10">
            <!-- Profile Section -->
            <section
                class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Profiel</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Deze informatie kan door anderen worden
                        gezien.</p>
                </div>
                <div class="p-6 space-y-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="h-14 w-14 rounded-full bg-gray-200 dark:bg-slate-800 flex items-center justify-center overflow-hidden text-base font-semibold text-gray-600 dark:text-slate-300 ring-1 ring-gray-300 dark:ring-slate-700">
                            <img v-if="avatarPreview" :src="avatarPreview" class="object-cover w-full h-full" />
                            <img v-else-if="isEdit && user?.avatar" :src="user.avatar"
                                class="object-cover w-full h-full" />
                            <span v-else>{{ initials(form.name) }}</span>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <label
                                    class="cursor-pointer inline-flex items-center rounded-md border border-gray-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-slate-200 shadow-sm hover:bg-gray-50 dark:hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-600">Wijzig
                                    <input @change="onAvatarChange" ref="avatarInput" type="file" accept="image/*"
                                        class="hidden" />
                                </label>
                                <button v-if="avatarFile" type="button" @click="clearAvatar"
                                    class="text-xs text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300">Reset</button>
                            </div>
                            <p class="text-[11px] text-gray-400 dark:text-slate-500">PNG, JPG tot 3MB.</p>
                        </div>
                    </div>
                    <div>
                        <TextInput v-model="form.name" type="text" label="Naam" required :has-error="form.errors.name"
                            :error-message="form.errors.name" />
                    </div>
                    <div>
                        <TextInput v-model="form.email" type="email" label="Email" required
                            :has-error="form.errors.email" :error-message="form.errors.email" />
                    </div>
                    <div v-if="!isEdit">
                        <label
                            class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Wachtwoord</label>
                        <TextInput v-model="form.password" type="password" label="Wachtwoord" required
                            :has-error="form.errors.password" :error-message="form.errors.password" />
                    </div>
                    <div v-else>
                        <TextInput v-model="form.password" type="password" label="Nieuw wachtwoord (optioneel)"
                            :has-error="form.errors.password" :error-message="form.errors.password" />
                    </div>
                    <div v-if="isAdmin">
                        <label class="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Rollen</label>
                        <ComboBox v-model="form.role_ids" :options="allRoles" multiple />
                        <div v-if="form.errors.role_ids" class="text-xs text-red-600 mt-1">{{ form.errors.role_ids }}
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <button @click="submit" :disabled="form.processing || !canSubmit"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="4">
                        <circle cx="12" cy="12" r="10" class="opacity-25" />
                        <path d="M4 12a8 8 0 018-8" class="opacity-75" />
                    </svg>
                    <span>{{ isEdit ? 'Opslaan' : 'Aanmaken' }}</span>
                </button>
                <Link :href="cancelHref"
                    class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300">
                Annuleren</Link>
            </div>
        </form>
    </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import { useForm, Link, usePage } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'

const props = defineProps({ user: Object, allRoles: { type: Array, default: () => [] } })

const page = usePage()
const isAdmin = computed(() => !!page.props.auth?.isAdmin)

const isEdit = computed(() => !!props.user)

const form = useForm({ name: props.user?.name || '', email: props.user?.email || '', password: '', avatar: null, role_ids: (props.user?.roles || []).map(r => r.id) })

const canSubmit = computed(() => form.name && form.email && (!isEdit.value ? form.password.length >= 8 : true))

const initials = (name = '') => {
    const letters = name.trim().split(/\s+/).filter(Boolean).map(p => p[0]).slice(0, 2)
    return letters.length ? letters.join('').toUpperCase() : 'US'
}

const avatarInput = ref(null)
const avatarFile = ref(null)
const avatarPreview = ref(null)

const onAvatarChange = () => {
    const file = avatarInput.value?.files?.[0]
    if (file) {
        avatarFile.value = file
        form.avatar = file
        const reader = new FileReader()
        reader.onload = (e) => avatarPreview.value = e.target.result
        reader.readAsDataURL(file)
    }
}

const submit = () => {
    if (isEdit.value) {
        // If editing yourself (navigated from /me/edit), the route is /me
        if (typeof window !== 'undefined' && window.location.pathname.startsWith('/me')) {
            form.post('/me')
        } else {
            form.post(`/users/${props.user.id}`)
        }
    } else {
        form.post('/users')
    }
}

const cancelHref = computed(() => {
    if (typeof window !== 'undefined' && window.location.pathname.startsWith('/me')) {
        return '/'
    }
    return '/users/'
})
</script>
