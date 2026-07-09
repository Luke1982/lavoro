<template>
    <div v-if="status !== 'idle'"
        class="fixed top-4 right-4 px-4 py-3 rounded-lg shadow-lg z-[1000] max-w-sm transition-colors duration-500"
        :class="boxClasses" @click="status === 'failed' ? (showFailedList = !showFailedList) : null">

        <!-- Processing State -->
        <div v-if="status === 'processing'">
            <div class="flex items-center justify-between">
                <span>Adressen zoeken...</span>
                <span class="text-sm">{{ geocodedCount }} / {{ totalToGeocode }}</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: progress + '%' }"></div>
            </div>
        </div>

        <!-- Success State -->
        <div v-if="status === 'success'" class="flex items-center">
            <CheckCircleIcon class="h-8 w-8 mr-2" />
            <span>Alle {{ totalToGeocode }} adressen gevonden.</span>
        </div>

        <!-- Failed State -->
        <div v-if="status === 'failed'" class="cursor-pointer">
            <div class="flex items-center">
                <ExclamationTriangleIcon class="h-8 w-8 mr-2" />
                <span>
                    {{ total - failedGeocodes.length }} van {{ total }} adressen te zien op de
                    kaart, {{ failedGeocodes.length }} konden er niet worden gevonden.
                </span>
            </div>
            <transition name="fade-height">
                <div v-if="showFailedList" class="mt-2 pt-2 border-t"
                    :class="status === 'failed' ? 'border-red-300' : 'border-gray-300'">
                    <ul class="list-disc list-inside text-sm max-h-60 overflow-y-auto">
                        <li v-for="item in failedGeocodes" :key="item.id">{{ item.name }}</li>
                    </ul>
                </div>
            </transition>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { ExclamationTriangleIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    status: { type: String, required: true }, // idle, processing, success, failed
    geocodedCount: { type: Number, default: 0 },
    totalToGeocode: { type: Number, default: 0 },
    progress: { type: Number, default: 0 },
    failedGeocodes: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
});

const showFailedList = ref(false);

const boxClasses = computed(() => {
    switch (props.status) {
        case 'processing':
            return 'bg-gray-100 border border-gray-400 text-gray-700';
        case 'success':
            return 'bg-green-100 border border-green-400 text-green-700';
        case 'failed':
            return 'bg-red-100 border border-red-400 text-red-700';
        default:
            return '';
    }
});
</script>

<style scoped>
.fade-height-enter-active,
.fade-height-leave-active {
    transition: all 0.3s ease-in-out;
    max-height: 20rem;
}

.fade-height-enter-from,
.fade-height-leave-to {
    opacity: 0;
    transform: translateY(-10px);
    max-height: 0;
}
</style>
