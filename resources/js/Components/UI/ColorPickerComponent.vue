<template>
    <ColorPicker alpha-channel="hide" :color="modelValue" default-format="rgb" @color-change="onColorChange">
        <template #hue-range-input-label>Basiskleur</template>
    </ColorPicker>
</template>
<script setup lang="ts">
import { ColorPicker } from 'vue-accessible-color-picker';

const emit = defineEmits(['update:modelValue']);

const { modelValue } = defineProps<{
    modelValue: string;
}>();

function onColorChange(event: { colors: { hex } }) {
    console.log('Color changed:', event.colors.hex);
    emit('update:modelValue', event.colors.hex);
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