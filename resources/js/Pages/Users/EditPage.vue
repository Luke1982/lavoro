<template>
    <div class="max-w-5xl mx-auto py-6">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-gray-900">{{ isEdit ? 'Gebruiker' : 'Nieuwe gebruiker' }}</h1>
            <p v-if="!isEdit" class="mt-1 text-sm text-gray-500">Voer de gegevens in om een gebruiker toe te voegen.</p>
        </div>

        <form class="space-y-10">
            <!-- Profile Section -->
            <section class="bg-white rounded-lg border border-gray-200 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h2 class="text-sm font-medium text-gray-900">Profiel</h2>
                    <p class="mt-1 text-xs text-gray-500">Deze informatie kan door anderen worden gezien.</p>
                </div>
                <div class="p-6 space-y-6">
                    <div class="flex items-center gap-4">
                        <div
                            class="h-14 w-14 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden text-base font-semibold text-gray-600 ring-1 ring-gray-300">
                            <img v-if="avatarPreview" :src="avatarPreview" class="object-cover w-full h-full" />
                            <img v-else-if="isEdit && user?.avatar" :src="user.avatar"
                                class="object-cover w-full h-full" />
                            <span v-else>{{ initials(form.name) }}</span>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <label
                                    class="cursor-pointer inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-600">Wijzig
                                    <input @change="onAvatarChange" ref="avatarInput" type="file" accept="image/*"
                                        class="hidden" />
                                </label>
                                <button v-if="avatarFile" type="button" @click="clearAvatar"
                                    class="text-xs text-gray-500 hover:text-gray-700">Reset</button>
                            </div>
                            <p class="text-[11px] text-gray-400">PNG, JPG tot 3MB.</p>
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
                        <label class="block text-xs font-medium text-gray-700 mb-1">Wachtwoord</label>
                        <TextInput v-model="form.password" type="password" label="Wachtwoord" required
                            :has-error="form.errors.password" :error-message="form.errors.password" />
                    </div>
                    <div v-else>
                        <TextInput v-model="form.password" type="password" label="Nieuw wachtwoord (optioneel)"
                            :has-error="form.errors.password" :error-message="form.errors.password" />
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
                <Link href="/users/" class="text-sm text-gray-500 hover:text-gray-700">Annuleren</Link>
            </div>
        </form>
    </div>
</template>
<script setup>
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({ user: Object })

const isEdit = computed(() => !!props.user)

const form = useForm({ name: props.user?.name || '', email: props.user?.email || '', password: '', avatar: null })

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
        form.post(`/users/${props.user.id}`)
    } else {
        form.post('/users')
    }
}
</script>
