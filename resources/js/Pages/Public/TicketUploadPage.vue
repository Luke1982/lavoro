<template>
    <div class="min-h-screen bg-slate-100 py-8 px-4">
        <div class="mx-auto w-full max-w-2xl">
            <div class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
                <header class="flex items-center gap-3 border-b border-slate-100 px-6 py-5">
                    <img v-if="company?.logo_url" :src="company.logo_url" :alt="company.name" class="h-9 w-auto" />
                    <span v-else class="text-lg font-bold text-slate-800">{{ company?.name || 'Service' }}</span>
                </header>

                <div class="px-6 py-6">
                    <h1 class="text-xl font-bold text-slate-900">Aanvullende informatie</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Over uw storingsmelding bij {{ machine }}<span v-if="serial">, serienummer {{
                            serial }}</span>.
                    </p>

                    <div v-if="closed" class="mt-6 rounded-xl bg-amber-50 px-4 py-4 text-sm text-amber-800">
                        Deze storing is inmiddels afgehandeld, daarom kunt u hier niets meer aanleveren.
                        Neem gerust contact met ons op als er nog iets speelt.
                    </div>

                    <template v-else>
                        <div v-if="flashSuccess" class="mt-6 rounded-xl bg-green-50 px-4 py-4 text-sm text-green-800">
                            {{ flashSuccess }}
                        </div>

                        <section v-if="requested.length" class="mt-6 rounded-xl bg-slate-50 px-4 py-4">
                            <p class="text-sm font-semibold text-slate-700">Wij ontvangen graag:</p>
                            <ul class="mt-2 space-y-1">
                                <li v-for="item in requested" :key="item"
                                    class="flex items-start gap-2 text-sm text-slate-600">
                                    <CheckIcon class="mt-0.5 size-4 flex-none text-green-600" />
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                        </section>

                        <form class="mt-6 space-y-5" @submit.prevent="submit">
                            <div>
                                <label for="files"
                                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors"
                                    :class="dragging ? 'border-blue-400 bg-blue-50' : 'border-slate-300 hover:border-slate-400'"
                                    @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
                                    @drop.prevent="onDrop">
                                    <ArrowUpTrayIcon class="size-7 text-slate-400" />
                                    <span class="text-sm font-semibold text-slate-700">
                                        Sleep bestanden hierheen of tik om te kiezen
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        Foto's, video's en documenten — maximaal {{ limits.max_files }} bestanden
                                    </span>
                                    <input id="files" ref="fileInput" type="file" multiple class="sr-only"
                                        :accept="acceptAttribute" @change="onPick" />
                                </label>

                                <ul v-if="chosen.length" class="mt-3 space-y-2">
                                    <li v-for="(file, index) in chosen" :key="file.name + index"
                                        class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2">
                                        <PaperClipIcon class="size-4 flex-none text-slate-400" />
                                        <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ file.name
                                            }}</span>
                                        <span class="flex-none text-xs text-slate-400">{{ readableSize(file.size)
                                            }}</span>
                                        <button type="button" class="flex-none text-slate-400 hover:text-red-600"
                                            :aria-label="`${file.name} verwijderen`" @click="remove(index)">
                                            <XMarkIcon class="size-4" />
                                        </button>
                                    </li>
                                </ul>

                                <p v-for="message in fileErrors" :key="message" class="mt-2 text-sm text-red-600">
                                    {{ message }}
                                </p>
                            </div>

                            <div>
                                <label for="note" class="mb-1 block text-sm font-medium text-slate-700">
                                    Toelichting <span class="font-normal text-slate-400">(optioneel)</span>
                                </label>
                                <textarea id="note" v-model="form.note" rows="4" :maxlength="limits.note_max"
                                    placeholder="Wanneer begon het probleem? Wat merkt u precies?"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 outline-none focus:border-blue-500" />
                                <p v-if="form.errors.note" class="mt-1 text-sm text-red-600">{{ form.errors.note }}</p>
                            </div>

                            <button type="submit" :disabled="form.processing || nothingToSend"
                                class="w-full rounded-lg bg-blue-700 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-40">
                                {{ form.processing ? 'Bezig met versturen…' : 'Versturen' }}
                            </button>

                            <p v-if="form.progress" class="text-center text-xs text-slate-400">
                                {{ form.progress.percentage }}% verstuurd
                            </p>
                        </form>

                        <section v-if="uploaded.length" class="mt-8 border-t border-slate-100 pt-5">
                            <p class="text-sm font-semibold text-slate-700">Al door u verstuurd</p>
                            <ul class="mt-2 space-y-1">
                                <li v-for="(item, index) in uploaded" :key="item.name + index"
                                    class="flex items-center gap-2 text-sm text-slate-500">
                                    <CheckCircleIcon class="size-4 flex-none text-green-600" />
                                    <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
                                    <span class="flex-none text-xs text-slate-400">{{ shortDate(item.at) }}</span>
                                </li>
                            </ul>
                        </section>
                    </template>
                </div>
            </div>

            <p v-if="expiresOn" class="mt-4 text-center text-xs text-slate-400">
                Deze pagina blijft bereikbaar tot en met {{ shortDate(expiresOn) }}.
            </p>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import {
    ArrowUpTrayIcon,
    CheckCircleIcon,
    CheckIcon,
    PaperClipIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline'
import EmptyLayout from '@/Layouts/EmptyLayout.vue'

const props = defineProps({
    machine: { type: String, required: true },
    serial: { type: String, default: null },
    customer: { type: String, default: '' },
    requested: { type: Array, default: () => [] },
    uploaded: { type: Array, default: () => [] },
    closed: { type: Boolean, default: false },
    expires_on: { type: String, default: null },
    limits: { type: Object, required: true },
})

const page = usePage()
const company = computed(() => page.props.company)
const flashSuccess = computed(() => page.props.flash?.success)
const expiresOn = computed(() => props.expires_on)

const chosen = ref([])
const localErrors = ref([])
const dragging = ref(false)
const fileInput = ref(null)

const form = useForm({ files: [], note: '' })

const acceptAttribute = computed(() => props.limits.kinds
    .flatMap((kind) => kind.extensions)
    .map((extension) => '.' + extension)
    .join(','))

const nothingToSend = computed(() => chosen.value.length === 0 && form.note.trim() === '')

/**
 * De server weigert een te groot bestand pas nadat het helemaal geüpload is, en dat
 * is over mobiel internet minuten wachten op een nee. Daarom hier dezelfde afweging,
 * in dezelfde volgorde en met dezelfde woorden: de soort komt van de extensie, en de
 * soort bepaalt de grens.
 */
function kindFor(file) {
    const extension = (file.name.split('.').pop() || '').toLowerCase()

    return props.limits.kinds.find((kind) => kind.extensions.includes(extension)) || null
}

function refuse(file) {
    const kind = kindFor(file)

    if (!kind) {
        return `${file.name} kunnen wij niet ontvangen. Stuur een foto, video, pdf of documentbestand.`
    }

    if (kind.max_kb > 0 && file.size > kind.max_kb * 1024) {
        return `${file.name} is te groot. Maximaal ${readableLimit(kind.max_kb)} per ${kind.noun}.`
    }

    return null
}

function addFiles(files) {
    const refusals = []

    files.forEach((file) => {
        const problem = refuse(file)

        if (problem) {
            refusals.push(problem)
            return
        }

        if (chosen.value.length >= props.limits.max_files) {
            refusals.push(`U kunt maximaal ${props.limits.max_files} bestanden tegelijk versturen.`)
            return
        }

        chosen.value.push(file)
    })

    localErrors.value = [...new Set(refusals)]
}

function onPick(event) {
    addFiles(Array.from(event.target.files || []))
    if (fileInput.value) fileInput.value.value = ''
}

function onDrop(event) {
    dragging.value = false
    addFiles(Array.from(event.dataTransfer?.files || []))
}

function remove(index) {
    chosen.value.splice(index, 1)
}

const fileErrors = computed(() => [
    ...localErrors.value,
    ...Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
])

function submit() {
    if (nothingToSend.value) return

    form.files = chosen.value
    form.post(page.url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            chosen.value = []
            localErrors.value = []
            form.reset('note')
        },
    })
}

function readableSize(bytes) {
    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`
}

function readableLimit(kilobytes) {
    return kilobytes >= 1024
        ? `${String(Math.round((kilobytes / 1024) * 10) / 10).replace('.', ',')} MB`
        : `${kilobytes} KB`
}

function shortDate(value) {
    if (!value) return ''
    return new Date(value).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })
}

defineOptions({ layout: EmptyLayout })
</script>
