<template>
    <CreatableComboBox placeholder="Selecteer product" create-label="Nieuw product" permission="product.create"
        :modal="ProductCreateModal" />
</template>

<script setup>
import CreatableComboBox from '@/Components/UI/CreatableComboBox.vue'
import ProductCreateModal from '@/Components/Products/ProductCreateModal.vue'

/** The product picker of the machine forms, which makes a missing product on the spot. */
</script>
