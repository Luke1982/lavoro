<template>
    <div class="flex items-start justify-between gap-4 mb-6">
        <BreadcrumbComponent :items="breadcrumbItems" />
        <button v-if="canDelete" type="button" @click="deleteAsset" v-tooltip="'Verwijder machine'"
            class="flex-none flex items-center justify-center size-10 aspect-square bg-white text-red-600 ring-1 ring-gray-200 rounded-full cursor-pointer hover:bg-gray-50 dark:bg-slate-800 dark:text-red-400 dark:ring-slate-600 dark:hover:bg-slate-700">
            <TrashIcon class="size-5" />
        </button>
    </div>

    <div class="flex items-start gap-4">
        <div
            class="size-20 sm:size-24 flex-none overflow-hidden rounded-lg bg-white p-2.5 ring-1 ring-gray-900/10 dark:bg-slate-800 dark:ring-slate-600">
            <img v-if="headerImage" :src="headerImage" :alt="assetTitle" class="size-full object-contain" />
            <div v-else class="flex size-full items-center justify-center">
                <PuzzlePieceIcon class="size-10 text-gray-400 dark:text-slate-500" />
            </div>
        </div>
        <div class="min-w-0 flex-grow">
            <h1 class="text-2xl font-bold break-words dark:text-slate-100">{{ assetTitle }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <BadgeComponent :color="statusBadgeColor" :has-dot="false">{{ asset.status }}</BadgeComponent>
                <BadgeComponent v-if="asset.date_in_service" color="blue" :has-dot="false">In gebruik</BadgeComponent>
            </div>
        </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-x-8 gap-y-3 mb-6">
        <TitleValueIconComponent v-for="fact in headerFacts" :key="fact.title" class="w-[calc(50%-1rem)] sm:w-auto"
            :icon="fact.icon" :title="fact.title" :value="fact.value" />
    </div>

    <TwoThirdsOneThird>
        <template #main>
            <div class="mb-5 grid grid-cols-2 xl:grid-cols-4 gap-3">
                <div v-for="card in metricCards" :key="card.label"
                    class="flex items-center gap-3 rounded-lavoro-sm border-lavoro-box shadow-lavoro-box bg-white dark:bg-slate-900 p-4">
                    <div :class="['flex-none flex items-center justify-center size-12 rounded-lavoro-sm', card.iconBg]">
                        <component :is="card.icon" :class="['size-6', card.iconColor]" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-gray-900 dark:text-slate-100 truncate">{{ card.value }}</p>
                        <p class="text-sm text-gray-600 dark:text-slate-300 truncate">{{ card.label }}</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 truncate">{{ card.sub }}</p>
                    </div>
                </div>
            </div>
            <BoxComponent>
                <div class="grid grid-cols-1 md:grid-cols-2">
                    <div class="flex flex-col gap-6 md:pr-8">
                        <EditableTextField type="combobox" v-model="form.product_id" :options="productOptions"
                            :has-external-searching="productsUseAjax" :searching="productSearching"
                            @change="searchProducts" :readonly="!canUpdate" label="Merk en model"
                            :error="form.errors.product_id" @revert="form.clearErrors('product_id')">
                            <template #display>
                                {{ asset.product.brand.name }}
                                <Link class="underline" :href="`/products/${asset.product.id}`">
                                    {{ asset.product.model }}
                                </Link>
                            </template>
                        </EditableTextField>
                        <div>
                            <h3 class="text-xs font-semibold mb-1 text-slate-500">Serienummer</h3>
                            <span v-if="asset.product.bundle" class="text-sm text-gray-500 italic">Bundel</span>
                            <div v-else class="flex items-center gap-2">
                                <EditableTextField v-model="form.serial_number" :readonly="!canUpdate"
                                    class="flex-1 min-w-0" :error="form.errors.serial_number"
                                    @revert="form.clearErrors('serial_number')" />
                                <ScanSerialButton v-if="canUpdate" @picked="form.serial_number = $event" />
                            </div>
                        </div>
                        <EditableTextField v-model="form.date_in_service" inputType="date" :readonly="!canUpdate"
                            label="In gebruikname" :error="form.errors.date_in_service"
                            @revert="form.clearErrors('date_in_service')" />
                        <EditableTextField type="combobox" v-model="form.status" :options="statusOptions"
                            :readonly="!canUpdate" label="Status" :error="form.errors.status"
                            @revert="form.clearErrors('status')">
                            <template #display>
                                <BadgeComponent :color="statusBadgeColor" :has-dot="false">{{ asset.status }}
                                </BadgeComponent>
                            </template>
                        </EditableTextField>
                        <EditableTextField type="combobox" v-model="form.location_id" :options="locationOptions"
                            :readonly="!canUpdate" label="Locatie" indicator="link" :error="form.errors.location_id"
                            @revert="form.clearErrors('location_id')">
                            <template #display>
                                <Link v-if="asset.linked_location" :href="`/locations/${asset.linked_location.id}`"
                                    class="text-blue-600 underline">{{ asset.linked_location.title }}</Link>
                                <span v-else class="text-gray-400 dark:text-slate-500">Geen locatie</span>
                            </template>
                        </EditableTextField>
                    </div>
                    <div class="flex flex-col gap-6 md:pl-8 md:border-l md:border-gray-200/70 mt-6 md:mt-0">
                        <EditableTextField v-model="form.next_service_date" inputType="date" :readonly="!canUpdate"
                            label="Volgende keuring" :error="form.errors.next_service_date"
                            @revert="form.clearErrors('next_service_date')" />
                        <EditableTextField type="combobox" v-model="form.customer_id" :options="customerOptions"
                            :has-external-searching="customersUseAjax" :searching="customerSearching"
                            @change="searchCustomers" :readonly="!canUpdate || isChildAsset" label="Klant"
                            indicator="link" :error="form.errors.customer_id"
                            @revert="form.clearErrors('customer_id')">
                            <template #display>
                                <Link v-if="owningCustomer" :href="`/customers/${owningCustomer.id}`"
                                    class="text-blue-600 underline">{{ owningCustomer.name }}</Link>
                                <span v-else class="text-gray-400">—</span>
                                <span v-if="isChildAsset" class="text-xs text-gray-400 ml-2">
                                    via bovenliggende machine
                                </span>
                            </template>
                        </EditableTextField>
                        <EditableTextField readonly label="Leverancier"
                            :indicator="preferredSupplier ? 'link' : ''">
                            <template #display>
                                <Link v-if="preferredSupplier && canReadSuppliers"
                                    :href="`/suppliers/${preferredSupplier.id}`" class="text-blue-600 underline">
                                    {{ preferredSupplier.name }}
                                </Link>
                                <span v-else-if="preferredSupplier">{{ preferredSupplier.name }}</span>
                                <span v-else class="text-gray-400 dark:text-slate-500 font-normal">—</span>
                            </template>
                        </EditableTextField>
                        <EditableTextField readonly label="Garantie">
                            <template #display>
                                <span v-if="asset.product.warranty">{{ asset.product.warranty }}</span>
                                <span v-else class="text-gray-400 dark:text-slate-500 font-normal">—</span>
                            </template>
                        </EditableTextField>
                        <EditableTextField readonly label="Aangemaakt op">
                            <template #display>{{ nlDate(asset.created_at) }}</template>
                        </EditableTextField>
                    </div>
                </div>
                <CustomFieldsComponent v-if="customFields.length" model-type="asset" :model-id="asset.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
            </BoxComponent>
            <BoxComponent class="mt-5" v-auto-animate>
                <SectionHeader :icon="ExclamationCircleIcon" title="Storingen"
                    subtitle="Openstaande en afgehandelde storingen aan deze machine." chapter="tickets">
                    <template #actions>
                        <button v-if="hasPermission('ticket.create')" @click="showTicketDrawer = true"
                            class="inline-flex items-center gap-1.5 rounded-md bg-lavoro-blue px-2 py-2 sm:px-3 text-sm font-medium text-white cursor-pointer transition-opacity hover:opacity-90">
                            <PlusIcon class="w-5 h-5" />
                            <span class="hidden sm:inline">Nieuwe storing</span>
                        </button>
                    </template>
                </SectionHeader>
                <p v-if="!asset.tickets?.length" class="text-sm text-gray-400 dark:text-slate-500 mt-3">
                    Geen storingen gevonden.
                </p>
                <div v-else class="mt-2 divide-y divide-gray-100 dark:divide-slate-700/60">
                    <Link v-for="ticket in asset.tickets" :key="ticket.id" :href="`/tickets/${ticket.id}`"
                        class="flex items-start sm:items-center gap-3 py-3 px-2 -mx-2 rounded-md transition-colors hover:bg-gray-50 dark:hover:bg-slate-800/40">
                        <span class="mt-1.5 sm:mt-0 size-2.5 rounded-full flex-none" :class="ticketStatusDotClasses(ticket.status)" />
                        <span class="mt-0.5 sm:mt-0 text-sm font-semibold text-gray-800 dark:text-slate-200 flex-none">
                            #{{ ticket.id }}
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="text-sm text-gray-600 dark:text-slate-300 truncate">
                                {{ ticket.subject }}
                            </span>
                            <BadgeComponent class="self-start sm:hidden" :color="ticketPriorityColor(ticket.priority)"
                                :has-dot="false">
                                {{ ticket.priority }}
                            </BadgeComponent>
                        </div>
                        <span class="hidden sm:block w-24 flex-none text-right text-xs text-gray-400 dark:text-slate-500">
                            {{ nlDate(ticket.created_at) }}
                        </span>
                        <div class="hidden flex-none sm:flex sm:w-36 sm:justify-end">
                            <BadgeComponent :color="ticketPriorityColor(ticket.priority)" :has-dot="false">
                                {{ ticket.priority }}
                            </BadgeComponent>
                        </div>
                        <div class="hidden flex-none sm:block sm:w-8">
                            <span v-if="ticket.created_by" v-tooltip="ticket.created_by.name"
                                class="flex items-center justify-center size-8 rounded-full bg-lavoro-blue/10 text-lavoro-blue font-semibold text-xs">
                                {{ initials(ticket.created_by.name) }}
                            </span>
                        </div>
                        <ChevronRightIcon class="mt-1 sm:mt-0 size-4 flex-none text-gray-400 dark:text-slate-500" />
                    </Link>
                </div>
            </BoxComponent>
            <BoxComponent
                v-if="asset.child_assets?.length || asset.parent_asset || (asset.product.productables?.length && hasPermission('assetrelation.create')) || (productHasChildTypes && hasPermission('assetrelation.create'))"
                class="mt-5">
                <SectionHeader :icon="LinkIcon" title="Gerelateerde machines"
                    subtitle="Onderdelen en toebehoren die bij deze machine horen." chapter="assets" />

                <!-- Per-slot view when product has defined related products -->
                <div v-if="asset.product.productables?.length">
                    <p class="text-xs text-gray-400 mb-2">Onderdelen</p>
                    <div v-for="slot in asset.product.productables" :key="slot.id"
                        class="mb-3 border border-gray-100 dark:border-slate-700 rounded-md p-2">
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ slot.child_product.brand.name }} {{ slot.child_product.model }}
                                </span>
                                <span v-if="slot.product_relation" class="text-xs text-gray-400">
                                    ({{ slot.product_relation.name }})
                                </span>
                                <span v-if="slot.is_required"
                                    class="text-xs text-amber-600 bg-amber-50 dark:bg-amber-900/30 rounded px-1">
                                    verplicht
                                </span>
                            </div>
                            <span class="text-xs"
                                :class="childAssetsForSlot(slot.id).length >= slot.quantity ? 'text-gray-400' : 'text-blue-500'">
                                {{ childAssetsForSlot(slot.id).length }} / {{ slot.quantity }}
                            </span>
                        </div>
                        <div v-for="child in childAssetsForSlot(slot.id)" :key="child.id"
                            class="flex items-center justify-between py-1 pl-2 border-b border-gray-100 dark:border-slate-600">
                            <div>
                                <Link :href="`/assets/${child.id}`" class="text-blue-600 underline text-sm">
                                    {{ child.product.brand.name }} {{ child.product.model }}
                                </Link>
                                <span class="text-xs text-gray-400 ml-2">{{ child.product?.bundle ? 'Bundel' :
                                    (child.serial_number ?? '—') }}</span>
                            </div>
                            <button v-if="hasPermission('assetrelation.delete')" @click="detachChild(child.id)"
                                class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                        <div v-if="hasPermission('assetrelation.create') && childAssetsForSlot(slot.id).length < slot.quantity"
                            class="mt-1.5 pl-2">
                            <div v-if="creatingForSlot !== slot.id">
                                <button @click="openNewChildForm(slot.id)"
                                    class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                                    <PlusIcon class="size-3" /> Nieuwe machine aanmaken
                                </button>
                            </div>
                            <div v-else class="flex gap-2 items-start mt-1">
                                <div class="flex-1">
                                    <TextInput v-model="newChildForm.serial_number" label="Serienummer"
                                        :hasError="Boolean(newChildForm.errors.serial_number)"
                                        :errorMessage="newChildForm.errors.serial_number" />
                                </div>
                                <button @click="submitNewChild(slot.id)"
                                    class="mt-8 px-3 py-1.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                    Aanmaken
                                </button>
                                <button @click="cancelNewChild"
                                    class="mt-8 px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                    Annuleren
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Children linked without a productable slot (manual links) -->
                    <div v-if="unslottedChildAssets.length" class="mt-2">
                        <p class="text-xs text-gray-400 mb-1">Overige koppelingen</p>
                        <div v-for="child in unslottedChildAssets" :key="child.id"
                            class="flex items-center justify-between py-1 border-b border-gray-50 dark:border-slate-700">
                            <div>
                                <Link :href="`/assets/${child.id}`" class="text-blue-600 underline text-sm">
                                    {{ child.product.brand.name }} {{ child.product.model }}
                                </Link>
                                <span class="text-xs text-gray-400 ml-2">{{ child.product?.bundle ? 'Bundel' :
                                    child.serial_number }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">{{ child.product_relation?.name ?? '—' }}</span>
                                <button v-if="hasPermission('assetrelation.delete')" @click="detachChild(child.id)"
                                    class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                    <TrashIcon class="size-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Flat list fallback when product has no defined related products -->
                <div v-else-if="asset.child_assets?.length">
                    <p class="text-xs text-gray-400 mb-1">Onderdelen</p>
                    <div v-for="child in asset.child_assets" :key="child.id"
                        class="flex items-center justify-between py-1 border-b border-gray-50">
                        <div>
                            <Link :href="`/assets/${child.id}`" class="text-blue-600 underline text-sm">
                                {{ child.product.brand.name }} {{ child.product.model }}
                            </Link>
                            <span class="text-xs text-gray-400 ml-2">{{ child.product?.bundle ? 'Bundel' :
                                child.serial_number }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400">{{ child.product_relation?.name ?? '—' }}</span>
                            <button v-if="hasPermission('assetrelation.delete')" @click="detachChild(child.id)"
                                class="text-red-400 hover:text-red-600" v-tooltip="'Koppeling verwijderen'">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="asset.parent_asset" class="mt-2">
                    <p class="text-xs text-gray-400 mb-1">Onderdeel van</p>
                    <div class="flex items-center justify-between py-1 border-b border-gray-50 dark:border-slate-700">
                        <div>
                            <Link :href="`/assets/${asset.parent_asset.id}`" class="text-blue-600 underline text-sm">
                                {{ asset.parent_asset.product.brand.name }} {{ asset.parent_asset.product.model }}
                            </Link>
                            <span class="text-xs text-gray-400 ml-2">{{ asset.parent_asset.product?.bundle ? 'Bundel' :
                                asset.parent_asset.serial_number }}</span>
                        </div>
                        <span class="text-xs text-gray-400">{{ asset.product_relation?.name ?? '—' }}</span>
                    </div>
                </div>

                <div v-if="!asset.product.productables?.length && productHasChildTypes && !eligibleChildAssets.length && !asset.child_assets?.length && hasPermission('assetrelation.create')"
                    class="mt-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded text-xs text-amber-700 dark:text-amber-300">
                    Er zijn geen machines van de juiste subtypes gevonden bij deze klant om te koppelen. Voeg eerst een
                    onderdeel-machine toe aan de klant.
                </div>

                <div v-if="eligibleChildAssets.length && hasPermission('assetrelation.create')" class="mt-3">
                    <div v-if="!addingManualLink">
                        <button @click="addingManualLink = true"
                            class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                            <PlusIcon class="size-4" /> Machine handmatig koppelen
                        </button>
                    </div>
                    <div v-else
                        class="p-3 border border-gray-200 rounded-md bg-gray-50 dark:bg-slate-800 space-y-2 mt-2">
                        <div class="flex gap-2 flex-wrap">
                            <div class="flex-1 min-w-48">
                                <label class="block text-xs text-gray-500 mb-1">Machine</label>
                                <ComboBox :options="eligibleChildAssets" v-model="manualLink.child_asset_id"
                                    placeholder="Selecteer machine" />
                            </div>
                            <div class="flex-1 min-w-32">
                                <label class="block text-xs text-gray-500 mb-1">Relatietype</label>
                                <ComboBox :options="productRelations" v-model="manualLink.product_relation_id"
                                    placeholder="Selecteer type" />
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button @click="submitManualLink"
                                class="px-3 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700">
                                Koppelen
                            </button>
                            <button
                                @click="addingManualLink = false; manualLink.child_asset_id = null; manualLink.product_relation_id = null"
                                class="px-3 py-1 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300">
                                Annuleren
                            </button>
                        </div>
                    </div>
                </div>
            </BoxComponent>
            <BoxComponent class="mt-5">
                <SectionHeader :icon="ClipboardDocumentCheckIcon" title="Keuringen"
                    subtitle="Alle keuringen die op deze machine zijn uitgevoerd." chapter="inspections" />
                <ServiceJobsTable :servicejobs="asset.servicejobs" class="mt-3" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent v-if="hasPermission('image.upload') || hasPermission('image.see')" class="mt-6 md:mt-0">
                <ImageUploadComponent title="Foto's van de machine" :existing="asset.images" :imageable-id="asset.id"
                    imageable-type="\App\Models\Asset" />
            </BoxComponent>
            <BoxComponent v-if="asset.product.images.length > 0 && hasPermission('image.see')" class="mt-6">
                <SectionHeader :icon="CubeIcon" chapter="products" border>
                    Foto's van het
                    <Link :href="`/products/${asset.product.id}`" class="text-blue-600 underline">product</Link>
                </SectionHeader>
                <div class="grid grid-cols-2 gap-6 items-center mt-4">
                    <img v-for="image in asset.product.images" :key="image.id" :src="`/storage/${image.path}`"
                        alt="{{ image.name }}" class="w-full h-auto rounded-lg mb-4" />
                </div>
            </BoxComponent>
            <BoxComponent v-if="hasPermission('maintenancecontract.read')" class="mt-6">
                <SectionHeader :icon="ShieldCheckIcon" title="Onderhoudscontracten"
                    subtitle="De contracten waaronder deze machine valt." chapter="contracts" border />
                <div v-if="!asset.maintenance_contracts?.length"
                    class="text-sm text-gray-500 dark:text-slate-400">
                    Niet gekoppeld aan een onderhoudscontract
                </div>
                <div v-else class="space-y-2" v-auto-animate>
                    <Link v-for="contract in asset.maintenance_contracts" :key="contract.id"
                        :href="`/maintenancecontracts/${contract.id}`"
                        class="flex items-center gap-3 rounded-lavoro-sm border border-gray-200 dark:border-slate-700/60 p-3 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-sm font-bold text-gray-800 dark:text-slate-200">{{
                                    contract.display_title }}</span>
                                <BadgeComponent :color="maintenanceContractStatusBadgeColor(contract.status)"
                                    :has-dot="false">
                                    {{ maintenanceContractStatusText(contract.status) }}
                                </BadgeComponent>
                            </div>
                            <div class="mt-1.5 grid grid-cols-2 gap-4">
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Startdatum"
                                    :value="nlDate(contract.start_date)" />
                                <TitleValueIconComponent :icon="CalendarDaysIcon" title="Einddatum"
                                    :value="contract.end_date ? nlDate(contract.end_date) : 'heden'" />
                            </div>
                        </div>
                        <ChevronRightIcon class="size-5 flex-none text-gray-400 dark:text-slate-500 stroke-2" />
                    </Link>
                </div>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>

    <CustomerTransferModal v-model:open="showTransferModal" context="asset" :subject-id="asset.id"
        :customer-id="form.customer_id" :new-customer-name="newCustomerName"
        @confirm="onTransferConfirm" @cancel="onTransferCancel" />

    <DrawerComponent v-model="showTicketDrawer" title="Nieuwe storing"
        :subtitle="`Nieuwe storing voor ${assetTitle}`">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Onderwerp</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newTicketForm.subject" type="text"
                        :hasError="Boolean(newTicketForm.errors.subject)"
                        :errorMessage="newTicketForm.errors.subject" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-start">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Beschrijving</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newTicketForm.description" type="text" placeholder="Optioneel"
                        :hasError="Boolean(newTicketForm.errors.description)"
                        :errorMessage="newTicketForm.errors.description" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Status</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="ticketStatusses" v-model="newTicketForm.status"
                        :hasError="Boolean(newTicketForm.errors.status)" :errorMessage="newTicketForm.errors.status" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prioriteit</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="ticketPriorities" v-model="newTicketForm.priority"
                        :hasError="Boolean(newTicketForm.errors.priority)"
                        :errorMessage="newTicketForm.errors.priority" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeTicketDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewTicket" :disabled="newTicketForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Storing aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import CustomerTransferModal from '@/Components/UI/CustomerTransferModal.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BuildingOffice2Icon, CalendarDaysIcon, ChevronRightIcon, ClipboardDocumentCheckIcon, CubeIcon, ExclamationCircleIcon, ExclamationTriangleIcon, HashtagIcon, LinkIcon, MapPinIcon, PlusIcon, PuzzlePieceIcon, ShieldCheckIcon, TrashIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline';
import { Link, useForm, router } from '@inertiajs/vue3';
import { useCustomerLocations } from '@/Composables/useCustomerLocations';
import { ref, watch, computed, reactive, onMounted, nextTick } from 'vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue';
import ServiceJobsTable from '@/Components/ServiceJobs/ServiceJobsTable.vue';
import { ticketStatusses, ticketPriorities } from '@/Components/data/TicketData';
import dayjs from 'dayjs';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TitleValueIconComponent from '@/Components/UI/TitleValueIconComponent.vue';
import { hasPermission, nlDate, initials, ticketStatusDotClasses, ticketPriorityColor, maintenanceContractStatusText, maintenanceContractStatusBadgeColor } from '@/Utilities/Utilities';
import { useComboSearch } from '@/Composables/useComboSearch';

const showTicketDrawer = ref(false);

const props = defineProps({
    asset: {
        type: Object,
        required: true,
    },
    allProducts: {
        type: Array,
        required: true,
    },
    productsUseAjax: { type: Boolean, default: false },
    allCustomers: {
        type: Array,
        required: true,
    },
    customersUseAjax: { type: Boolean, default: false },
    customFields: {
        type: Array,
        default: () => [],
    },
    eligibleChildAssets: { type: Array, default: () => [] },
    productHasChildTypes: { type: Boolean, default: false },
    productRelations: { type: Array, default: () => [] },
    owningCustomer: { type: Object, default: null },
});

const statusOptions = [
    { id: 'Actief', name: 'Actief' },
    { id: 'Niet actief', name: 'Niet actief' },
];

const { options: productOptions, searching: productSearching, search: searchProducts } =
    useComboSearch('products', props.allProducts, props.productsUseAjax)
const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const form = useForm({
    product_id: props.asset.product.id,
    serial_number: props.asset.serial_number,
    next_service_date: props.asset.next_service_date,
    date_in_service: props.asset.date_in_service,
    status: props.asset.status,
    customer_id: props.owningCustomer?.id ?? null,
    location_id: props.asset.location_id ?? null,
});

/** A child machine is owned through its parent, so its customer is not editable here. */
const isChildAsset = computed(() => Boolean(props.asset.parent_asset_id))

const { locations: locationOptions, load: loadLocations } = useCustomerLocations();

onMounted(() => loadLocations(form.customer_id));

/**
 * Picking another customer invalidates the current location and is handled by the transfer
 * modal, so neither the clearing nor the customer change itself may reach the auto-save.
 */
const suppressAutoSave = ref(false);

watch(() => form.customer_id, (customerId) => {
    suppressAutoSave.value = true;
    form.location_id = null;
    loadLocations(customerId);
    nextTick(() => { suppressAutoSave.value = false });
});

const canUpdate = computed(() => hasPermission('asset.update'))
const canDelete = computed(() => hasPermission('asset.delete'))
const canReadSuppliers = computed(() => hasPermission('supplier.read'))

const assetTitle = computed(() => {
    const brandName = props.asset.product?.brand?.name
    const model = props.asset.product?.model
    return [brandName, model].filter(Boolean).join(' ') || `Machine #${props.asset.id}`
})

const breadcrumbItems = computed(() => [
    { label: 'Machines', href: '/assets' },
    { label: assetTitle.value },
])

const statusBadgeColor = computed(() => (props.asset.status === 'Actief' ? 'green' : 'red'))

const headerImage = computed(() => {
    const assetMain = props.asset.images?.find(image => image.pivot?.main) ?? props.asset.images?.[0]
    if (assetMain) return `/storage/${assetMain.path}`
    const productImage = props.asset.product?.images?.find(image => image.pivot?.main) ?? props.asset.product?.images?.[0]
    if (productImage) return `/storage/${productImage.path}`
    return null
})

const preferredSupplier = computed(() => {
    const suppliers = props.asset.product?.suppliers ?? []
    return suppliers.find(supplier => supplier.pivot?.is_preferred) ?? suppliers[0] ?? null
})

const daysUntilNextService = computed(() => {
    if (!props.asset.next_service_date) return null
    return dayjs(props.asset.next_service_date).startOf('day').diff(dayjs().startOf('day'), 'day')
})

const nextServiceSub = computed(() => {
    const days = daysUntilNextService.value
    if (days === null) return 'Niet gepland'
    if (days < 0) return `${Math.abs(days)} dagen verlopen`
    if (days === 0) return 'Vandaag'
    return `Nog ${days} dagen`
})

const headerFacts = computed(() => [
    { icon: HashtagIcon, title: 'Serienummer', value: props.asset.product.bundle ? 'Bundel' : (props.asset.serial_number ?? '—') },
    { icon: CubeIcon, title: 'Producttype', value: props.asset.product.product_type?.name ?? '—' },
    { icon: BuildingOffice2Icon, title: 'Klant', value: props.owningCustomer?.name ?? '—' },
    { icon: MapPinIcon, title: 'Locatie', value: props.asset.linked_location?.title ?? '—' },
    { icon: CalendarDaysIcon, title: 'Volgende keuring', value: props.asset.next_service_date ? nlDate(props.asset.next_service_date) : '—' },
])

const metricCards = computed(() => {
    const tickets = props.asset.tickets ?? []
    const closed = tickets.filter(ticket => (ticket.status ?? '').toLowerCase() === 'gesloten').length
    const open = tickets.filter(ticket => ['open', 'in behandeling'].includes((ticket.status ?? '').toLowerCase())).length
    const pending = tickets.filter(ticket => (ticket.status ?? '').toLowerCase() === 'in behandeling').length
    return [
        { icon: CalendarDaysIcon, iconBg: 'bg-blue-500/10', iconColor: 'text-blue-600 dark:text-blue-400', value: props.asset.next_service_date ? nlDate(props.asset.next_service_date) : '—', label: 'Volgende keuring', sub: nextServiceSub.value },
        { icon: WrenchScrewdriverIcon, iconBg: 'bg-green-500/10', iconColor: 'text-green-600 dark:text-green-400', value: String(tickets.length), label: 'Storingen', sub: `${closed} afgerond` },
        { icon: ExclamationTriangleIcon, iconBg: 'bg-amber-500/10', iconColor: 'text-amber-600 dark:text-amber-400', value: String(open), label: 'Open storingen', sub: `${pending} in behandeling` },
        { icon: ClipboardDocumentCheckIcon, iconBg: 'bg-indigo-500/10', iconColor: 'text-indigo-600 dark:text-indigo-400', value: String(props.asset.servicejobs?.length ?? 0), label: 'Keuringen', sub: 'totaal' },
    ]
})

const newTicketForm = useForm({
    asset_id: props.asset.id,
    subject: '',
    description: '',
    status: 'Open',
    priority: 'Hoog',
})

function submitNewTicket() {
    newTicketForm.post('/tickets', {
        preserveScroll: true,
        onSuccess: () => {
            showTicketDrawer.value = false
            newTicketForm.reset()
        },
    })
}

function closeTicketDrawer() {
    showTicketDrawer.value = false
    newTicketForm.reset()
    newTicketForm.clearErrors()
}

const updateAsset = () => {
    if (!canUpdate.value || suppressAutoSave.value) return;
    form.put(`/assets/${props.asset.id}`);
};

const deleteForm = useForm({});
const deleteAsset = () => {
    if (!confirm('Weet je zeker dat je deze machine wilt verwijderen?')) return;
    deleteForm.delete(`/assets/${props.asset.id}`);
};

watch(
    [
        () => form.product_id,
        () => form.status,
        () => form.location_id,
        () => form.serial_number,
        () => form.next_service_date,
        () => form.date_in_service,
    ],
    updateAsset
)

const showTransferModal = ref(false)

const newCustomerName = computed(() =>
    customerOptions.value.find(option => option.id === form.customer_id)?.name ?? ''
)

watch(() => form.customer_id, (customerId) => {
    if (customerId && customerId !== props.owningCustomer?.id) {
        showTransferModal.value = true
    }
})

function onTransferConfirm({ asset_strategy, location_map }) {
    form.transform(data => ({ ...data, asset_strategy, location_map }))
        .put(`/assets/${props.asset.id}`, {
            onFinish: () => form.transform(data => data),
        })
}

function onTransferCancel() {
    suppressAutoSave.value = true
    form.customer_id = props.owningCustomer?.id ?? null
    nextTick(() => { suppressAutoSave.value = false })
}

const addingManualLink = ref(false)
const manualLink = reactive({ child_asset_id: null, product_relation_id: null })

const creatingForSlot = ref(null)
const newChildForm = useForm({ productable_id: null, serial_number: '' })

function childAssetsForSlot(productableId) {
    return props.asset.child_assets?.filter(child => child.productable_id === productableId) ?? []
}

const unslottedChildAssets = computed(() =>
    props.asset.child_assets?.filter(child => !child.productable_id) ?? []
)

function openNewChildForm(slotId) {
    creatingForSlot.value = slotId
    newChildForm.reset()
    newChildForm.clearErrors()
}

function cancelNewChild() {
    creatingForSlot.value = null
    newChildForm.reset()
    newChildForm.clearErrors()
}

function submitNewChild(productableId) {
    newChildForm.productable_id = productableId
    newChildForm.post(`/assets/${props.asset.id}/child`, {
        preserveScroll: true,
        onSuccess: () => {
            creatingForSlot.value = null
            newChildForm.reset()
        },
    })
}

function submitManualLink() {
    router.post(`/assets/${props.asset.id}/children`, {
        child_asset_id: manualLink.child_asset_id,
        product_relation_id: manualLink.product_relation_id,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addingManualLink.value = false
            manualLink.child_asset_id = null
            manualLink.product_relation_id = null
        },
    })
}

/**
 * A machine belongs to either a customer or a parent, so cutting one loose is also an
 * ownership decision: it takes over the customer and location of the machine it hung under.
 */
function detachChild(childAssetId) {
    if (!confirm('De machine wordt losgekoppeld en komt rechtstreeks bij de klant van deze machine te staan. Doorgaan?')) {
        return
    }

    router.delete(`/assets/${childAssetId}/parent`, { preserveScroll: true })
}

</script>
