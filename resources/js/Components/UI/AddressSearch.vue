<template>
    <ComboBox v-model="chosen" :options="options" :label="label" :placeholder="placeholder" has-external-searching
        disable-internal-filter :searching="searching" :has-error="!!error" :error-message="error" @change="search" />
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useComboSearch } from '@/Composables/useComboSearch';

/**
 * Finds a Dutch address as it is typed and hands the whole of it to `pick`:
 * { label, address, postal_code, city, lat, lon }. The suggestions come from
 * PDOK through /combo/addresses; the one picked is fetched from /geocode/address.
 */
defineProps({
    label: { type: String, default: '' },
    placeholder: { type: String, default: 'Zoek een adres…' },
});

const emit = defineEmits(['pick']);

const { options, searching, search } = useComboSearch('addresses', [], true);
const chosen = ref(null);
const error = ref('');

watch(chosen, async (id) => {
    error.value = '';
    if (!id) {
        return;
    }
    try {
        const { data } = await axios.get('/geocode/address', { params: { id } });
        emit('pick', data);
    } catch (exception) {
        error.value = exception.response?.data?.message ?? 'Dat adres kon niet worden opgehaald.';
    }
});
</script>
