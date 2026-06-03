<template>
    <div class="flex items-center justify-between">
        <div class="flex items-center">
            <Link href="/serviceorders" class="text-slate-400 text-sm font-medium">Werkbonnen</Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
            <span class="text-slate-800 font-bold text-sm">Werkbon #{{ serviceOrder.id }} van {{
                nlDate(serviceOrder.created_at) }}
                voor {{ serviceOrder.customer.name }}</span>
        </div>
        <SelectMenuComponent v-if="hasPermission('serviceorder.update')" v-model="form.type" :options="typeOptions"
            label="Type" />
    </div>
    <div class="flex items-center justify-between my-4">
        <h1 class="text-2xl font-bold flex items-center gap-2">
            Werkbon #{{ serviceOrder.id }}
            <BadgeComponent color="blue" :hasDot="false" v-if="serviceOrder.service_order_stage">
                {{ serviceOrder.service_order_stage.name }}
            </BadgeComponent>
        </h1>

    </div>
    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(header, index) in chapterHeaders" :key="index" :index="index">
                {{ header }}
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <template #chapter-0>
                <TwoThirdsOneThird>
                    <template #main>
                        <BoxComponent class="mb-4">
                            <div v-if="stages.length > 1" class="mb-4"
                                :class="{ 'pointer-events-none opacity-60': serviceOrder.is_closed && !hasPermission('serviceorder.reopen') }">
                                <StepsProgressBar :steps="stages" :model-value="serviceOrder.service_order_stage_id"
                                    @update:modelValue="onStageChange" />
                            </div>
                        </BoxComponent>
                        <BoxComponent>
                            <div class="flex items-center">
                                <div class="flex justify-between w-full flex-wrap md:flex-nowrap">
                                    <div class="flex w-full items-center">
                                        <DocumentTextIcon class="size-6 mr-2 flex-none object-cover" />
                                        <div class="flex flex-col">
                                            <span class="text-md font-bold">Details</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2">
                                <!-- Left column -->
                                <div class="flex flex-col gap-6 md:pr-8">
                                    <EditableTextField :disabled="!hasPermission('serviceorder.update')" type="combobox"
                                        label="Klant" v-model="form.customer_id" :options="internalCustomers">
                                        <template #display>
                                            <component :is="hasPermission('customer.read') ? Link : 'span'"
                                                :href="`/customers/${selectedCustomer.id}`" :class="{
                                                    'underline text-lavoro-blue': hasPermission('customer.read')
                                                }">{{ selectedCustomer.name }}</component>
                                        </template>
                                    </EditableTextField>
                                    <EditableTextField :disabled="!hasPermission('serviceorder.update')" type="combobox"
                                        label="Project" v-model="form.project_id" :options="internalProjects">
                                        <template #display>
                                            <component v-if="selectedProject"
                                                :is="hasPermission('project.read') ? Link : 'span'"
                                                :href="`/projects/${selectedProject.id}`" :class="{
                                                    'underline text-lavoro-blue': hasPermission('project.read')
                                                }">{{ selectedProject.title }}</component>
                                            <span v-else class="text-gray-400">—</span>
                                        </template>
                                    </EditableTextField>
                                </div>
                                <!-- Right column -->
                                <div class="flex flex-col gap-6 md:pl-8 md:border-l md:border-gray-200/70">
                                    <EditableTextField :disabled="true" label="Adres">
                                        <template #display>
                                            <a :href="mapsLinkFromCustomer(serviceOrder.customer)" target="_blank"
                                                class="underline text-lavoro-blue dark:text-slate-200 ">{{
                                                    serviceOrder.customer.address
                                                }}, {{
                                                    serviceOrder.customer.postal_code }} {{
                                                    serviceOrder.customer.city }}
                                            </a>
                                        </template>
                                    </EditableTextField>
                                    <EditableTextField :disabled="!hasPermission('serviceorder.update')"
                                        label="Externe referentie" v-model="form.external_purchaseorder_no"
                                        @update="val => { form.external_purchaseorder_no = val; }"
                                        placeholder="Externe referentie" />
                                </div>
                            </div>
                        </BoxComponent>
                        <TaskInstancesWidget :service-order-id="serviceOrder.id"
                            :instances="serviceOrder.task_instances" :available-tasks="availableTasks" class="my-4" />
                        <BoxComponent class="my-4">
                            <div class="flex items-center gap-x-3 mb-3 justify-between">
                                <div class="flex gap-x-3">
                                    <div
                                        class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                                        <BadgeCheck class="h-5 w-5 text-white" />
                                    </div>
                                    <div class="flex flex-col">
                                        <h3
                                            class="text-base font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-x-2">
                                            Keuringen
                                        </h3>
                                        <div class="text-slate-400 text-xs">Beheer en maak keuringen voor dit apparaat
                                        </div>
                                    </div>
                                </div>
                                <button v-if="hasPermission('servicejob.create') && !serviceOrder.is_closed"
                                    @click="addServiceJobFromSelectedAsset" :disabled="!selectedAsset"
                                    :class="['px-4 py-2 rounded text-sm font-semibold text-white transition-opacity', selectedAsset ? 'bg-lavoro-blue hover:opacity-90 cursor-pointer' : 'bg-lavoro-blue opacity-40 cursor-not-allowed']">
                                    + Keuring toevoegen
                                </button>
                            </div>
                            <div
                                class="flex justify-between divide-gray-200/70 divide-x-1 ring-1 ring-gray-200/70 rounded-lavoro-sm">
                                <AssetSelectMenu v-model="selectedAsset" :assets="customerAssets" class="p-4 w-1/4" />
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Datum ingebruikname"
                                    :value="selectedAsset?.date_in_service ?? '—'" class="p-4 w-1/4 justify-center" />
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Volgende keuring"
                                    :value="selectedAsset?.next_service_date ?? '—'" class="p-4 w-1/4 justify-center" />
                                <TitleValueIconComponent :icon="ClipboardDocumentListIcon" title="Totaal keuringen"
                                    :value="selectedAsset ? String(selectedAsset.total_servicejobs) : '—'"
                                    class="p-4 w-1/4 justify-center" />
                            </div>
                            <ServiceJobsTable :servicejobs="serviceOrder.servicejobs" class="mt-4" />
                        </BoxComponent>
                        <BoxComponent>
                            <MaterialsWidget :service-order-id="serviceOrder.id" :materials="serviceOrder.materials"
                                :all-materials="allMaterials" :is-closed="serviceOrder.is_closed"
                                :sent-to-administration="serviceOrder.sent_to_administration"
                                :type="serviceOrder.type" />
                        </BoxComponent>
                    </template>
                    <template #sidebar>
                        <BoxComponent padding="p-0" extra-classes="overflow-hidden">
                            <OpenStreetMapWidget
                                :address="`${serviceOrder.customer.address}, ${serviceOrder.customer.postal_code} ${serviceOrder.customer.city}`" />
                        </BoxComponent>
                        <BoxComponent v-if="timelineItems.length" class="mt-6">
                            <div class="flex">
                                <TimelineIcon class="size-6 mr-2 flex-none object-cover" />
                                <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Tijdlijn</h3>
                            </div>
                            <TimelineComponent :activities="timelineItems" />
                        </BoxComponent>
                        <BoxComponent class="mt-6">
                            <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
                                :disabled="serviceOrder.is_closed" :remarkable-id="serviceOrder.id"
                                :comments="serviceOrder.remarks" />
                        </BoxComponent>
                    </template>
                </TwoThirdsOneThird>
            </template>
        </ChapterContents>
    </ChaptersComponent>
    <div class="mt-100"></div>
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
                                                    Exporteer PDF voorbeeld
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
                                            v-if="!serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder') && snelStartEnabled"
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
                        <EditableTextField type="textarea" v-model="form.description" :readonly="serviceOrder.is_closed"
                            @update="val => { form.description = val; }"
                            placeholder="Beschrijf hier kort de uitgevoerde werkzaamheden" />
                    </div>
                </div>
                <div class="my-4" v-if="hasPermission('servicejob.read')">
                    <h2 v-if="serviceOrder.servicejobs.length > 0"
                        class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                        Keuringen</h2>
                    <div class="grid grid-cols-12 mt-4"
                        v-if="hasPermission('servicejob.create') && !serviceOrder.is_closed">
                        <div class="col-span-12 flex">
                            <ComboBox :options="internalAssets" class="flex-grow" v-model="assetToCheck"
                                :with-images="true" />
                            <button @click="addServiceJob"
                                class="w-auto md:w-40 ml-2 px-4 py-1.5 rounded text-sm bg-indigo-600 text-white hover:bg-indigo-700 cursor-pointer">
                                Keuren
                            </button>
                        </div>
                    </div>
                </div>
                <h2 v-if="serviceOrder.tickets.length > 0 || hasPermission('ticket.add_to_serviceorder')"
                    class="text-lg font-medium my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2 dark:text-slate-200">
                    Storingen</h2>
                <div class="grid grid-cols-12 mt-4"
                    v-if="hasPermission('ticket.add_to_serviceorder') && !serviceOrder.is_closed">
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


                <div
                    class="flex items-center justify-between my-4 border-b-gray-200 dark:border-slate-700/60 border-b-1 pb-2">
                    <h2 class="text-lg font-medium dark:text-slate-200">Afsluiting en opmerkingen</h2> <button
                        v-if="canSeeFinancials" type="button" @click="showFinancial = !showFinancial"
                        class="text-gray-500 hover:text-gray-700"
                        v-tooltip="showFinancial ? 'Verberg prijzen' : 'Toon prijzen'">
                        <span class="text-xl leading-none select-none">$</span>
                    </button>
                </div>
                <div class="flex flex-wrap">
                    <div class="w-full md:w-1/2 flex flex-col pr-0 md:pr-3">
                        <EditableTextField v-model="form.signed_by" class="w-full mb-5"
                            :readonly="serviceOrder.is_closed" @update="val => { form.signed_by = val; }"
                            placeholder="Voer de naam van degene in die de werkbon tekent" />
                        <div class="relative" v-if="!editingSignature">
                            <img :src="serviceOrder.signature_base64" alt="">
                            <PencilSquareIcon v-if="!serviceOrder.is_closed"
                                class="absolute top-2 right-2 transform w-5 h-5 text-gray-600 dark:text-slate-400 cursor-pointer hover:text-gray-500 dark:hover:text-slate-300"
                                @click="editingSignature = true" />
                        </div>
                        <div class="relative" v-if="editingSignature">
                            <SignaturePad v-model="form.signature_base64" :readonly="serviceOrder.is_closed" />
                            <XMarkIcon
                                class="absolute top-2 right-2 transform w-5 h-5 text-red-600 dark:text-red-400 cursor-pointer hover:text-red-500 dark:hover:text-red-300"
                                @click="editingSignature = false" v-if="serviceOrder.signature_base64" />
                        </div>
                    </div>
                    <div class="w-full md:w-1/2 pl-0 md:pl-3 mt-4 md:mt-0">
                    </div>
                </div>
                <CustomFieldsComponent v-if="customFields.length" model-type="service_order" :model-id="serviceOrder.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
            </BoxComponent>
            <button class="w-full p-4 rounded-md bg-green-600 text-white mt-3 hover:bg-green-700" @click="closeViaStage"
                v-if="closedStageId !== null && !serviceOrder.is_closed && hasPermission('serviceorder.close')">Werkbon
                afsluiten</button>
            <button class="w-full p-4 rounded-md bg-blue-500 text-white mt-3" @click="reopenViaStage"
                v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')">Werkbon
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
                    <div
                        class="flex justify-between py-1 border-b border-gray-100 dark:border-slate-800/60 items-center">
                        <span class="text-gray-500 dark:text-slate-400">Externe ref.</span>
                        <div v-if="hasPermission('serviceorder.update') && !serviceOrder.sent_to_administration"
                            class=" w-1/2 text-right">
                            <EditableTextField v-model="form.external_purchaseorder_no"
                                @update="val => { form.external_purchaseorder_no = val; }"
                                placeholder="Externe referentie" />
                        </div>
                        <span v-else class="text-gray-800 dark:text-slate-200">{{
                            serviceOrder.external_purchaseorder_no || '—' }}</span>
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
                        <span class="text-gray-500 dark:text-slate-400">Verzending</span>
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
                <div
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 p-4 text-sm">
                    <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Start- en eindtijd</h3>
                    <div
                        class="flex justify-between py-1 border-b border-gray-100 dark:border-slate-800/60 items-center">
                        <span class="text-gray-500 dark:text-slate-400">Starttijd</span>
                        <div v-if="hasPermission('serviceorder.update')" class="w-1/2 text-right">
                            <EditableTextField inputType="time" v-model="form.actual_start_time"
                                @update="val => { form.actual_start_time = val; }" class="text-right" />
                        </div>
                        <span v-else class="text-gray-800 dark:text-slate-200">{{
                            (serviceOrder.actual_start_time || '').substring(0, 5) || '—' }}</span>
                    </div>
                    <div
                        class="flex justify-between py-1 border-b border-gray-100 dark:border-slate-800/60 items-center">
                        <span class="text-gray-500 dark:text-slate-400">Eindtijd</span>
                        <div v-if="hasPermission('serviceorder.update')" class="w-1/2 text-right">
                            <EditableTextField inputType="time" v-model="form.actual_end_time"
                                @update="val => { form.actual_end_time = val; }" class="text-right" />
                        </div>
                        <span v-else class="text-gray-800 dark:text-slate-200">{{
                            (serviceOrder.actual_end_time || '').substring(0, 5) || '—' }}</span>
                    </div>
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
                        Exporteer PDF voorbeeld
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
                    <button
                        v-if="!serviceOrder.sent_to_administration && hasPermission('snelstart.send_serviceorder') && snelStartEnabled"
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
import ServiceJobsTable from '@/Components/ServiceJobs/ServiceJobsTable.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import { mapsLinkFromCustomer, nlDate, hasPermission, hasAnyPermission, serviceOrderPillText, serviceOrderPillColorClasses } from '@/Utilities/Utilities';
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
import { DocumentTextIcon, PencilSquareIcon, XMarkIcon, CalendarDaysIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline';
import MaterialsWidget from '@/Components/Materials/MaterialsWidget.vue';
import { ChevronDownIcon } from '@heroicons/vue/20/solid';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import SignaturePad from '@/Components/UI/SignaturePad.vue';
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import OpenStreetMapWidget from '@/Components/OpenStreetMapWidget.vue';
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue';
import AssetSelectMenu from '@/Components/UI/AssetSelectMenu.vue';
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue';
import TitleValueIconComponent from '@/Components/UI/TitleValueIconComponent.vue';
import { BadgeCheck, ChevronRightIcon, TimelineIcon } from '@lucide/vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue';
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue';
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue';
import ChapterContents from '@/Components/Chapters/ChapterContents.vue';

const props = defineProps({
    serviceOrder: {
        type: Object,
        required: true
    },
    allMaterials: {
        type: Array,
        required: true
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    stages: { type: Array, default: () => [] },
    closedStageId: { type: [Number, null], default: null },
    customers: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const chapterHeaders = ref(['Details', 'Planning', 'Exporteren'])

const editingSignature = ref(props.serviceOrder.signature_base64 === null);


const internalCustomers = computed(() =>
    props.customers.map(c => ({ id: c.id, name: c.name }))
);

const internalProjects = computed(() =>
    props.projects.map(p => ({ id: p.id, name: p.title }))
);

const selectedProject = computed(() =>
    props.projects.find(p => p.id === form.project_id) ?? props.serviceOrder.project
);

const selectedCustomer = computed(() =>
    props.customers.find(c => c.id === form.customer_id) ?? props.serviceOrder.customer
);

const internalAssets = props.serviceOrder.customer.assets.slice().sort((a, b) =>
    a.product.product_type.name.localeCompare(b.product.product_type.name)
).map((asset) => {
    return {
        id: asset.id,
        name: `${asset.product.product_type.name}: ${asset.product.brand.name} ${asset.product.model} (${asset.serial_number}), ${asset.status}. Verloopt op ${nlDate(asset.next_service_date)}`,
        image_url: asset.product.images.length > 0 ? `/storage/${asset.product.images[0]?.path}` : null,
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

const customerAssets = computed(() =>
    props.serviceOrder.customer.assets.map(asset => {
        const jobs = asset.servicejobs ?? []
        const completed = jobs.map(j => j.completed_on).filter(Boolean).sort()
        return {
            id: asset.id,
            name: `${asset.product.brand.name} ${asset.product.model}`,
            category: asset.product.product_type.name,
            article_number: asset.product.part_no,
            serial_number: asset.serial_number,
            is_bundle: !!asset.product.bundle,
            next_service_date: asset.next_service_date ? nlDate(asset.next_service_date) : null,
            last_service_date: completed.length ? nlDate(completed[completed.length - 1]) : null,
            total_servicejobs: jobs.length,
            thumbnail_url: asset.product.images.length > 0 ? `/storage/${asset.product.images[0]?.path}` : null,
        }
    })
);
const selectedAsset = ref(customerAssets.value[0] ?? null);

const assetToCheck = ref(internalAssets[0]?.id || null);
const ticketToSolve = ref(internalTickets.value[0]?.id || null);

const form = useForm({
    ...props.serviceOrder,
    actual_start_time: props.serviceOrder.actual_start_time ? props.serviceOrder.actual_start_time.substring(0, 5) : null,
    actual_end_time: props.serviceOrder.actual_end_time ? props.serviceOrder.actual_end_time.substring(0, 5) : null,
});

function closeViaStage() {
    if (!canClose.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        return
    }
    onStageChange(props.closedStageId)
}

function reopenViaStage() {
    onStageChange(null)
}

function onStageChange(stage_id) {
    if (!hasPermission('serviceorderstage.update')) {
        usePage().props.flash.error = 'Je hebt geen toestemming om de werkbon status te wijzigen.';
        return;
    }
    const form = useForm({
        customer_id: props.serviceOrder.customer.id,
        service_order_stage_id: stage_id,
    })
    form.patch(`/serviceorders/${props.serviceOrder.id}`, { preserveScroll: true })
}

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

const addServiceJobFromSelectedAsset = () => {
    if (!selectedAsset.value) return;
    newServicejobForm.asset_id = selectedAsset.value.id;
    newServicejobForm.post(`/servicejobs`, { preserveScroll: true });
};

const isReverting = ref(false);

const typeOptions = [
    { value: 'installation', title: 'Installatie' },
    { value: 'service', title: 'Service' },
    { value: 'mixed', title: 'Gemengd' },
]

watch(
    [
        () => form.description,
        () => form.signed_by,
        () => form.signature_base64,
        () => form.external_purchaseorder_no,
        () => form.actual_start_time,
        () => form.actual_end_time,
        () => form.customer_id,
        () => form.project_id,
        () => form.type,
    ],
    () => {
        if (isReverting.value) {
            isReverting.value = false;
            return;
        }
        form.put(`/serviceorders/${props.serviceOrder.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingSignature.value = false;
                form.defaults();
            },
            onError: () => {
                isReverting.value = true;
                form.reset();
                usePage().props.flash.error = usePage().props.errors;
            }
        });
    }
)

const attachTicket = () => {
    if (!hasPermission('ticket.add_to_serviceorder')) return;
    if (!ticketToSolve.value) return;
    form.post(`/serviceorders/ ${props.serviceOrder.id} /tickets/${ticketToSolve.value} `, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            internalTickets.value = internalTickets.value.filter(ticket => ticket.id !== ticketToSolve.value);
        }
    });
};

const emailPdf = () => {
    if (emailing.value) return;
    if (!props.serviceOrder.is_closed) {
        alert('Sluit de werkbon af voordat je de PDF kunt e-mailen.');
        return;
    }
    emailing.value = true;
    form.post(`/serviceorders/ ${props.serviceOrder.id}/email-pdf`, {
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
    if (!props.serviceOrder.is_closed) {
        alert('Sluit de werkbon af voordat je de PDF met keuringen kunt e-mailen.');
        return;
    }
    emailingCombined.value = true;
    form.post(`/serviceorders/${props.serviceOrder.id}/email-pdf-with-jobs`, {
        preserveScroll: true,
        onFinish: () => { emailingCombined.value = false; }
    });
};

const sendForm = useForm({});
const sendToSnelStart = () => {
    if (props.serviceOrder.sent_to_administration) {
        return;
    }
    if (!props.serviceOrder.is_closed) {
        alert('Sluit de werkbon af voordat je kunt versturen naar SnelStart.');
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

// Build mixed timeline: activities + linked events
const timelineItems = computed(() => {
    const acts = (props.serviceOrder.activities || []).map(a => ({
        id: `act-${a.id}`,
        category: a.category || 'other',
        description: a.description,
        created_at: a.created_at,
        executing_users: a.user ? [a.user] : [],
    }));
    const evts = (props.serviceOrder.events || []).map(e => ({
        id: `evt-${e.id}`,
        category: 'event',
        rendered: `${e.event_type?.name || 'Afspraak'}${e.name ? ': ' + e.name : ''}`,
        description: e.description || '',
        created_at: e.start || e.created_at,
        color: e.event_type?.color || null,
        executing_users: e.executing_users || [],
        status: e.status || null,
    }));
    return acts.concat(evts).sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
});

</script>