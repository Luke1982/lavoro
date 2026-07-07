<template>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
        <div class="inline sm:flex items-center">
            <Link href="/serviceorders" class="text-slate-400 text-sm font-medium inline">Werkbonnen</Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2 inline" />
            <span class="text-slate-800 font-bold text-sm inline">Werkbon #{{ serviceOrder.id }} van {{
                nlDate(serviceOrder.created_at) }}
                voor {{ serviceOrder.customer.name }}</span>
        </div>
        <div class="flex gap-4">
            <div v-if="hasPermission('serviceorder.change_type') && !serviceOrder.is_closed" class="w-full sm:w-auto">
                <span class="text-xs text-slate-500 font-medium mt-2 block sm:hidden pb-2">Type werkbon</span>
                <SelectMenuComponent v-model="form.type" :options="typeOptions" label="Type" class="w-full sm:w-auto" />
            </div>
            <button v-if="hasPermission('serviceorder.delete') && !serviceOrder.sent_to_administration"
                @click="deleteServiceOrder"
                class="px-3 py-1.5 text-sm font-medium bg-white text-red-600 ring-gray-200 ring-1 rounded-full cursor-pointer">
                <TrashIcon class="size-5" />
            </button>
        </div>
    </div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center my-4 gap-2">
        <h1 class="text-2xl font-bold">
            Werkbon #{{ serviceOrder.id }}
        </h1>
        <BadgeComponent color="blue" :hasDot="false" v-if="serviceOrder.service_order_stage">
            {{ serviceOrder.service_order_stage.name }}
        </BadgeComponent>
        <BadgeComponent color="orange" v-if="usersMissingTimes.length"
            :tooltip="'Deze uitvoerders hebben nog geen tijden ingevuld'">
            Tijden ontbreken: {{ usersMissingTimes.join(', ') }}
        </BadgeComponent>
    </div>
    <ChaptersComponent>
        <template v-if="chapterHeaders.length > 1">
            <span class="text-xs text-slate-500 font-medium mt-2 block sm:hidden pb-2">Sectie</span>
            <ChapterHeaders>
                <ChapterHeader v-for="(header, index) in chapterHeaders" :key="index" :index="index">
                    {{ header }}
                </ChapterHeader>
            </ChapterHeaders>
        </template>
        <ChapterContents>
            <template #chapter-0>
                <TwoThirdsOneThird>
                    <template #main>
                        <BoxComponent class="mb-4 hidden sm:block">
                            <div v-if="stages.length > 1" class="mb-4"
                                :class="{ 'pointer-events-none opacity-60': serviceOrder.is_closed && !hasPermission('serviceorder.reopen') }">
                                <StepsProgressBar :steps="stages" :model-value="serviceOrder.service_order_stage_id"
                                    @update:modelValue="onStageChange" />
                            </div>
                        </BoxComponent>
                        <div v-if="stages.length > 1" class="mb-4 block sm:hidden"
                            :class="{ 'pointer-events-none opacity-60': serviceOrder.is_closed && !hasPermission('serviceorder.reopen') }">
                            <span class="text-xs text-slate-500 font-medium mt-2 block sm:hidden pb-2">Fase</span>
                            <StepsProgressBar :steps="stages" :model-value="serviceOrder.service_order_stage_id"
                                @update:modelValue="onStageChange" />
                        </div>
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
                                    <EditableTextField
                                        :disabled="serviceOrder.is_closed || !hasPermission('serviceorder.update')"
                                        type="combobox" label="Klant" v-model="form.customer_id"
                                        :options="internalCustomers">
                                        <template #display>
                                            <component :is="hasPermission('customer.read') ? Link : 'span'"
                                                :href="`/customers/${selectedCustomer.id}`" :class="{
                                                    'underline text-lavoro-blue': hasPermission('customer.read')
                                                }">{{ selectedCustomer.name }}</component>
                                        </template>
                                    </EditableTextField>
                                    <EditableTextField
                                        :disabled="serviceOrder.is_closed || !hasPermission('serviceorder.update')"
                                        type="combobox" label="Project" v-model="form.project_id"
                                        :options="internalProjects">
                                        <template #display>
                                            <component v-if="selectedProject"
                                                :is="hasPermission('project.read') ? Link : 'span'"
                                                :href="`/projects/${selectedProject.id}`" :class="{
                                                    'underline text-lavoro-blue': hasPermission('project.read')
                                                }">{{ selectedProject.title }}</component>
                                            <span v-else class="text-gray-400">—</span>
                                        </template>
                                    </EditableTextField>
                                    <EditableTextField :disabled="!hasPermission('serviceorder.update')"
                                        label="Extern factuurnummer" v-model="form.external_invoice_no"
                                        @update="val => { form.external_invoice_no = val; }"
                                        placeholder="Extern factuurnummer" />
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
                                    <EditableTextField
                                        :disabled="serviceOrder.is_closed || !hasPermission('serviceorder.update')"
                                        label="Externe referentie" v-model="form.external_purchaseorder_no"
                                        @update="val => { form.external_purchaseorder_no = val; }"
                                        placeholder="Externe referentie" />
                                    <EditableTextField
                                        :disabled="serviceOrder.is_closed || !hasPermission('serviceorder.update')"
                                        label="Uitvoeringslocatie" v-model="form.execution_location"
                                        @update="val => { form.execution_location = val; }"
                                        placeholder="Uitvoeringslocatie" />
                                </div>
                            </div>
                        </BoxComponent>
                        <TaskInstancesWidget v-if="hasPermission('serviceordertaskinstance.read')"
                            :service-order-id="serviceOrder.id" :instances="serviceOrder.task_instances"
                            :available-tasks="availableTasks" :products="products" :user-roles="userRoles"
                            :is-closed="serviceOrder.is_closed" class="my-4" />
                        <BoxComponent v-if="hasPermission('servicejob.read')" class="my-4">
                            <div class="flex items-start sm:items-center gap-x-3 mb-3 justify-between">
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
                                    :class="['px-3 sm:px-4 py-0.5 sm:py-2 rounded text-2xl sm:text-sm font-light sm:font-semibold text-white transition-opacity', selectedAsset ? 'bg-lavoro-blue hover:opacity-90 cursor-pointer' : 'bg-lavoro-blue opacity-40 cursor-not-allowed']">
                                    + <span class="hidden sm:inline">Keuring toevoegen</span>
                                </button>
                            </div>
                            <div v-if="hasPermission('servicejob.create')"
                                class="flex flex-col sm:flex-row justify-between divide-gray-200/70 divide-x-1 ring-1 ring-gray-200/70 rounded-lavoro-sm">
                                <AssetSelectMenu v-model="selectedAsset" :assets="customerAssets"
                                    class="p-1 sm:p-4 sm:w-1/4 w-full sm:min-w-100" />
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Datum ingebruikname"
                                    :value="selectedAsset?.date_in_service ?? '—'"
                                    class="p-4 w-full sm:w-1/4 justify-center" />
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Volgende keuring"
                                    :value="selectedAsset?.next_service_date ?? '—'"
                                    class="p-4 w-full sm:w-1/4 justify-center" />
                                <TitleValueIconComponent :icon="ClipboardDocumentListIcon" title="Totaal keuringen"
                                    :value="selectedAsset ? String(selectedAsset.total_servicejobs) : '—'"
                                    class="p-4 w-full sm:w-1/4 justify-center" />
                            </div>
                            <ServiceJobsTable :servicejobs="serviceOrder.servicejobs" class="mt-4" />
                        </BoxComponent>
                        <BoxComponent class="my-4"
                            v-if="serviceOrder.tickets.length > 0 || hasPermission('ticket.add_to_serviceorder')">
                            <div class="flex items-start sm:items-center gap-x-3 mb-4 justify-between">
                                <div class="flex gap-x-3">
                                    <div
                                        class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                                        <ExclamationTriangleIcon class="h-5 w-5 text-white" />
                                    </div>
                                    <div class="flex flex-col">
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Storingen
                                        </h3>
                                        <div class="text-slate-400 text-xs">Koppel een bestaande storing aan dit werk of
                                            voeg een nieuwe toe.
                                        </div>
                                    </div>
                                </div>
                                <button v-if="hasPermission('ticket.create') && !serviceOrder.is_closed"
                                    @click="showNewTicketDrawer = true"
                                    class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-0.5 sm:py-2 rounded text-2xl sm:text-sm font-light sm:font-semibold text-white bg-lavoro-blue hover:opacity-90 transition-opacity cursor-pointer">
                                    + <span class="hidden sm:inline">Nieuwe storing aanmaken</span>
                                </button>
                            </div>

                            <div v-if="hasPermission('ticket.add_to_serviceorder') && !serviceOrder.is_closed"
                                class="mb-5">
                                <label
                                    class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">
                                    Kies een bestaande storing
                                    <InformationCircleIcon class="w-4 h-4 text-gray-400"
                                        v-tooltip="'Selecteer een bestaande storing om deze aan de werkbon te koppelen'" />
                                </label>
                                <ComboBox :options="internalTickets" v-model="ticketToSolve"
                                    placeholder="Zoek op storing, module, serienummer of omschrijving...">
                                    <template #option="{ option, active }">
                                        <div class="flex items-start gap-3 py-1">
                                            <div
                                                :class="[active ? 'bg-white/20' : ticketPriorityIconBg(option.priority), 'flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center']">
                                                <ExclamationTriangleIcon v-if="option.priority.toLowerCase() === 'hoog'"
                                                    :class="[active ? 'text-white' : ticketPriorityIconColor(option.priority), 'w-5 h-5']" />
                                                <ExclamationCircleIcon
                                                    v-else-if="option.priority.toLowerCase() === 'normaal'"
                                                    :class="[active ? 'text-white' : ticketPriorityIconColor(option.priority), 'w-5 h-5']" />
                                                <InformationCircleIcon v-else
                                                    :class="[active ? 'text-white' : ticketPriorityIconColor(option.priority), 'w-5 h-5']" />
                                            </div>
                                            <div class="flex flex-col sm:flex-row justify-between min-w-0 flex-1">
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-semibold text-sm"
                                                        :class="active ? 'text-white' : 'text-gray-900 dark:text-slate-100'">
                                                        {{ option.subject }}</p>
                                                    <p class="text-xs mt-0.5 sm:truncate"
                                                        :class="active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400'">
                                                        {{ option.product_type }}: {{ option.asset_name }} ({{
                                                            option.serial_number }})
                                                    </p>
                                                    <p class="text-xs mt-0.5 sm:line-clamp-1"
                                                        :class="active ? 'text-indigo-200' : 'text-gray-400 dark:text-slate-500'">
                                                        {{ option.description }}</p>
                                                </div>
                                                <div
                                                    class="flex-shrink-0 text-right flex flex-col items-start sm:items-end gap-1">
                                                    <span class="text-xs whitespace-nowrap"
                                                        :class="active ? 'text-indigo-100' : 'text-gray-400 dark:text-slate-500'">Sinds
                                                        {{ nlDate(option.created_at) }}</span>
                                                    <span
                                                        :class="[active ? 'bg-white/20 text-white' : ticketPriorityBadgeClass(option.priority), 'text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap']">
                                                        Prio {{ option.priority }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </ComboBox>
                            </div>

                            <div v-if="serviceOrder.tickets.length > 0">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="text-sm font-semibold text-gray-700 dark:text-slate-300">Gekoppelde
                                        storingen</span>
                                    <span
                                        class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-xs font-bold">{{
                                            serviceOrder.tickets.length }}</span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4" v-auto-animate>
                                    <TicketCard v-for="ticket in serviceOrder.tickets" :key="ticket.id" :ticket="ticket"
                                        :disconnect="'service_order_id'" :readonly="serviceOrder.is_closed" />
                                </div>
                            </div>

                            <div
                                class="flex flex-col sm:flex-row items-center justify-between mt-4 pt-4 border-t border-gray-200 dark:border-slate-700">
                                <div
                                    class="flex items-start sm:items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
                                    <InformationCircleIcon class="size-6 sm:size-4 text-lavoro-blue" />
                                    {{ serviceOrder.tickets.length }} storingen gekoppeld aan dit werk
                                </div>
                                <div class="flex flex-col sm:flex-row items-center gap-4 mt-2">
                                    <span v-if="ticketsLastUpdate"
                                        class="text-sm text-gray-400 dark:text-slate-500">Laatste update: {{
                                            nlDate(ticketsLastUpdate) }} om {{ nlTime(ticketsLastUpdate) }}</span>
                                    <Link href="/tickets" v-if="hasPermission('ticket.see_all')"
                                        class="inline-flex items-center gap-1.5 text-sm border border-gray-200 dark:border-slate-600 rounded-md px-3 py-1.5 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                        Storingen beheren
                                        <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                                    </Link>
                                </div>
                            </div>
                        </BoxComponent>
                        <BoxComponent
                            v-if="hasAnyPermission(['material.read.serviceorder', 'freeformmaterial.create', 'freeformmaterial.delete', 'freeformmaterial.update', 'freeformmaterial.read'])"
                            class="mb-4 sm:mb-0">
                            <MaterialsWidget :service-order-id="serviceOrder.id" :materials="serviceOrder.materials"
                                :freeform-materials="serviceOrder.freeform_materials" :all-materials="allMaterials"
                                :materials-use-ajax="materialsUseAjax" :categories="materialCategories"
                                :usage-units="materialUsageUnits" :is-closed="serviceOrder.is_closed"
                                :sent-to-administration="serviceOrder.sent_to_administration"
                                :type="serviceOrder.type" />
                        </BoxComponent>
                    </template>
                    <template #sidebar>
                        <BoxComponent padding="p-0" extra-classes="overflow-hidden">
                            <OpenStreetMapWidget :key="serviceOrder.customer_id"
                                :address="`${serviceOrder.customer.address}, ${serviceOrder.customer.postal_code} ${serviceOrder.customer.city}`" />
                        </BoxComponent>
                        <BoxComponent v-if="timelineItems.length" class="mt-6">
                            <div class="flex">
                                <TimelineIcon class="size-6 mr-2 flex-none object-cover" />
                                <h3 class="font-semibold text-base mb-3 dark:text-slate-100">Tijdlijn</h3>
                            </div>
                            <TimelineComponent :activities="timelineItems" />
                        </BoxComponent>
                        <BoxComponent class="mt-6"
                            v-if="!serviceOrder.is_closed || (serviceOrder.is_closed && serviceOrder.remarks.length > 0)">
                            <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
                                :disabled="serviceOrder.is_closed" :remarkable-id="serviceOrder.id"
                                :comments="serviceOrder.remarks" />
                        </BoxComponent>
                        <BoxComponent class="mt-6"
                            v-if="!serviceOrder.is_closed || (serviceOrder.is_closed && serviceOrder.internal_remarks.length > 0)">
                            <div class="flex items-center gap-x-2 mb-4">
                                <span
                                    class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
                            </div>
                            <RemarksComponent :remarkable-type="'App\\Models\\ServiceOrder'"
                                :disabled="serviceOrder.is_closed" :remarkable-id="serviceOrder.id"
                                :comments="serviceOrder.internal_remarks" :internal="true" />
                        </BoxComponent>
                        <DocumentUploadComponent :existing="serviceOrder.documents" :documentable-id="serviceOrder.id"
                            documentable-type="\App\Models\ServiceOrder" class="mt-6" />
                        <BoxComponent class="mt-6">
                            <ImageUploadComponent :existing="serviceOrder.images" :imageable-id="serviceOrder.id"
                                imageable-type="App\Models\ServiceOrder" />
                        </BoxComponent>
                        <BoxComponent class="mt-6">
                            <div class="flex items-center gap-x-2 mb-4">
                                <span
                                    class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded">Intern</span>
                                <span class="text-sm font-semibold text-gray-700 dark:text-slate-200">Interne
                                    foto's</span>
                            </div>
                            <ImageUploadComponent :existing="serviceOrder.internal_images"
                                :imageable-id="serviceOrder.id" imageable-type="App\Models\ServiceOrder"
                                :internal="true" />
                        </BoxComponent>
                        <BoxComponent class="mt-6">
                            <div class="flex items-center mb-3">
                                <div class="flex justify-between w-full flex-wrap md:flex-nowrap">
                                    <div class="flex w-full items-center">
                                        <Signature class="size-6 mr-2 flex-none object-cover" />
                                        <div class="flex flex-col">
                                            <span class="text-md font-bold">Afronding en handtekening</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="py-2">
                                <EditableTextField type="textarea" v-model="form.description"
                                    :readonly="serviceOrder.is_closed || !hasPermission('serviceorder.close')"
                                    @update="val => { form.description = val; }" label="Eventuele opmerkingen" />
                            </div>
                            <div class="py-2">
                                <EditableTextField inputType="time" v-model="form.actual_start_time"
                                    :readonly="serviceOrder.is_closed || !hasPermission('serviceorder.close')"
                                    @update="val => { form.actual_start_time = val; }">
                                    <template #display>
                                        <span class="text-xs">{{
                                            (serviceOrder.actual_start_time || '').substring(0, 5) ||
                                            `${serviceOrder.is_closed ? `Bon gesloten, je kunt geen aankomsttijd
                                            invoeren` : `Klik hier om een aankomsttijd in te voeren`
                                            }`
                                            }}</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="py-2">
                                <EditableTextField inputType="time" v-model="form.actual_end_time"
                                    :readonly="serviceOrder.is_closed || !hasPermission('serviceorder.close')"
                                    @update="val => { form.actual_end_time = val; }">
                                    <template #display>
                                        <span class="text-xs">{{
                                            (serviceOrder.actual_end_time || '').substring(0, 5) ||
                                            `${serviceOrder.is_closed ? `Bon gesloten, je kunt geen vertrektijd
                                            invoeren` : `Klik hier om een vertrektijd in te voeren`
                                            }`
                                            }}</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="py-2">
                                <span class="block text-xs font-semibold text-gray-500 dark:text-slate-400 mb-1">Naam</span>
                                <span class="text-sm dark:text-slate-200">{{ serviceOrder.signed_by || 'Nog niet ondertekend' }}</span>
                            </div>
                            <div v-if="isSigned" class="py-2">
                                <img :src="serviceOrder.signature_base64" alt="Handtekening" class="max-h-24">
                            </div>
                            <button v-if="hasPermission('serviceorder.close') || serviceOrder.is_closed"
                                @click="showCloseModal = true"
                                class="mt-4 w-full p-3 rounded-md ring-1 ring-gray-200 dark:ring-slate-600 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                <Signature class="w-5 h-5" />
                                {{ isSigned ? 'Bekijk overzicht' : 'Bekijk & onderteken' }}
                            </button>
                            <button
                                v-if="closedStageId !== null && isSigned && !serviceOrder.is_closed && hasPermission('serviceorder.close')"
                                @click="closeViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-green-600 text-white hover:bg-green-700 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                <Check class="w-5 h-5" />
                                Werkbon afsluiten
                            </button>
                            <button v-else-if="serviceOrder.is_closed && hasPermission('serviceorder.reopen')"
                                @click="reopenViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-blue-500 text-white hover:bg-blue-600 cursor-pointer font-semibold text-sm">
                                Werkbon heropenen
                            </button>
                            <button
                                v-if="incompleteStageId !== null && !serviceOrder.is_closed && serviceOrder.service_order_stage_id !== incompleteStageId && hasPermission('serviceorder.mark_partially_complete')"
                                @click="markIncompleteViaStage"
                                class="mt-4 w-full p-3 rounded-md bg-amber-500 text-white hover:bg-amber-600 cursor-pointer font-semibold text-sm flex items-center justify-center gap-2">
                                Werkbon als onvolledig markeren
                            </button>
                        </BoxComponent>
                    </template>
                </TwoThirdsOneThird>
            </template>
            <template v-if="canSeeFinancials" #chapter-1>
                <TwoThirdsOneThird>
                    <template #main>
                        <div v-if="serviceOrder.sent_to_administration"
                            class="mb-4 p-3 rounded border border-amber-400 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700 text-amber-800 dark:text-amber-300 text-sm font-semibold">
                            Deze order is naar de administratie verzonden. Materialen kunnen niet meer worden aangepast.
                        </div>
                        <BoxComponent>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Materialen</h3>
                            <MaterialsFinancialOverview :materials="serviceOrder.materials"
                                :freeform-materials="serviceOrder.freeform_materials" />
                        </BoxComponent>
                        <BoxComponent class="mt-4">
                            <EditableTextField type="textarea" :disabled="!hasPermission('serviceorder.update')"
                                label="Financieel commentaar" v-model="form.financial_comments"
                                @update="val => { form.financial_comments = val; }"
                                placeholder="Financieel commentaar" />
                        </BoxComponent>
                    </template>
                    <template #sidebar>
                        <BoxComponent v-if="snelStartEnabled && hasPermission('snelstart.send_serviceorder')"
                            class="mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm text-gray-500 dark:text-slate-400">Status verzending</span>
                                <span class="px-2 py-0.5 text-xs rounded border"
                                    :class="serviceOrderPillColorClasses(serviceOrder)">
                                    {{ serviceOrderPillText(serviceOrder) }}
                                </span>
                            </div>
                            <button v-if="!serviceOrder.sent_to_administration" @click="sendToSnelStart"
                                class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm font-semibold">
                                Verstuur naar SnelStart
                            </button>
                            <span v-else
                                class="w-full inline-flex items-center justify-center px-4 py-2 text-sm rounded bg-green-100 text-green-700 border border-green-300">
                                Verzonden naar administratie
                            </span>
                        </BoxComponent>
                        <BoxComponent v-if="canSeeFinancials">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100">Financieel</h3>
                                <button type="button" @click="showFinancial = !showFinancial"
                                    class="p-1.5 rounded text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"
                                    v-tooltip="showFinancial ? 'Verberg prijzen' : 'Toon prijzen'">
                                    <span class="text-xl leading-none select-none">$</span>
                                </button>
                            </div>
                            <div v-if="showFinancialUi" class="text-sm">
                                <div class="flex justify-between py-1">
                                    <span class="text-gray-500 dark:text-slate-400">Subtotaal</span>
                                    <span class="dark:text-slate-200">€ {{ materialsSubtotal.toFixed(2) }}</span>
                                </div>
                                <div class="flex justify-between py-1">
                                    <span class="text-gray-500 dark:text-slate-400">BTW (21%)</span>
                                    <span class="dark:text-slate-200">€ {{ materialsVat.toFixed(2) }}</span>
                                </div>
                                <div
                                    class="flex justify-between py-2 border-t border-gray-200 dark:border-slate-700 mt-2 font-semibold">
                                    <span class="dark:text-slate-100">Totaal</span>
                                    <span class="dark:text-slate-100">€ {{ materialsTotal.toFixed(2) }}</span>
                                </div>
                            </div>
                        </BoxComponent>
                    </template>
                </TwoThirdsOneThird>
            </template>

            <template v-if="canExport" v-slot:[exportSlot]>
                <OneThirdTwoThirds
                    v-if="hasAnyPermission(['serviceorder.export_pdf', 'serviceorder.email_pdf', 'servicejob.export_pdf', 'servicejob.mail_pdf'])"
                    class="mt-5 sm:mt-0">
                    <template #narrow>
                        <BoxComponent>
                            <div class="flex flex-col gap-1.5">
                                <button v-if="hasAnyPermission(['serviceorder.export_pdf', 'serviceorder.email_pdf'])"
                                    @click="selectExportItem('werkbon')"
                                    :class="['text-left px-3 py-2 rounded-md text-sm font-medium border transition-colors w-full',
                                        selectedExportItem?.type === 'werkbon'
                                            ? 'bg-indigo-50 border-indigo-400 text-indigo-700 dark:bg-indigo-900/30 dark:border-indigo-500 dark:text-indigo-300'
                                            : 'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700']">
                                    <div class="flex items-center justify-between gap-2">
                                        <span>Werkbon</span>
                                        <span v-if="serviceOrder.sent_to_customer"
                                            class="text-xs bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-1.5 py-0.5 rounded shrink-0">
                                            Verzonden
                                        </span>
                                    </div>
                                </button>

                                <template v-if="hasAnyPermission(['servicejob.export_pdf', 'servicejob.mail_pdf'])">
                                    <button v-for="job in serviceOrder.servicejobs" :key="job.id"
                                        @click="selectExportItem('job', job.id)"
                                        :class="['text-left px-3 py-2 rounded-md text-sm border transition-colors w-full',
                                            selectedExportItem?.type === 'job' && selectedExportItem?.id === job.id
                                                ? 'bg-indigo-50 border-indigo-400 text-indigo-700 dark:bg-indigo-900/30 dark:border-indigo-500 dark:text-indigo-300'
                                                : 'border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700']">
                                        <div class="flex items-start justify-between gap-2">
                                            <div>
                                                <div class="font-medium leading-tight">{{
                                                    job.asset?.product?.brand?.name }} {{ job.asset?.product?.model }}
                                                </div>
                                                <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{
                                                    job.asset?.serial_number }}</div>
                                            </div>
                                            <span v-if="job.sent_to_customer"
                                                class="text-xs bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-1.5 py-0.5 rounded shrink-0 mt-0.5">
                                                Verzonden
                                            </span>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </BoxComponent>
                    </template>
                    <template #wide>
                        <BoxComponent>
                            <template v-if="selectedExportItem">
                                <div
                                    class="mb-3 rounded-md overflow-hidden border border-gray-200 dark:border-slate-600">
                                    <iframe :src="selectedExportItem.type === 'werkbon'
                                        ? `/serviceorders/${serviceOrder.id}/export/pdf`
                                        : `/servicejobs/${selectedExportItem.id}/export/pdf`" class="w-full h-[600px]"
                                        frameborder="0" />
                                </div>
                                <div class="flex gap-2">
                                    <a :href="selectedExportItem.type === 'werkbon'
                                        ? `/serviceorders/${serviceOrder.id}/export/pdf`
                                        : `/servicejobs/${selectedExportItem.id}/export/pdf`" target="_blank"
                                        rel="noopener"
                                        class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-[#FF0000] text-white rounded text-sm font-semibold hover:opacity-90">
                                        <span
                                            class="bg-white text-[#FF0000] font-bold text-[10px] leading-none px-1 py-0.5 rounded mr-2">PDF</span>
                                        Genereer
                                    </a>
                                    <button @click="emailSelectedPdf" :disabled="exportEmailing"
                                        class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed">
                                        {{ exportEmailing ? 'Versturen...' : 'E-mail PDF' }}
                                    </button>
                                </div>
                            </template>
                            <p v-else class="text-sm text-gray-400 dark:text-slate-500">
                                Selecteer een document aan de linkerkant om een voorbeeld te bekijken.
                            </p>
                        </BoxComponent>
                    </template>
                </OneThirdTwoThirds>
            </template>
        </ChapterContents>
    </ChaptersComponent>

    <DrawerComponent v-model="showNewTicketDrawer" title="Nieuwe storing aanmaken"
        subtitle="Maak een nieuwe storing aan en koppel deze direct aan de werkbon." max-width-class="max-w-lg">
        <div class="px-4 sm:px-6 py-6 space-y-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Apparaat</label>
                <AssetSelectMenu :assets="customerAssets" v-model="newTicketAsset" placeholder="Kies een apparaat..."
                    :needs-box="true" />
                <p v-if="newTicketForm.errors.asset_id" class="mt-1 text-sm text-red-600">{{
                    newTicketForm.errors.asset_id
                    }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Onderwerp</label>
                <input v-model="newTicketForm.subject" type="text" placeholder="Omschrijf het probleem kort..."
                    :class="['w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset sm:text-sm sm:leading-6 bg-white dark:bg-slate-900', newTicketForm.errors.subject ? 'ring-red-300 focus:ring-red-500' : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600', 'focus:ring-2 focus:ring-inset focus:outline-none']" />
                <p v-if="newTicketForm.errors.subject" class="mt-1 text-sm text-red-600">{{ newTicketForm.errors.subject
                    }}
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Prioriteit</label>
                <ComboBox :options="ticketPriorities" v-model="newTicketForm.priority" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1.5">Omschrijving</label>
                <textarea v-model="newTicketForm.description" rows="4" placeholder="Beschrijf het probleem in detail..."
                    :class="['w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset sm:text-sm sm:leading-6 bg-white dark:bg-slate-900', newTicketForm.errors.description ? 'ring-red-300 focus:ring-red-500' : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600', 'focus:ring-2 focus:ring-inset focus:outline-none']"></textarea>
                <p v-if="newTicketForm.errors.description" class="mt-1 text-sm text-red-600">{{
                    newTicketForm.errors.description }}</p>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button @click="showNewTicketDrawer = false"
                    class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer">Annuleren</button>
                <button @click="createAndAttachTicket" :disabled="newTicketForm.processing || attachForm.processing"
                    class="px-4 py-2 text-sm font-semibold text-white bg-lavoro-blue hover:opacity-90 rounded-md disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed">
                    {{ newTicketForm.processing || attachForm.processing ? 'Opslaan...' : 'Opslaan' }}
                </button>
            </div>
        </template>
    </DrawerComponent>

    <CloseServiceOrderModal v-model:open="showCloseModal" :service-order="serviceOrder" :user-roles="userRoles"
        @confirm="handleSignatureConfirm" />
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import OneThirdTwoThirds from '@/Layouts/OneThirdTwoThirds.vue';
import ServiceJobsTable from '@/Components/ServiceJobs/ServiceJobsTable.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import { mapsLinkFromCustomer, nlDate, nlTime, hasPermission, hasAnyPermission, serviceOrderPillText, serviceOrderPillColorClasses } from '@/Utilities/Utilities';
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue';
import { DocumentTextIcon, CalendarDaysIcon, ClipboardDocumentListIcon, ExclamationTriangleIcon, ExclamationCircleIcon, InformationCircleIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline';
import { Check, TrashIcon } from '@lucide/vue';
import MaterialsWidget from '@/Components/Materials/MaterialsWidget.vue';
import MaterialsFinancialOverview from '@/Components/Materials/MaterialsFinancialOverview.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue';
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import OpenStreetMapWidget from '@/Components/OpenStreetMapWidget.vue';
import TaskInstancesWidget from '@/Components/ServiceOrders/TaskInstancesWidget.vue';
import CloseServiceOrderModal from '@/Components/ServiceOrders/CloseServiceOrderModal.vue';
import AssetSelectMenu from '@/Components/UI/AssetSelectMenu.vue';
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue';
import { ticketPriorities } from '@/Components/data/TicketData';
import TitleValueIconComponent from '@/Components/UI/TitleValueIconComponent.vue';
import { BadgeCheck, ChevronRightIcon, Signature, TimelineIcon } from '@lucide/vue';
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
    materialsUseAjax: { type: Boolean, default: false },
    materialCategories: { type: Array, default: () => [] },
    materialUsageUnits: { type: Array, default: () => [] },
    customFields: {
        type: Array,
        default: () => [],
    },
    stages: { type: Array, default: () => [] },
    closedStageId: { type: [Number, null], default: null },
    incompleteStageId: { type: [Number, null], default: null },
    customers: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    snelStartEnabled: { type: Boolean, default: false },
    usersMissingTimes: { type: Array, default: () => [] },
});


const showCloseModal = ref(false);


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
                    subject: ticket.subject,
                    description: ticket.description,
                    priority: ticket.priority,
                    created_at: ticket.created_at,
                    product_type: ticket.asset.product.product_type.name,
                    asset_name: `${ticket.asset.product.brand.name} ${ticket.asset.product.model}`,
                    serial_number: ticket.asset.serial_number,
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
            date_in_service: asset.date_in_service ? nlDate(asset.date_in_service) : null,
            next_service_date: asset.next_service_date ? nlDate(asset.next_service_date) : null,
            last_service_date: completed.length ? nlDate(completed[completed.length - 1]) : null,
            total_servicejobs: jobs.length,
            thumbnail_url: asset.product.images.length > 0 ? `/storage/${asset.product.images[0]?.path}` : null,
        }
    })
);
const selectedAsset = ref(customerAssets.value[0] ?? null);

const ticketsLastUpdate = computed(() => {
    if (!props.serviceOrder.tickets.length) return null;
    return props.serviceOrder.tickets.reduce((max, t) =>
        t.updated_at > max ? t.updated_at : max,
        props.serviceOrder.tickets[0].updated_at
    );
});

const ticketPriorityIconBg = (priority) => {
    const p = priority.toLowerCase();
    if (p === 'hoog') return 'bg-red-100';
    if (p === 'normaal') return 'bg-amber-100';
    return 'bg-green-100';
};
const ticketPriorityIconColor = (priority) => {
    const p = priority.toLowerCase();
    if (p === 'hoog') return 'text-red-600';
    if (p === 'normaal') return 'text-amber-600';
    return 'text-green-600';
};
const ticketPriorityBadgeClass = (priority) => {
    const p = priority.toLowerCase();
    if (p === 'hoog') return 'bg-red-100 text-red-700';
    if (p === 'normaal') return 'bg-amber-100 text-amber-700';
    return 'bg-green-100 text-green-700';
};

const assetToCheck = ref(internalAssets[0]?.id || null);
const ticketToSolve = ref(null);

const form = useForm({
    ...props.serviceOrder,
    actual_start_time: props.serviceOrder.actual_start_time ? props.serviceOrder.actual_start_time.substring(0, 5) : null,
    actual_end_time: props.serviceOrder.actual_end_time ? props.serviceOrder.actual_end_time.substring(0, 5) : null,
});

function closeViaStage() {
    if (!isSigned.value) {
        alert('Vul zowel de naam als de handtekening in om de werkbon te kunnen afsluiten.')
        return
    }
    if (!confirm('Weet je zeker dat je de werkbon wilt sluiten? Je kunt er daarna geen wijzigingen meer in aanbrengen.')) {
        return
    }
    onStageChange(props.closedStageId)
}

function handleSignatureConfirm({ signed_by, signature_base64 }) {
    form.signed_by = signed_by
    form.signature_base64 = signature_base64
    form.put(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults()
            showCloseModal.value = false
        },
        onError: (errors) => {
            form.reset('signed_by', 'signature_base64')
            const msg = errors.signed_by || errors.signature_base64 || Object.values(errors)[0]
            if (msg) usePage().props.flash.error = msg
        },
    })
}

function reopenViaStage() {
    onStageChange(null)
}

function markIncompleteViaStage() {
    if (!confirm('Weet je zeker dat je deze werkbon als onvolledig wilt markeren?')) {
        return
    }
    onStageChange(props.incompleteStageId)
}

function onStageChange(stage_id) {
    if (!hasPermission('serviceorder.update') && !hasPermission('serviceorder.close') && !hasPermission('serviceorder.mark_partially_complete')) {
        usePage().props.flash.error = 'Je hebt geen toestemming om de werkbon status te wijzigen.';
        return;
    }
    const form = useForm({
        customer_id: props.serviceOrder.customer.id,
        service_order_stage_id: stage_id,
    })
    form.patch(`/serviceorders/${props.serviceOrder.id}`, {
        preserveScroll: true,
        onError: (errors) => {
            const msg = errors.service_order_stage_id || Object.values(errors)[0]
            if (msg) usePage().props.flash.error = msg
        },
    })
}

const newServicejobForm = useForm({
    service_order_id: props.serviceOrder.id,
    asset_id: assetToCheck.value,
    outcome: 'Nog geen uitkomst',
});

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
        () => form.external_purchaseorder_no,
        () => form.external_invoice_no,
        () => form.financial_comments,
        () => form.execution_location,
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
    const id = ticketToSolve.value;
    ticketToSolve.value = null;
    form.post(`/serviceorders/${props.serviceOrder.id}/tickets/${id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            internalTickets.value = internalTickets.value.filter(ticket => ticket.id !== id);
        }
    });
};

watch(ticketToSolve, (id) => {
    if (id) attachTicket();
});

const showNewTicketDrawer = ref(false);
const newTicketAsset = ref(null);
const newTicketForm = useForm({
    asset_id: null,
    subject: '',
    description: '',
    priority: 'Normaal',
    status: 'Open',
});
const attachForm = useForm({});

const createAndAttachTicket = () => {
    newTicketForm.asset_id = newTicketAsset.value?.id ?? null;
    newTicketForm.post('/tickets', {
        preserveScroll: true,
        onSuccess: () => {
            const newTicket = usePage().props.flash?.extra?.ticket;
            if (newTicket?.id) {
                attachForm.post(`/serviceorders/${props.serviceOrder.id}/tickets/${newTicket.id}`, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        showNewTicketDrawer.value = false;
                        newTicketForm.reset();
                        newTicketAsset.value = null;
                    },
                });
            } else {
                showNewTicketDrawer.value = false;
                newTicketForm.reset();
            }
        },
    });
};

const selectedExportItem = ref(null);
const exportEmailing = ref(false);
const exportEmailForm = useForm({});

const selectExportItem = (type, id = null) => {
    if (selectedExportItem.value?.type === type && selectedExportItem.value?.id === id) {
        selectedExportItem.value = null;
        return;
    }
    selectedExportItem.value = { type, id };
};

const emailSelectedPdf = () => {
    if (!selectedExportItem.value || exportEmailing.value) return;
    if (selectedExportItem.value.type === 'werkbon') {
        if (!props.serviceOrder.is_closed) {
            alert('Sluit de werkbon af voordat je de PDF kunt e-mailen.');
            return;
        }
        exportEmailing.value = true;
        exportEmailForm.post(`/serviceorders/${props.serviceOrder.id}/email-pdf`, {
            preserveScroll: true,
            onFinish: () => { exportEmailing.value = false; }
        });
    } else {
        exportEmailing.value = true;
        exportEmailForm.post(`/servicejobs/${selectedExportItem.value.id}/email-pdf`, {
            preserveScroll: true,
            onFinish: () => { exportEmailing.value = false; }
        });
    }
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

const deleteServiceOrder = () => {
    if (confirm('Weet je zeker dat je deze werkbon wilt verwijderen? Dit kan niet ongedaan worden gemaakt.')) {
        router.delete(`/serviceorders/${props.serviceOrder.id}`)
    }
}

const materialsSubtotal = computed(() => {
    return props.serviceOrder.materials.reduce((sum, m) => {
        return sum + (Number(m.pivot.quantity) * Number(m.price));
    }, 0);
});
const materialsVat = computed(() => materialsSubtotal.value * 0.21);
const materialsTotal = computed(() => materialsSubtotal.value + materialsVat.value);


const showFinancial = ref(false);
const canSeeFinancials = computed(() => hasPermission('serviceorder.see_financials'));
const canExport = computed(() => hasAnyPermission(['serviceorder.export_pdf', 'serviceorder.email_pdf', 'servicejob.export_pdf', 'servicejob.mail_pdf', 'snelstart.send_serviceorder']));

const chapterHeaders = computed(() => [
    'Details',
    ...(canSeeFinancials.value ? ['Administratie'] : []),
    ...(canExport.value ? ['Exporteren'] : []),
])
const exportSlot = computed(() => `chapter-${canSeeFinancials.value ? 2 : 1}`)

const showFinancialUi = computed(() => canSeeFinancials.value && showFinancial.value);

const isSigned = computed(() => {
    const name = (props.serviceOrder.signed_by ?? '').toString().trim();
    const sig = (props.serviceOrder.signature_base64 ?? '').toString().trim();
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