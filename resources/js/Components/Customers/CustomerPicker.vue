<template>
    <CreatableComboBox placeholder="Selecteer klant" create-label="Nieuwe klant" permission="customer.create"
        :modal="CustomerCreateModal" />
</template>

<script setup>
import CreatableComboBox from '@/Components/UI/CreatableComboBox.vue'
import CustomerCreateModal from '@/Components/Customers/CustomerCreateModal.vue'

/** The customer picker of the machine forms, which makes a missing customer on the spot. */
</script>
