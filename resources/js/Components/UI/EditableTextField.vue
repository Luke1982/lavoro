<template>
    <div ref="rootRef"
        :class="{ 'pr-5': !editing, 'relative pb-2 w-full': true, 'cursor-pointer': !disabled && !readonly, 'border-b-1 border-b-gray-200/70': decoration }"
        @click="onWrapperClick" v-auto-animate>
        <h3 v-if="label || $slots['label-suffix']" class="text-xs font-semibold mb-1 text-slate-500">{{ label }}
            <slot name="label-suffix" />
        </h3>
        <span v-if="!editing" class="pr-4 font-semibold">
            <slot name="display">{{ displayValue }}</slot>
        </span>

        <div class="flex min-w-0" v-if="editing">
            <slot v-if="$slots.open" name="open" :close="close" />
            <CurrencyInput v-else-if="type === 'input' && inputType === 'currency'" v-model="local"
                :rightCorners="false" :ring="false" class="flex-grow min-w-0" :placeholder="placeholder"
                :hasError="Boolean(error)" :errorMessage="error" />
            <TextInput v-else-if="type === 'input'" v-model="local" :rightCorners="false" :ring="false"
                class="flex-grow min-w-0" :type="htmlInputType" :placeholder="placeholder" :hasError="Boolean(error)"
                :errorMessage="error" />
            <textarea v-else-if="type === 'textarea'" v-model="local"
                :class="['flex-grow p-2 border rounded-l-md', error ? 'border-red-500' : 'border-gray-300']"
                rows="8"></textarea>
            <ComboBox v-else-if="type === 'combobox'" :modelValue="local" :options="options" :multiple="multiple"
                :initialId="local" :hasError="Boolean(error)" :errorMessage="error"
                :hasExternalSearching="hasExternalSearching" :searching="searching"
                @change="$emit('change', $event)"
                @update:modelValue="onComboBoxSelect" class="flex-grow min-w-0" />
            <button v-if="!$slots.open && inErrorState" @click.stop="revert"
                class="px-3 py-1 text-white rounded-r cursor-pointer" v-tooltip="'Wijzigingen ongedaan maken'">
                <ArrowUturnLeftIcon class="w-5 h-5 text-gray-500 dark:text-gray-200" />
            </button>
            <button v-else-if="!$slots.open && type !== 'combobox'" @click.stop="save"
                class="px-3 py-1 text-white rounded-r cursor-pointer">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"
                    class="w-5 h-5 text-gray-500 dark:text-gray-200" fill="currentColor">
                    <path
                        d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
                </svg>
            </button>
        </div>

        <PencilSquareIcon v-if="!editing && !readonly && !disabled && decoration"
            class="size-4 text-gray-400 dark:text-gray-300 absolute right-2 top-4 transform -translate-y-1/2 cursor-pointer"
            @click="startEdit" />
    </div>
</template>

<script setup>
import { computed, onUnmounted, ref, useSlots, watch, watchEffect } from 'vue';
import { ArrowUturnLeftIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';
import TextInput from '@/Components/UI/TextInput.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import { nlDate } from '@/Utilities/Utilities';

const emit = defineEmits(['update', 'revert', 'open', 'change']);

const model = defineModel();
const props = defineProps({
    // 'input' | 'textarea' | 'combobox'
    type: { type: String, default: 'input' },
    inputType: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
    error: { type: String, default: '' },
    // Combobox-only:
    options: { type: Array, default: () => [] },
    multiple: { type: Boolean, default: false },
    decoration: { type: Boolean, default: true },
    label: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    hasExternalSearching: { type: Boolean, default: false },
    searching: { type: Boolean, default: false },
});

const editing = ref(false);
const local = ref(model.value);
const rootRef = ref(null);
const slots = useSlots();
const close = () => { editing.value = false; };
// NO_SAVE = no save attempted in this edit session.
// anything else = the value we last submitted (and whose result we may still be waiting for).
const NO_SAVE = Symbol('no-save');
const lastSubmittedValue = ref(NO_SAVE);
// The model value at the start of the current edit session. Used by revert() —
// `model` itself is unreliable because save() may have already mutated it with
// the bad value the user typed.
const originalValue = ref(model.value);

const inErrorState = computed(() =>
    Boolean(props.error)
    && lastSubmittedValue.value !== NO_SAVE
    && local.value === lastSubmittedValue.value
);

// Map the semantic `inputType` to the actual HTML input `type`. We deliberately
// don't render <input type="number"> because the browser silently strips
// non-numeric characters to '' before we can send them — making it impossible
// to distinguish "user typed garbage" from "user cleared the field" on the
// server. Use plain text and let backend validation reject non-numeric input.
const htmlInputType = computed(() =>
    props.inputType === 'number' ? 'text' : props.inputType
);

const currencyFormatter = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' });

const displayValue = computed(() => {
    if (model.value === null || model.value === undefined || model.value === '') {
        return props.placeholder;
    }
    if (props.inputType === 'date') {
        return nlDate(model.value);
    }
    if (props.inputType === 'currency') {
        const num = Number(model.value);
        return Number.isNaN(num) ? model.value : currencyFormatter.format(num);
    }
    return model.value;
});

watchEffect(() => {
    if (!editing.value) local.value = model.value;
});

function startEdit() {
    emit('open');
    editing.value = true;
    local.value = model.value;
    originalValue.value = model.value;
    lastSubmittedValue.value = NO_SAVE;
}

function onWrapperClick(event) {
    if (editing.value || props.readonly || props.disabled) return;
    // If the click landed on (or inside) a link, let the navigation happen without
    // entering edit mode — flashing the editor open milliseconds before unmounting is bad UX.
    if (event.target.closest('a')) return;
    startEdit();
}

let closeTimer = null;

function save() {
    let value = local.value;
    if (props.inputType === 'number' && typeof value === 'string') {
        value = value.replace(',', '.');
    }
    lastSubmittedValue.value = value;
    model.value = value;
    emit('update', value);

    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => {
        if (lastSubmittedValue.value === NO_SAVE) return; // revert ran
        if (props.error) return; // validation failed — keep open
        editing.value = false;
        lastSubmittedValue.value = NO_SAVE;
    }, 200);
}

function revert() {
    clearTimeout(closeTimer);
    local.value = originalValue.value;
    model.value = originalValue.value;
    editing.value = false;
    lastSubmittedValue.value = NO_SAVE;
    emit('revert');
}

// Combobox selection saves immediately — there's no save button in combobox mode
// because selecting an option is itself the commit action.
function onComboBoxSelect(value) {
    local.value = value;
    save();
}

function handleOutsideClick(e) {
    if (rootRef.value && !rootRef.value.contains(e.target)) {
        if (inErrorState.value) return;
        if (slots.open) {
            editing.value = false;
        } else {
            save();
        }
    }
}

watch(editing, (isEditing) => {
    if (isEditing) {
        document.addEventListener('click', handleOutsideClick);
    } else {
        document.removeEventListener('click', handleOutsideClick);
    }
});

watch(() => props.error, (err) => {
    if (err) {
        editing.value = true;
        if (lastSubmittedValue.value === NO_SAVE) {
            lastSubmittedValue.value = local.value;
        }
    } else if (lastSubmittedValue.value !== NO_SAVE) {
        // Error cleared after a save we were tracking → success, close the field.
        editing.value = false;
        lastSubmittedValue.value = NO_SAVE;
    }
}, { flush: 'post' });

onUnmounted(() => {
    document.removeEventListener('click', handleOutsideClick);
    clearTimeout(closeTimer);
});
</script>