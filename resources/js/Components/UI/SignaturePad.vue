<template>
    <div class="grid">
        <div class="flex flex-col items-center space-y-4">
            <div class="border border-gray-300 rounded-lg p-4 w-full">
                <VueSignaturePad ref="signature" height="200px" :maxWidth="2" :minWidth="2" :disabled="state.disabled"
                    :options="{
                        penColor: state.options.penColor, backgroundColor: state.options.backgroundColor
                    }" />
            </div>
            <div class="flex">
                <button class="border py-2 px-4 border-gray-400 rounded-l-md border-r-0 cursor-pointer" type="button"
                    @click="handleSave('image/jpeg')">Opslaan</button>
                <button class="border py-2 px-4 border-gray-400 border-r-0 cursor-pointer" type="button"
                    v-tooltip="'Maak de complete handtekening leeg'" @click="handleClear">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="w-5 h-5" fill="currentColor">
                        <path
                            d="M210.5 480L333.5 480L398.8 414.7L225.3 241.2L98.6 367.9L210.6 479.9zM256 544L210.5 544C193.5 544 177.2 537.3 165.2 525.3L49 409C38.1 398.1 32 383.4 32 368C32 352.6 38.1 337.9 49 327L295 81C305.9 70.1 320.6 64 336 64C351.4 64 366.1 70.1 377 81L559 263C569.9 273.9 576 288.6 576 304C576 319.4 569.9 334.1 559 345L424 480L544 480C561.7 480 576 494.3 576 512C576 529.7 561.7 544 544 544L256 544z" />
                    </svg>
                </button>
                <button class="border py-2 px-4 border-gray-400 rounded-r-md cursor-pointer" type="button"
                    v-tooltip="'Maak de laatste handtekening stap ongedaan'" @click="handleUndo">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5" fill="currentColor">
                        <path
                            d="M256 64c-56.8 0-107.9 24.7-143.1 64l47.1 0c17.7 0 32 14.3 32 32s-14.3 32-32 32L32 192c-17.7 0-32-14.3-32-32L0 32C0 14.3 14.3 0 32 0S64 14.3 64 32l0 54.7C110.9 33.6 179.5 0 256 0 397.4 0 512 114.6 512 256S397.4 512 256 512c-87 0-163.9-43.4-210.1-109.7-10.1-14.5-6.6-34.4 7.9-44.6s34.4-6.6 44.6 7.9c34.8 49.8 92.4 82.3 157.6 82.3 106 0 192-86 192-192S362 64 256 64z" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { VueSignaturePad } from "@selemondev/vue3-signature-pad"
import { ref } from "vue";

defineProps({
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const state = ref({
    options: {
        penColor: 'rgb(0, 0, 0)',
        backgroundColor: 'rgb(255, 255, 255)'
    },
    disabled: false,
})

const signature = ref();

const handleSave = (format) => {
    return emit('update:modelValue', signature.value.saveSignature(format))
};
const handleClear = () => {
    return signature.value.clearCanvas()
};
const handleUndo = () => {
    return signature.value.undo()
};
</script>