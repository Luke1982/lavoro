<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex border-b-1 border-gray-200 pb-2 mb-4 justify-between">
                    <div class="flex">
                        <ExclamationCircleIcon class="h-6 w-6 text-yellow-500 mr-2" />
                        <h1 class="text-l font-medium">Gegevens van de storing</h1>
                    </div>
                    <div>
                        <CheckIcon class="h-6 w-6 text-green-500 cursor-pointer hover:text-green-700"
                            v-if="ticket.status.toLowerCase() === 'gesloten'"
                            v-tooltip="'Deze storing is \'Gesloten\''" />
                        <ClockIcon v-if="ticket.status.toLowerCase() === 'in behandeling'"
                            class="w-5 h-5 text-blue-500 cursor-pointer"
                            v-tooltip="'Deze storing is \'In behandeling\''" />
                        <NoSymbolIcon v-if="ticket.status.toLowerCase() === 'open'"
                            class="w-5 h-5 text-red-500 cursor-pointer" v-tooltip="'Deze storing is \'Open\''" />
                    </div>
                </div>
                <div class="grid grid-cols-12 mt-2 grid-wrap gap-4">
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Storing aan</span>
                    </div>
                    <div class="col-span-10">
                        {{ ticket.asset.product.brand.name }}
                        <Link class="text-blue-600 hover:text-blue-800 underline"
                            :href="`/products/${ticket.asset.product.id}`">{{ ticket.asset.product.model }}</Link> ({{
                                ticket.asset.product.product_type.name }}) met serienummer
                        <Link :href="`/assets/${ticket.asset.id}`" class="text-blue-600 hover:text-blue-800 underline">
                        {{ ticket.asset.serial_number }}</Link> bij
                        <Link :href="`/customers/${ticket.asset.customer.id}`"
                            class="text-blue-600 hover:text-blue-800 underline">{{ ticket.asset.customer.name }}</Link>
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Onderwerp</span>
                    </div>
                    <div class="col-span-10">
                        <EditableTextField v-model="form.subject" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Omschrijving</span>
                    </div>
                    <div class="col-span-10">
                        <EditableTextField v-model="form.description" type="textarea" class="w-full" />
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Status</span>
                    </div>
                    <div class="col-span-4">
                        <ComboBox :options="statusses" v-model="form.status" :initial-id="initialStatus.id"
                            class="w-full" v-if="hasPermission('ticket.change_status')" />
                        <span v-else>{{ form.status }}</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-xs font-bold">Prioriteit</span>
                    </div>
                    <div class="col-span-4">
                        <fieldset aria-label="Kies een prioriteit" v-if="hasPermission('ticket.alter_priority')">
                            <div class="grid grid-cols-3 gap-3">
                                <label v-for="prio in priorities" :key="prio.id" :aria-label="prio.name" :class="{
                                    'cursor-pointer group relative flex items-center justify-center rounded-md border p-2 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-disabled:opacity-25': true,
                                    'bg-red-100 text-red-600 hover:bg-red-600 hover:text-white focus:bg-red-600 has-checked:bg-red-600 border-red-600 has-checked:border-red-900': prio === priorities[2],
                                    'bg-yellow-100 text-yellow-600 hover:bg-yellow-600 hover:text-white focus:bg-yellow-600 has-checked:bg-yellow-600 border-yellow-600 has-checked:border-yellow-900': prio === priorities[1],
                                    'bg-green-100 text-green-600 hover:bg-green-600 hover:text-white focus:bg-green-600 has-checked:bg-green-600 border-green-600 has-checked:border-green-900': prio === priorities[0]
                                }">
                                    <input type="radio" name="option" :value="prio.id" :checked="prio === priorities[2]"
                                        v-model="form.priority"
                                        class="absolute inset-0 appearance-none focus:outline-none disabled:cursor-not-allowed" />
                                    <span class="text-sm font-medium uppercase group-has-checked:text-white">{{
                                        prio.name }}</span>
                                </label>
                            </div>
                        </fieldset>
                        <span v-else>{{ form.priority }}</span>
                    </div>
                </div>
            </BoxComponent>
            <RemarksComponent :remarkable-type="'App\\Models\\Ticket'" :remarkable-id="ticket.id"
                :comments="ticket.remarks" class="mt-8" />
        </template>
        <template #sidebar>
            <BoxComponent>
                <div class="flex border-b-1 border-gray-200 pb-2 mb-4">
                    <PhotoIcon class="size-6 mr-2" />
                    <h1 class="text-l font-medium">Afbeeldingen van de storing</h1>
                </div>
                <span v-if="ticket.images.length === 0" class="text-gray-500 text-sm mb-4 block">
                    Nog geen afbeeldingen, upload hieronder een afbeelding.
                </span>
                <ImageUploadComponent :existing="ticket.images" :imageable-id="ticket.id"
                    imageable-type="\App\Models\Ticket" />
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>

</template>
<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { CheckIcon, ClockIcon, ExclamationCircleIcon, NoSymbolIcon, PhotoIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    ticket: {
        type: Object,
        required: true
    },
    statusses: {
        type: Array,
        required: true
    },
    priorities: {
        type: Array,
        required: true
    }
});

const initialStatus = props.statusses.find(s => s.name === props.ticket.status);

const form = useForm({
    subject: props.ticket.subject,
    description: props.ticket.description,
    status: initialStatus.id,
    priority: props.priorities.find(p => p.name === props.ticket.priority).id
});

function patchTicketField(field, value) {
    form.transform(() => {
        return {
            [field]: value
        };
    }).patch(`/tickets/${props.ticket.id}`, {
        preserveScroll: true,
    });
}

watch(() => form.subject, (newVal) => {
    patchTicketField('subject', newVal);
});

watch(() => form.description, (newVal) => {
    patchTicketField('description', newVal);
});

watch(() => form.status, (newVal) => {
    const statusName = props.statusses.find(s => s.id === newVal)?.name || newVal;
    patchTicketField('status', statusName);
});

watch(() => form.priority, (newVal) => {
    const priorityName = props.priorities.find(p => p.id === newVal)?.name || newVal;
    patchTicketField('priority', priorityName);
});
</script>