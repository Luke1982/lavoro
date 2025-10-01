<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent class="dark:bg-slate-900">
                <div v-if="serviceOrder.sent_to_administration"
                    class="mb-4 p-3 rounded border border-amber-400 bg-amber-50 text-amber-800 text-sm font-semibold">
                    Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
                </div>
                <div class="flex items-center justify-between mb-4">
                    <h1 class="text-2xl font-bold flex-1 uppercase dark:text-slate-100">Werkbon van {{
                        nlDate(serviceOrder.created_at)
                    }}</h1>
                    <div class="flex flex-col md:flex-row gap-2">
                        <Menu as="div" class="relative ml-4 inline-block text-left"
                            v-if="hasAnyPermission(['serviceorder.export_pdf', 'serviceorder.email_pdf', 'snelstart.send_serviceorder', 'serviceorder.email_pdf_with_checks'])">
                            <div>
                                <MenuButton
                                    class="inline-flex w-full justify-center gap-x-1.5 rounded-md bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-semibold text-gray-900 dark:text-slate-100 inset-ring-1 inset-ring-gray-300 dark:inset-ring-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/70">
                                    Acties
                                    <ChevronDownIcon class="-mr-1 size-5 text-gray-400" aria-hidden="true" />
                                </MenuButton>
                            </div>
                            <transition enter-active-class="transition ease-out duration-100"
                                enter-from-class="transform opacity-0 scale-95"
                                enter-to-class="transform opacity-100 scale-100"
                                leave-active-class="transition ease-in duration-75"
                                leave-from-class="transform opacity-100 scale-100"
                                leave-to-class="transform opacity-0 scale-95">
                                <MenuItems
                                    class="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white dark:bg-slate-800 shadow-lg outline-1 outline-black/5 dark:outline-slate-700/60 focus:outline-none">
                                    <div class="py-1 text-sm">
                                        <MenuItem v-if="hasPermission('serviceorder.export_pdf')" v-slot="{ active }">
                                        <button type="button" @click="openPdf"
                                            :class="[active ? 'opacity-90' : '', 'block w-full text-left px-4 py-2 bg-[#FF0000] text-white font-semibold rounded-sm']">
                                            <span class="inline-flex items-center">
                                                <span
                                                    class="bg-white text-[#FF0000] font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                                                Exporteer PDF
                                            </span>
                                        </button>
                                        </MenuItem>
                                        <MenuItem v-if="hasPermission('serviceorder.email_pdf')" v-slot="{ active }">
                                        <button type="button" @click="emailPdf" :disabled="emailing"
                                            :class="[active ? 'bg-gray-100 text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300', 'block w-full text-left px-4 py-2', emailing ? 'opacity-60 cursor-not-allowed' : '']">
                                            <span class="inline-flex items-center" v-if="!emailing">
                                                <span
                                                    class="bg-[#FF0000] text-white font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                                                E-mail PDF
                                            </span>
                                            <span v-else>Versturen...</span>
                                        </button>
                                        </MenuItem>
                                        <MenuItem
                                            v-if="serviceOrder.servicejobs.length > 0 && hasPermission('serviceorder.email_pdf_with_checks')"
                                            v-slot="{ active }">
                                        <button type="button" @click="emailPdfWithJobs" :disabled="emailingCombined"
                                            :class="[active ? 'bg-gray-100 text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300', 'block w-full text-left px-4 py-2', emailingCombined ? 'opacity-60 cursor-not-allowed' : '']">
                                            <span class="inline-flex items-center" v-if="!emailingCombined">
                                                <span
                                                    class="bg-[#FF0000] text-white font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                                                E-mail PDF + keuringen
                                            </span>
                                            <span v-else>Versturen...</span>
                                        </button>
                                        </MenuItem>
                                        <MenuItem
                                            v-if="!serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder')"
                                            v-slot="{ active }">
                                        <button type="button" @click="sendToSnelStart"
                                            :class="[active ? 'bg-gray-100 text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300', 'block w-full text-left px-4 py-2']">
                                            Verstuur naar SnelStart
                                        </button>
                                        </MenuItem>
                                        <MenuItem
                                            v-else-if="serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder')"
                                            v-slot="{ active }">
                                        <span
                                            :class="[active ? 'bg-gray-100' : '', 'block px-4 py-2 text-gray-500 cursor-default']">Al
                                            naar administratie</span>
                                        </MenuItem>
                                    </div>
                                </MenuItems>
                            </transition>
                        </Menu>
                        <span class="ml-2 px-3 py-1.5 inline-flex items-center text-sm rounded border"
                            :class="serviceOrderPillColorClasses(serviceOrder)">
                            {{ serviceOrderPillText(serviceOrder) }}
                        </span>
                    </div>
                </div>
                <div class="grid grid-cols-12 gap-y-2 border-b border-gray-200 dark:border-slate-700/60 pb-4">
                    <div class="col-span-2 text-xs text-gray-600 dark:text-slate-400">
                        Naam klant
                    </div>
                    <div class="col-span-4">
                        <component :is="hasPermission('customer.read') ? Link : 'span'"
                            :href="`/customers/${serviceOrder.customer.id}`" :class="{
                                'text-gray-800 dark:text-slate-200': true,
                                'underline dark:hover:text-slate-400 hover:text-gray-600': hasPermission('customer.read')
                            }">
                            {{ serviceOrder.customer.name }}
                        </component>
                    </div>
                    <div class="col-span-2 text-xs text-gray-600 dark:text-slate-400">
                        Adres
                    </div>
                    <div class="col-span-4">
                        <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank"
                            class="underline text-gray-800 dark:text-slate-200 hover:text-gray-600 dark:hover:text-slate-400">{{
                                serviceOrder.customer.address
                            }}, {{
                                serviceOrder.customer.postal_code }} {{
                                serviceOrder.customer.city }}
                        </a>
                    </div>
                </div>
                <h2
                    class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                    Uitgevoerde werkzaamheden</h2>
                <div class="grid grid-cols-12 mt-2">
                    <div class="col-span-12">
                        <EditableTextField type="textarea" v-model="form.description"
                            :readonly="serviceOrder.status === 'closed'" @update="val => { form.description = val; }"
                            placeholder="Beschrijf hier kort de uitgevoerde werkzaamheden" />
                    </div>
                </div>
                <div v-auto-animate class="my-4" v-if="hasPermission('servicejob.read')">
                    <h2
                        class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                        Keuringen</h2>
                    <div class="grid grid-cols-12 mt-4"
                        v-if="hasPermission('servicejob.create') && serviceOrder.status !== 'closed'">
                        <div class="col-span-12 flex">
                            <ComboBox :options="internalAssets" class="flex-grow" v-model="assetToCheck" />
                            <button @click="addServiceJob"
                                class="w-auto md:w-40 ml-2 px-4 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">
                                Keuren
                            </button>
                        </div>
                    </div>
                    <div v-if="serviceOrder.servicejobs.length > 0"
                        class="grid-cols-12 lg:grid mt-6 text-xs gap-4 font-bold border-b-1 border-gray-300 dark:border-slate-700/60 pb-3 hidden dark:text-slate-300">
                        <div class="col-span-5">Machine</div>
                        <div class="col-span-2">Uitkomst</div>
                        <div class="col-span-2">Tijdelijke goedkeur</div>
                        <div class="col-span-2">Afgerond op</div>
                    </div>
                    <ServiceJobRow v-for="job in serviceOrder.servicejobs" :key="job.id" :servicejob="job" class="mt-4"
                        :asset="job.asset" />
                </div>
                <template v-if="serviceOrder.tickets.length > 0 || hasPermission('ticket.add_to_serviceorder')">
                    <h2
                        class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                        Storingen</h2>
                    <div class="grid grid-cols-12 mt-4"
                        v-if="hasPermission('ticket.add_to_serviceorder') && serviceOrder.status !== 'closed'">
                        <div class="col-span-12 flex flex-col md:flex-row">
                            <ComboBox :options="internalTickets" class="flex-grow" v-model="ticketToSolve" />
                            <button @click="attachTicket"
                                class="w-full md:w-40 ml-0 md:ml-2 mt-2 md:mt-0 px-4 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">
                                Voeg storing toe
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-wrap" v-auto-animate>
                        <div class="w-full md:w-1/2 odd:pr-2 even:pl-2 mt-4" v-for="ticket in serviceOrder.tickets"
                            :key="ticket.id">
                            <TicketCard :ticket="ticket" :disconnect="'service_order_id'" />
                        </div>
                    </div>
                </template>
                <h2
                    class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                    Materialen</h2>
                <div class="grid grid-cols-12 mt-4">
                    <div class="col-span-12 flex flex-col md:flex-row items-start"
                        v-if="serviceOrder.status !== 'closed'">
                        <div class="flex flex-grow w-full">
                            <div class="flex flex-col flex-grow">
                                <span class="text-sm mb-2">Kies een materiaal</span>
                                <ComboBox :options="internalMaterials" class="flex-grow" v-model="materialToAdd" />
                            </div>
                            <div class="flex flex-col w-30 ml-2">
                                <span class="text-sm mb-2">Aantal</span>
                                <TextInput v-model="materialsForm.quantity" type="number" placeholder="Aantal" />
                            </div>
                        </div>
                        <button @click="attachMaterial" :disabled="serviceOrder.sent_to_administration"
                            :class="'self-end mt-2 md:mt-0 ml-2 px-4 py-2 w-full md:w-50 rounded text-sm ' + ((serviceOrder.sent_to_administration) ? 'bg-gray-400 text-white cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer')">
                            Voeg toe
                        </button>
                    </div>
                    <div class="col-span-12 flex mt-5">
                        <div class="w-full">
                            <div v-if="serviceOrder.materials.length > 0"
                                class="hidden md:grid grid-cols-12 text-xs font-bold border-b-1 border-gray-300 dark:border-slate-700/60 pb-3 dark:text-slate-300">
                                <div :class="showFinancialUi ? 'col-span-5' : 'col-span-7'" class="pl-4">Materiaal</div>
                                <div :class="showFinancialUi ? 'col-span-2' : 'col-span-3'">Aantal</div>
                                <div v-if="showFinancialUi" class="col-span-2">Prijs per stuk</div>
                                <div v-if="showFinancialUi" class="col-span-2">Totaal</div>
                                <div class="col-span-1">Acties</div>
                            </div>
                            <div v-auto-animate>
                                <div v-for="material in serviceOrder.materials" :key="material.id"
                                    class="grid grid-cols-12 py-4 md:py-2 items-center odd:bg-gray-50 dark:odd:bg-slate-800/40 px-4 md:px-0 relative">
                                    <div
                                        :class="'col-span-12 flex flex-col md:pl-4 ' + (showFinancialUi ? 'md:col-span-5' : 'md:col-span-7')">
                                        <span class="font-bold text-xs block lg:hidden">Materiaal</span>
                                        {{ material.name }}
                                    </div>
                                    <div
                                        :class="'col-span-12 flex flex-col mt-2 md:mt-0 ' + (showFinancialUi ? 'md:col-span-2' : 'md:col-span-3')">
                                        <span class="font-bold text-xs block lg:hidden">Aantal</span>
                                        <template
                                            v-if="!serviceOrder.sent_to_administration && serviceOrder.status !== 'closed'">
                                            <EditableTextField inputType="number" v-model="material.pivot.quantity"
                                                class="w-full" @update="val => {
                                                    materialsForm.quantity = val;
                                                    updateMaterialQuantity(material.pivot.id);
                                                }" />
                                        </template>
                                        <span v-else class="text-sm">{{ material.pivot.quantity }}</span>
                                    </div>
                                    <div v-if="showFinancialUi"
                                        class="col-span-6 md:col-span-2 flex flex-col mt-2 md:mt-0">
                                        <span class="font-bold text-xs block lg:hidden">Prijs pst.</span>
                                        € {{ Number(material.price).toFixed(2) }}
                                    </div>
                                    <div v-if="showFinancialUi"
                                        class="col-span-6 md:col-span-2 flex flex-col mt-2 md:mt-0">
                                        <span class="font-bold text-xs block lg:hidden">Totaal</span>€ {{
                                            (Number(material.pivot.quantity) *
                                                Number(material.price)).toFixed(2) }}
                                    </div>
                                    <div class="absolute md:relative top-3 right-3 lg:top-0 lg:right-0 col-span-1"
                                        v-if="!serviceOrder.sent_to_administration && serviceOrder.status !== 'closed'">
                                        <TrashIcon class="size-6 md:size-5 text-red-500 cursor-pointer"
                                            @click="detachMaterial(material.pivot.id)"
                                            v-tooltip="'Verwijder dit materiaal van de werkbon'" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="flex items-center justify-between my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2">
                    <h2 class="text-lg font-medium dark:text-slate-200">Afsluiting en opmerkingen</h2>
                    <button v-if="canSeeFinancials" type="button" @click="showFinancial = !showFinancial"
                        class="text-gray-500 hover:text-gray-700"
                        v-tooltip="showFinancial ? 'Verberg prijzen' : 'Toon prijzen'">
                        <span class="text-xl leading-none select-none">$</span>
                    </button>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-full md:w-1/2 flex flex-col pr-0 md:pr-3">
                        <EditableTextField v-model="form.signed_by" class="w-full mb-5"
                            :readonly="serviceOrder.status === 'closed'" @update="val => { form.signed_by = val; }"
                            placeholder="Voer de naam van degene in die de werkbon tekent" />
                        <div class="relative" v-if="!editingSignature">
                            <img :src="serviceOrder.signature_base64" alt="">
                            <PencilSquareIcon v-if="serviceOrder.status !== 'closed'"
                                class="absolute top-2 right-2 transform w-5 h-5 text-gray-600 dark:text-slate-400 cursor-pointer hover:text-gray-500 dark:hover:text-slate-300"
                                @click="editingSignature = true" />
                        </div>
                        <div class="relative" v-if="editingSignature">
                            <SignaturePad v-model="form.signature_base64"
                                :readonly="serviceOrder.status === 'closed'" />
                            <XMarkIcon
                                class="absolute top-2 right-2 transform w-5 h-5 text-red-600 dark:text-red-400 cursor-pointer hover:text-red-500 dark:hover:text-red-300"
                                @click="editingSignature = false" v-if="serviceOrder.signature_base64" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 pl-0 md:pl-3 mt-4 md:mt-0">
                        <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
                            :disabled="serviceOrder.status === 'closed'" :remarkable-id="serviceOrder.id"
                            :comments="serviceOrder.remarks" class="mt-8" />
                    </div>
                </div>
            </BoxComponent>
            <button class="w-full p-4 rounded-md bg-green-600 text-white mt-3 hover:bg-green-700"
                @click="updateStatus('closed')"
                v-if="serviceOrder.status !== 'closed' && hasPermission('serviceorder.close')">Werkbon
                afsluiten</button>
            <button class="w-full p-4 rounded-md bg-blue-500 text-white mt-3" @click="updateStatus('open')"
                v-else-if="serviceOrder.status !== 'open' && hasPermission('serviceorder.reopen')">Werkbon
                heropenen</button>
        </template>
        <template #sidebar>
            <div class="space-y-4 mt-6 md:mt-0">
                <div
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 p-4 text-sm">
                    <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Werkbon details</h3>
                    <div class="flex justify-between py-1 border-b border-gray-100 dark:border-slate-800/60">
                        <span class="text-gray-500 dark:text-slate-400">Datum</span>
                        <span>{{ nlDate(serviceOrder.created_at) }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-100 dark:border-slate-800/60">
                        <span class="text-gray-500 dark:text-slate-400">Klant</span>
                        <component :is="hasPermission('customer.read') ? Link : 'span'"
                            :href="`/customers/${serviceOrder.customer.id}`" :class="{
                                'text-gray-800 dark:text-slate-200': true,
                                'underline dark:hover:text-slate-400 hover:text-gray-600': hasPermission('customer.read')
                            }">{{ serviceOrder.customer.name }}</component>
                    </div>
                    <div class="py-1 border-b border-gray-100 dark:border-slate-800/60">
                        <span class="text-gray-500 dark:text-slate-400 block">Adres</span>
                        <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank"
                            class="underline text-xs break-words text-gray-800 dark:text-slate-200 hover:text-gray-600 dark:hover:text-slate-400">
                            {{ serviceOrder.customer.address }}, {{ serviceOrder.customer.postal_code }} {{
                                serviceOrder.customer.city }}
                        </a>
                    </div>
                    <div class="flex items-center justify-between py-2">
                        <span class="text-gray-500 dark:text-slate-400">Status</span>
                        <span class="px-2 py-0.5 text-xs rounded border"
                            :class="serviceOrderPillColorClasses(serviceOrder)">{{ serviceOrderPillText(serviceOrder)
                            }}</span>
                    </div>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 p-4 text-sm"
                    v-if="showFinancialUi">
                    <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Materiaaloverzicht</h3>
                    <div class="flex justify-between py-1">
                        <span class="text-gray-500 dark:text-slate-400">Subtotaal</span>
                        <span>€ {{ materialsSubtotal.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-gray-500 dark:text-slate-400">BTW (21%)</span>
                        <span>€ {{ materialsVat.toFixed(2) }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-t mt-2 font-semibold text-base">
                        <span>Totaal</span>
                        <span>€ {{ materialsTotal.toFixed(2) }}</span>
                    </div>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 p-4 text-sm"
                    v-if="serviceOrder.activities?.length">
                    <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Tijdlijn</h3>
                    <TimelineComponent :activities="serviceOrder.activities" />
                </div>
                <div v-if="hasPermission('serviceorder.export_pdf')
                    || hasPermission('serviceorder.email_pdf')
                    || (serviceOrder.servicejobs.length > 0 && hasPermission('serviceorder.email_pdf_with_checks'))
                    || (!serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder'))"
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 p-4 flex flex-col gap-2">
                    <a v-if="hasPermission('serviceorder.export_pdf')"
                        :href="`/serviceorders/${serviceOrder.id}/export/pdf`" target="_blank" rel="noopener"
                        class="inline-flex items-center justify-center px-3 py-2 bg-[#FF0000] text-white rounded hover:opacity-90 text-sm w-full text-center font-semibold">
                        <span
                            class="bg-white text-[#FF0000] font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                        Exporteer PDF
                    </a>
                    <button v-if="hasPermission('serviceorder.email_pdf')" @click="emailPdf"
                        class="inline-flex items-center justify-center px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm w-full font-semibold">
                        <span
                            class="bg-[#FF0000] text-white font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                        E-mail PDF
                    </button>
                    <button
                        v-if="serviceOrder.servicejobs.length > 0 && hasPermission('serviceorder.email_pdf_with_checks')"
                        @click="emailPdfWithJobs"
                        class="inline-flex items-center justify-center px-3 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm w-full font-semibold">
                        <span
                            class="bg-[#FF0000] text-white font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                        E-mail PDF + keuringen
                    </button>
                    <button v-if="!serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder')"
                        @click="sendToSnelStart"
                        class="inline-flex items-center justify-center px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm w-full">Verstuur
                        naar SnelStart</button>
                    <span
                        v-else-if="serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder')"
                        class="px-3 py-2 text-sm rounded bg-green-100 text-green-700 border border-green-300 text-center">Verzonden
                        administratie</span>
                </div>
            </div>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import ServiceJobRow from '@/Components/ServiceJobRow.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { mapsLinkFromCustomer, nlDate, hasPermission, hasAnyPermission, serviceOrderPillText, serviceOrderPillColorClasses } from '@/Utilities/Utilities';
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
import { PencilSquareIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import SignaturePad from '@/Components/UI/SignaturePad.vue';
import RemarksComponent from '@/Components/RemarksComponent.vue';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    },
    allMaterials: {
        type: Array,
        required: true
    }
});

const editingSignature = ref(props.serviceOrder.signature_base64 === null);

const internalMaterials = props.allMaterials.slice().sort((a, b) =>
    a.name.localeCompare(b.name)
).map((material) => {
    return {
        id: material.id,
        name: `${material.name}, code ${material.code}, voorraad ${material.stock}, prijs € ${material.price}`,
    };
});
const materialToAdd = ref(internalMaterials[0]?.id || null);

const internalAssets = props.serviceOrder.customer.assets.slice().sort((a, b) =>
    a.product.product_type.name.localeCompare(b.product.product_type.name)
).map((asset) => {
    return {
        id: asset.id,
        name: `${asset.product.product_type.name}: ${asset.product.brand.name} ${asset.product.model} (${asset.serial_number}), ${asset.status}. Verloopt op ${nlDate(asset.next_service_date)}`,
    };
});
const internalTickets = ref([]);

watch(
    () => props.serviceOrder.tickets,
    (newTickets) => {
        if (!hasPermission('ticket.add_to_serviceorder')) {
            internalTickets.value = [];
            return;
        }
        internalTickets.value = props.serviceOrder.customer.tickets.slice()
            .filter(ticket => ticket.status !== 'Gesloten' && newTickets.map(t => t.id).indexOf(ticket.id) === -1)
            .sort((a, b) =>
                a.asset.product.product_type.name.localeCompare(b.asset.product.product_type.name)
            )
            .map((ticket) => {
                return {
                    id: ticket.id,
                    name: `${ticket.asset.product.product_type.name}: ${ticket.asset.product.brand.name} ${ticket.asset.product.model} (${ticket.asset.serial_number}), ${ticket.subject}`,
                };
            })
    },
    { deep: true, immediate: true }
)

const assetToCheck = ref(internalAssets[0]?.id || null);
const ticketToSolve = ref(internalTickets.value[0]?.id || null);

const form = useForm({
    ...props.serviceOrder
});

const updateStatus = (newStatus) => {
    if (newStatus === form.status) return;

    if (newStatus === 'closed') {
        if (!canClose.value) {
            alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.');
            return;
        }
        if (!confirm(`Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.`)) {
            return;
        }
    }
    form.status = newStatus;
    form.put(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
    });
};

const materialsForm = useForm({
    quantity: 1,
});

const newServicejobForm = useForm({
    service_order_id: props.serviceOrder.id,
    asset_id: assetToCheck.value,
    outcome: 'Nog geen uitkomst',
});

const addServiceJob = () => {
    newServicejobForm.asset_id = assetToCheck.value;
    newServicejobForm.post(`/servicejobs`, {
        preserveScroll: true
    })
};

watch(
    [
        () => form.description,
        () => form.signed_by,
        () => form.signature_base64,
    ],
    () => {
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingSignature.value = false;
            }
        });
    }
)

const attachTicket = () => {
    if (!hasPermission('ticket.add_to_serviceorder')) return;
    if (!ticketToSolve.value) return;
    form.post(`/serviceorders/${props.serviceOrder.id}/tickets/${ticketToSolve.value}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            internalTickets.value = internalTickets.value.filter(ticket => ticket.id !== ticketToSolve.value);
        }
    });
};

const attachMaterial = () => {
    if (!materialToAdd.value || materialsForm.quantity <= 0) return;

    materialsForm.post(`/serviceorders/${props.serviceOrder.id}/materials/${materialToAdd.value}`, {
        preserveScroll: true,
    });
};

const detachMaterial = (materiableId) => {
    materialsForm.delete(`/serviceorders/${props.serviceOrder.id}/materials/${materiableId}`, {
        preserveScroll: true,
    });
};

const emailPdf = () => {
    if (emailing.value) return;
    emailing.value = true;
    form.post(`/serviceorders/${props.serviceOrder.id}/email-pdf`, {
        preserveScroll: true,
        onFinish: () => { emailing.value = false; }
    });
};

const emailing = ref(false);
const emailingCombined = ref(false);
const openPdf = () => {
    window.open(`/serviceorders/${props.serviceOrder.id}/export/pdf`, '_blank');
};

const emailPdfWithJobs = () => {
    if (emailingCombined.value) return;
    emailingCombined.value = true;
    form.post(`/serviceorders/${props.serviceOrder.id}/email-pdf-with-jobs`, {
        preserveScroll: true,
        onFinish: () => { emailingCombined.value = false; }
    });
};

const updateMaterialQuantity = (materiableId) => {
    materialsForm.put(`/serviceorders/${props.serviceOrder.id}/materials/${materiableId}`, {
        preserveScroll: true,
        onSuccess: () => {
            materialsForm.reset()
        }
    });
};

const sendForm = useForm({});
const sendToSnelStart = () => {
    if (props.serviceOrder.sent_to_administration) {
        return;
    }
    sendForm.post(`/serviceorders/${props.serviceOrder.id}/send-snelstart`, {
        preserveScroll: true,
    });
};

const materialsSubtotal = computed(() => {
    return props.serviceOrder.materials.reduce((sum, m) => {
        return sum + (Number(m.pivot.quantity) * Number(m.price));
    }, 0);
});
const materialsVat = computed(() => materialsSubtotal.value * 0.21);
const materialsTotal = computed(() => materialsSubtotal.value + materialsVat.value);


const showFinancial = ref(false);
const canSeeFinancials = computed(() => hasPermission('serviceorder.see_financials'));
const showFinancialUi = computed(() => canSeeFinancials.value && showFinancial.value);

const canClose = computed(() => {
    const name = (form.signed_by ?? '').toString().trim();
    const sig = (form.signature_base64 ?? '').toString().trim();
    return name.length > 0 && sig.length > 0;
});

</script>