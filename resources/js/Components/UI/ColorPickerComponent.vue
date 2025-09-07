<template>
    <ColorPicker alpha-channel="hide" :color="modelValue" default-format="rgb" @color-change="onColorChange">
        <template #hue-range-input-label>Basiskleur</template>
    </ColorPicker>
</template>
<script setup lang="ts">
import { ColorPicker } from 'vue-accessible-color-picker';
import { ref } from 'vue';

const emit = defineEmits(['update:modelValue']);

const { modelValue } = defineProps<{
    modelValue: string;
}>();

const firstEvent = ref(true);

function onColorChange(event: { colors: { hex: string } }) {
    const hex = event.colors.hex;
    if (firstEvent.value) {
        firstEvent.value = false;
        return;
    }
    const current = (modelValue || '').toLowerCase();
    const next = (hex || '').toLowerCase();
    if (next === current) {
        return;
    }
    emit('update:modelValue', hex);
}
</script>
<style>
@import url('vue-accessible-color-picker/styles');

.vacp-actions,
.vacp-format-switch-button,
.vacp-color-input-group {
    display: none !important;
}
</style>