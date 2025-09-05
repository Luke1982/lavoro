<template>
    <div aria-live="assertive"
        class="pointer-events-none fixed inset-0 flex items-end px-4 py-6 lg:items-start sm:p-6 z-50">
        <div class="flex w-full flex-col items-center space-y-4 lg:items-end">
            <transition enter-active-class="transform ease-out duration-300 transition"
                enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                leave-to-class="opacity-0">
                <div v-if="localFlashMessage"
                    :class="[type === 'error' ? 'bg-red-600' : 'bg-green-600', 'pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg shadow-lg ring-opacity-5']">
                    <div class="p-4">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <CheckCircleIcon v-if="type === 'success'" class="h-6 w-6 text-white"
                                    aria-hidden="true" />
                                <XCircleIcon v-else-if="type === 'error'" class="h-6 w-6 text-white"
                                    aria-hidden="true" />
                            </div>
                            <div class="ml-3 w-0 flex-1 pt-0.5">
                                <p class="text-sm font-medium text-white">
                                    {{ title }}
                                </p>
                                <p class="mt-1 text-sm text-white">
                                    {{ localFlashMessage }}
                                </p>
                            </div>
                            <div class="ml-4 flex flex-shrink-0">
                                <button type="button" @click="closeNotification"
                                    class="inline-flex rounded-md bg-white text-green-700 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                    <span class="sr-only">Close</span>
                                    <SolidXMarkIcon class="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import { XMarkIcon as SolidXMarkIcon } from '@heroicons/vue/20/solid'

const page = usePage()

const flashSuccess = computed(() => page.props.flash.success)
const flashError = computed(() => page.props.flash.error)

const localFlashMessage = ref(null)
const type = ref('success')
const title = ref('')
let notificationTimer = null

watch([flashSuccess, flashError], ([newSuccess, newError]) => {
    clearTimeout(notificationTimer)

    if (newSuccess) {
        localFlashMessage.value = newSuccess
        type.value = 'success'
        title.value = 'Gelukt'
        startAutoClear()
    } else if (newError) {
        if (typeof newError === 'object') {
            localFlashMessage.value = Object.values(newError).join(' ')
        } else {
            localFlashMessage.value = newError
        }
        type.value = 'error'
        title.value = 'Er ging iets mis'
        startAutoClear()
    } else {
        localFlashMessage.value = null
    }
})

function startAutoClear() {
    notificationTimer = setTimeout(() => {
        closeNotification()
    }, 5000)
}

function closeNotification() {
    localFlashMessage.value = null
    page.props.flash.success = null
    page.props.flash.error = null
    clearTimeout(notificationTimer)
}
</script>