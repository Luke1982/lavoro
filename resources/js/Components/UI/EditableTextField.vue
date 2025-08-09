<template>
    <div :class="[editing ? '' : 'pr-5', 'relative']">
        <span v-if="!editing">{{ model ?? placeholder }}</span>

        <div class="flex min-w-0" v-if="editing">
            <TextInput v-if="type === 'input'" v-model="local" :rightCorners="false" class="flex-grow min-w-0"
                :type="inputType" :placeholder="placeholder" />
            <textarea v-else-if="type === 'textarea'" v-model="local"
                class="flex-grow p-2 border border-gray-300 rounded-l-md" rows="8"></textarea>
            <button @click="save" class="px-3 py-1 bg-green-600 text-white rounded-r cursor-pointer hover:bg-green-700">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5" fill="currentColor">
                    <path
                        d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
                </svg>
            </button>
        </div>

        <PencilSquareIcon v-if="!editing"
            class="size-5 text-gray-600 absolute right-2 top-2 transform -translate-y-1/2 cursor-pointer"
            @click="startEdit" />
    </div>
</template>

<script setup>
import { ref, watchEffect } from 'vue';
import { PencilSquareIcon } from '@heroicons/vue/24/outline';
import TextInput from './TextInput.vue';

const emit = defineEmits(['update']);

const model = defineModel();
defineProps({
    type: { type: String, default: 'input' },
    inputType: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
});

const editing = ref(false);
const local = ref(model.value);

watchEffect(() => {
    if (!editing.value) local.value = model.value;
});

function startEdit() {
    editing.value = true;
    local.value = model.value;
}

function save() {
    model.value = local.value;
    editing.value = false;
    emit('update', local.value);
}
</script>