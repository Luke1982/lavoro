<template>
    <BreadcrumbComponent :items="[{ label: 'Klanten', href: '/customers' }, { label: form.name }]" wrapper-class="mb-6" />

    <div class="flex flex-col sm:flex-row items-start my-4 gap-4">
        <BuildingOffice2Icon
            class="size-12 flex-none rounded-lg bg-white dark:bg-slate-800 ring-1 ring-gray-900/10 dark:ring-slate-600 p-2 text-gray-700 dark:text-slate-200" />
        <div class="flex flex-col justify-around flex-grow items-start gap-3">
            <div class="flex items-center gap-2">
                <EditableTextField v-model="form.name" type="input" :decoration="false" :error="form.errors.name"
                    :disabled="!canUpdate" @revert="form.clearErrors('name')">
                    <template #display>
                        <h1 class="text-2xl font-bold dark:text-slate-100">{{ form.name }}</h1>
                    </template>
                </EditableTextField>
            </div>
            <div class="flex gap-x-3 gap-y-3 sm:gap-12 flex-wrap">
                <TitleValueIconComponent v-if="form.phone" class="w-[calc(50%-0.375rem)] sm:w-auto" :icon="PhoneIcon"
                    title="Telefoon" :value="form.phone" />
                <TitleValueIconComponent v-if="form.mobile" class="w-[calc(50%-0.375rem)] sm:w-auto"
                    :icon="DevicePhoneMobileIcon" title="Mobiel" :value="form.mobile" />
                <TitleValueIconComponent v-if="form.email" class="w-[calc(50%-0.375rem)] sm:w-auto" :icon="EnvelopeIcon"
                    title="E-mail" :value="form.email" />
                <div v-if="form.address" class="flex flex-col min-w-0 w-[calc(50%-0.375rem)] sm:w-auto">
                    <div class="relative pl-7">
                        <MapPinIcon class="size-5 text-slate-400 inline mr-1 absolute left-0 top-1" />
                        <span class="text-xs text-slate-400 font-bold">Adres</span>
                    </div>
                    <a :href="mapsLinkFromCustomer(form)" target="_blank" rel="noopener"
                        class="text-md text-slate-600 pl-7 font-bold hover:underline break-words">
                        {{ form.address }}, {{ form.postal_code }} {{ form.city }}
                    </a>
                </div>
                <TitleValueIconComponent v-if="form.chamber_of_commerce_number" class="w-[calc(50%-0.375rem)] sm:w-auto"
                    :icon="BuildingLibraryIcon" title="KvK-nummer" :value="form.chamber_of_commerce_number" />
            </div>
        </div>
    </div>

    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(chapter, index) in chapters" :key="index" :index="index">
                {{ chapter }}
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>

            <!-- Chapter 0: Overzicht -->
            <template #chapter-0>
                <TwoThirdsOneThird>
                    <template #main>
                        <BoxComponent>
                            <div class="flex items-center mb-4">
                                <div class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-lavoro-blue/10 mr-3">
                                    <UserIcon class="size-6 text-lavoro-blue stroke-2" />
                                </div>
                                <span class="text-md font-bold dark:text-slate-100">Contactgegevens</span>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2">
                                <div class="flex flex-col gap-6 md:pr-8">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <UserIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.name" type="input" label="Naam"
                                            :error="form.errors.name" :disabled="!canUpdate"
                                            @revert="form.clearErrors('name')" class="flex-grow min-w-0" />
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <IdentificationIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.contactname" type="input" label="Contactpersoon"
                                            placeholder="Nog niet ingesteld" :error="form.errors.contactname"
                                            :disabled="!canUpdate" @revert="form.clearErrors('contactname')" class="flex-grow min-w-0" />
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <PhoneIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.phone" type="input" label="Telefoon"
                                            placeholder="Nog niet ingesteld" :error="form.errors.phone"
                                            :disabled="!canUpdate" @revert="form.clearErrors('phone')" class="flex-grow min-w-0" />
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <DevicePhoneMobileIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.mobile" type="input" label="Mobiel"
                                            placeholder="Nog niet ingesteld" :error="form.errors.mobile"
                                            :disabled="!canUpdate" @revert="form.clearErrors('mobile')" class="flex-grow min-w-0" />
                                    </div>
                                </div>
                                <div class="flex flex-col gap-6 md:pl-8 md:border-l md:border-gray-200/70 mt-6 md:mt-0">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <EnvelopeIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.email" type="input" label="E-mail"
                                            placeholder="Nog niet ingesteld" :error="form.errors.email"
                                            :disabled="!canUpdate" @revert="form.clearErrors('email')" class="flex-grow min-w-0" />
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="flex-none flex items-center justify-center size-9 rounded-lavoro-sm bg-lavoro-blue/10">
                                            <GlobeAltIcon class="size-5 text-lavoro-blue stroke-2" />
                                        </div>
                                        <EditableTextField v-model="form.website" type="input" label="Website"
                                            placeholder="Nog niet ingesteld" :error="form.errors.website"
                                            :disabled="!canUpdate" @revert="form.clearErrors('website')" class="flex-grow min-w-0" />
                                    </div>
                                </div>
                            </div>
                        </BoxComponent>

                        <BoxComponent class="mt-4">
                            <div class="flex items-center mb-4">
                                <div class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-violet-600/10 mr-3">
                                    <MapPinIcon class="size-6 text-violet-600 stroke-2" />
                                </div>
                                <span class="text-md font-bold dark:text-slate-100">Adressen</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
                                <div>
                                    <h3
                                        class="text-xs font-semibold mb-3 text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                                        Bezoekadres</h3>
                                    <div class="flex flex-col gap-6">
                                        <EditableTextField v-model="form.address" type="input"
                                            label="Straat + huisnummer" placeholder="Nog niet ingesteld"
                                            :error="form.errors.address" :disabled="!canUpdate"
                                            @revert="form.clearErrors('address')" />
                                        <EditableTextField v-model="form.postal_code" type="input" label="Postcode"
                                            placeholder="Nog niet ingesteld" :error="form.errors.postal_code"
                                            :disabled="!canUpdate" @revert="form.clearErrors('postal_code')" />
                                        <EditableTextField v-model="form.city" type="input" label="Stad"
                                            placeholder="Nog niet ingesteld" :error="form.errors.city"
                                            :disabled="!canUpdate" @revert="form.clearErrors('city')" />
                                        <EditableTextField v-model="form.country" type="input" label="Land"
                                            placeholder="Nog niet ingesteld" :error="form.errors.country"
                                            :disabled="!canUpdate" @revert="form.clearErrors('country')" />
                                    </div>
                                </div>
                                <div class="mt-6 md:mt-0 md:border-l md:border-gray-200/70 md:pl-8">
                                    <h3
                                        class="text-xs font-semibold mb-3 text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                                        Postadres</h3>
                                    <div class="flex flex-col gap-6">
                                        <EditableTextField v-model="form.postal_address" type="input"
                                            label="Straat + huisnummer" placeholder="Zelfde als bezoekadres"
                                            :error="form.errors.postal_address" :disabled="!canUpdate"
                                            @revert="form.clearErrors('postal_address')" />
                                        <EditableTextField v-model="form.postal_postal_code" type="input"
                                            label="Postcode" placeholder="Zelfde als bezoekadres"
                                            :error="form.errors.postal_postal_code" :disabled="!canUpdate"
                                            @revert="form.clearErrors('postal_postal_code')" />
                                        <EditableTextField v-model="form.postal_city" type="input" label="Stad"
                                            placeholder="Zelfde als bezoekadres" :error="form.errors.postal_city"
                                            :disabled="!canUpdate" @revert="form.clearErrors('postal_city')" />
                                        <EditableTextField v-model="form.postal_country" type="input" label="Land"
                                            placeholder="Zelfde als bezoekadres" :error="form.errors.postal_country"
                                            :disabled="!canUpdate" @revert="form.clearErrors('postal_country')" />
                                    </div>
                                </div>
                            </div>
                        </BoxComponent>

                        <BoxComponent class="mt-4">
                            <div class="flex items-center mb-4">
                                <div class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-green-600/10 mr-3">
                                    <BanknotesIcon class="size-6 text-green-600 stroke-2" />
                                </div>
                                <span class="text-md font-bold dark:text-slate-100">Financiële informatie</span>
                            </div>
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2">
                                <div class="flex flex-col gap-6 md:pr-8">
                                    <EditableTextField v-model="form.invoice_email" type="input" label="Factuur e-mail"
                                        placeholder="Nog niet ingesteld" :error="form.errors.invoice_email"
                                        :disabled="!canUpdate" @revert="form.clearErrors('invoice_email')" />
                                    <EditableTextField v-model="form.quotes_email" type="input" label="Offerte e-mail"
                                        placeholder="Nog niet ingesteld" :error="form.errors.quotes_email"
                                        :disabled="!canUpdate" @revert="form.clearErrors('quotes_email')" />
                                    <EditableTextField v-model="form.iban" type="input" label="IBAN"
                                        placeholder="Nog niet ingesteld" :error="form.errors.iban"
                                        :disabled="!canUpdate" @revert="form.clearErrors('iban')" />
                                </div>
                                <div class="flex flex-col gap-6 md:pl-8 md:border-l md:border-gray-200/70 mt-6 md:mt-0">
                                    <EditableTextField v-model="form.vat_number" type="input" label="BTW-nummer"
                                        placeholder="Nog niet ingesteld" :error="form.errors.vat_number"
                                        :disabled="!canUpdate" @revert="form.clearErrors('vat_number')" />
                                    <EditableTextField v-model="form.chamber_of_commerce_number" type="input"
                                        label="KvK-nummer" placeholder="Nog niet ingesteld"
                                        :error="form.errors.chamber_of_commerce_number" :disabled="!canUpdate"
                                        @revert="form.clearErrors('chamber_of_commerce_number')" />
                                </div>
                            </div>
                        </BoxComponent>

                        <CustomFieldsComponent v-if="customFields.length" model-type="customer" :model-id="customer.id"
                            :custom-fields="customFields" :can-edit="hasPermission('customfield.update')"
                            class="mt-4" />
                    </template>

                    <template #sidebar>
                        <div class="mt-4 md:mt-0 space-y-4">
                            <BoxComponent v-if="form.address" padding="p-0" extra-classes="overflow-hidden">
                                <OpenStreetMapWidget :key="`${form.address},${form.postal_code} ${form.city}`"
                                    :address="`${form.address}, ${form.postal_code} ${form.city}`" />
                            </BoxComponent>

                            <BoxComponent>
                                <div
                                    class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-3 justify-between items-center">
                                    <div class="flex items-center">
                                        <div class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-lavoro-blue/10 mr-3">
                                            <UsersIcon class="size-6 text-lavoro-blue stroke-2" />
                                        </div>
                                        <h2 class="font-semibold text-base dark:text-slate-200">Contacten</h2>
                                    </div>
                                    <button v-if="hasPermission('contact.create')" type="button"
                                        @click="showContactDrawer = true"
                                        class="flex flex-none items-center gap-1.5 rounded-md bg-lavoro-blue px-3 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-lavoro-blue focus:ring-offset-2 dark:focus:ring-offset-slate-900">
                                        <PlusIcon class="size-4" />
                                        <span class="hidden sm:inline">Contact toevoegen</span>
                                    </button>
                                </div>
                                <div v-if="!customer.contacts?.length"
                                    class="text-sm text-gray-500 dark:text-slate-400">
                                    Nog geen contacten
                                </div>
                                <div v-else>
                                    <div class="divide-y divide-gray-100 dark:divide-slate-700/60" v-auto-animate>
                                        <div v-for="contact in visibleContacts" :key="contact.id"
                                            class="flex items-center gap-3 py-3 first:pt-0">
                                            <div class="flex-none flex items-center justify-center size-10 rounded-full bg-lavoro-blue/10 text-lavoro-blue font-semibold text-sm">
                                                {{ initials(contact.full_name) }}
                                            </div>
                                            <div class="min-w-0 flex-grow">
                                                <Link :href="`/contacts/${contact.id}`"
                                                    class="block truncate font-semibold text-sm text-gray-800 dark:text-slate-200 hover:underline">
                                                    {{ contact.full_name }}
                                                </Link>
                                                <a v-if="contact.email" :href="`mailto:${contact.email}`"
                                                    class="block truncate text-xs text-gray-500 dark:text-slate-400 hover:underline">
                                                    {{ contact.email }}
                                                </a>
                                            </div>
                                            <Menu v-if="hasPermission('contact.update') || hasPermission('contact.delete')"
                                                as="div" class="relative flex-none">
                                                <MenuButton
                                                    class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-slate-700 cursor-pointer">
                                                    <EllipsisVerticalIcon class="size-5 text-gray-400 dark:text-slate-500" />
                                                </MenuButton>
                                                <transition enter-active-class="transition ease-out duration-100"
                                                    enter-from-class="transform opacity-0 scale-95"
                                                    enter-to-class="transform opacity-100 scale-100"
                                                    leave-active-class="transition ease-in duration-75"
                                                    leave-from-class="transform opacity-100 scale-100"
                                                    leave-to-class="transform opacity-0 scale-95">
                                                    <MenuItems
                                                        class="absolute right-0 z-10 mt-1 w-48 rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 focus:outline-none">
                                                        <div class="py-1 text-sm">
                                                            <MenuItem v-if="hasPermission('contact.update')" v-slot="{ active }">
                                                                <button type="button" @click="detachContact(contact)"
                                                                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2 w-full px-4 py-2 text-gray-700 dark:text-slate-300']">
                                                                    <LinkSlashIcon class="size-4 shrink-0" />
                                                                    Loskoppelen
                                                                </button>
                                                            </MenuItem>
                                                            <MenuItem v-if="hasPermission('contact.delete')" v-slot="{ active }">
                                                                <button type="button" @click="deleteContact(contact)"
                                                                    :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2 w-full px-4 py-2 text-red-600 dark:text-red-400']">
                                                                    <TrashIcon class="size-4 shrink-0" />
                                                                    Verwijderen
                                                                </button>
                                                            </MenuItem>
                                                        </div>
                                                    </MenuItems>
                                                </transition>
                                            </Menu>
                                        </div>
                                    </div>
                                    <button v-if="customer.contacts.length > contactPreviewCount" type="button"
                                        @click="showAllContacts = !showAllContacts"
                                        class="mt-3 flex items-center gap-1 text-sm font-medium text-lavoro-blue hover:underline">
                                        {{ showAllContacts ? 'Minder tonen' : `Alle ${customer.contacts.length} contacten bekijken` }}
                                        <ArrowRightIcon class="size-4 transition-transform"
                                            :class="{ 'rotate-90': showAllContacts }" />
                                    </button>
                                </div>
                            </BoxComponent>

                            <BoxComponent>
                                <div
                                    class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-2 justify-between items-center">
                                    <div class="flex items-center">
                                        <div
                                            class="flex-none flex items-center justify-center size-10 rounded-lavoro-sm bg-indigo-500/10 mr-3">
                                            <ShieldCheckIcon class="size-6 text-indigo-600 dark:text-indigo-400 stroke-2" />
                                        </div>
                                        <h2 class="font-semibold text-base dark:text-slate-200">Onderhoudscontracten</h2>
                                    </div>
                                    <button v-if="canCreateMaintenanceContract" type="button"
                                        @click="showMaintenanceContractDrawer = true"
                                        v-tooltip="`Nieuw onderhoudscontract voor ${form.name}`"
                                        class="flex flex-none items-center gap-1.5 rounded-md bg-lavoro-blue px-3 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-lavoro-blue focus:ring-offset-2 dark:focus:ring-offset-slate-900">
                                        <PlusIcon class="size-4" />
                                        <span class="hidden sm:inline">Contract toevoegen</span>
                                    </button>
                                </div>
                                <div v-if="!customer.maintenance_contracts?.length"
                                    class="text-sm text-gray-500 dark:text-slate-400">
                                    Geen onderhoudscontracten
                                </div>
                                <template v-else>
                                    <div class="flex items-center gap-5 mb-5">
                                        <CircularCounter :segments="contractDonutSegments"
                                            :total="customer.maintenance_contracts.length" label="Totaal"
                                            :size="112" :stroke="11" />
                                        <div class="flex-1 space-y-2">
                                            <div v-for="row in contractStatusLegend" :key="row.status"
                                                class="flex items-center justify-between text-sm">
                                                <span class="flex items-center gap-2">
                                                    <span class="size-2.5 rounded-full flex-none"
                                                        :style="{ backgroundColor: row.color }" />
                                                    <span class="text-gray-700 dark:text-slate-300">{{ row.label }}</span>
                                                </span>
                                                <span class="font-semibold text-gray-900 dark:text-slate-100">{{ row.count }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2" v-auto-animate>
                                        <Link v-for="contract in visibleContracts" :key="contract.id"
                                            :href="`/maintenancecontracts/${contract.id}`"
                                            class="flex items-center gap-3 rounded-lavoro-sm border border-gray-200 dark:border-slate-700/60 p-3 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span class="text-sm font-bold text-gray-800 dark:text-slate-200">{{ contract.display_title }}</span>
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

                                    <button v-if="customer.maintenance_contracts.length > contractPreviewCount"
                                        type="button" @click="showAllContracts = !showAllContracts"
                                        class="mt-3 flex items-center gap-1 text-sm font-medium text-lavoro-blue hover:underline">
                                        {{ showAllContracts ? 'Minder tonen' : `Alle ${customer.maintenance_contracts.length} contracten bekijken` }}
                                        <ArrowRightIcon class="size-4 transition-transform"
                                            :class="{ 'rotate-90': showAllContracts }" />
                                    </button>
                                </template>
                            </BoxComponent>

                            <BoxComponent>
                                <CustomerLocationsWidget :customer-id="customer.id"
                                    :locations="customer.locations || []" />
                            </BoxComponent>

                            <BoxComponent v-if="canUpdate">
                                <div
                                    class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-2 items-center">
                                    <ReceiptPercentIcon
                                        class="size-5 flex-none text-gray-500 dark:text-slate-400 mr-2" />
                                    <h2 class="font-semibold text-base dark:text-slate-200">Factuurklant</h2>
                                </div>
                                <div class="flex items-end gap-2">
                                    <ComboBox :options="customerOptions" v-model="form.billing_customer_id"
                                        label="Factuurklant" placeholder="Kies naar welke klant de factuur moet"
                                        :has-external-searching="customersUseAjax" :searching="customerSearching"
                                        @change="searchCustomers" @update:modelValue="updateBillingCustomer"
                                        class="grow" />
                                    <XCircleIcon v-if="form.billing_customer_id"
                                        class="size-6 mb-1.5 text-gray-400 hover:text-gray-600 cursor-pointer"
                                        @click="clearBillingCustomer" v-tooltip="'Factuurklant leegmaken'" />
                                </div>
                            </BoxComponent>
                        </div>
                    </template>
                </TwoThirdsOneThird>
            </template>

            <!-- Chapter 1: Machines -->
            <template #chapter-1>
                <BoxComponent>
                    <div class="flex items-center pb-3 border-b border-gray-200 dark:border-slate-700/60 mb-4">
                        <PuzzlePieceIcon class="size-5 text-gray-500 dark:text-slate-400 mr-2" />
                        <h3 class="text-sm font-medium dark:text-slate-200">Machines</h3>
                        <button v-if="hasPermission('asset.create')" @click="addAssetDrawerOpen = true"
                            class="text-blue-600 hover:text-blue-800 pl-2 cursor-pointer"
                            v-tooltip="'Nieuwe machine toevoegen'">
                            <PlusIcon class="size-4" />
                        </button>
                    </div>
                    <div class="flex flex-col md:flex-row md:items-start md:gap-4">
                        <div class="w-full md:w-72">
                            <ComboBox :options="productTypeOptions" v-model="selectedProductTypeIds" multiple
                                placeholder="Filter apparaat type" />
                        </div>
                        <div v-if="locationFilterOptions.length" class="w-full md:w-72 mt-3 md:mt-0">
                            <ComboBox :options="locationFilterOptions" v-model="selectedLocationIds" multiple
                                placeholder="Filter op locatie" />
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-3 md:mt-0">
                            <template v-if="hasActiveFilters">
                                <span v-for="pt in selectedProductTypes" :key="`pt-${pt.id}`"
                                    class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 dark:text-slate-200 dark:ring-slate-700">
                                    {{ pt.name }}
                                    <button type="button" @click="removeProductType(pt.id)"
                                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                                        <span class="sr-only">Remove</span>
                                        <svg viewBox="0 0 14 14"
                                            class="h-3.5 w-3.5 text-gray-600/50 stroke-gray-600/75 group-hover:stroke-gray-600/75 dark:text-slate-400 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                                            <path d="M4 4l6 6m0-6l-6 6" />
                                        </svg>
                                        <span class="absolute -inset-1" />
                                    </button>
                                </span>
                                <span v-for="loc in selectedLocations" :key="`loc-${loc.id}`"
                                    class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-lavoro-blue ring-1 ring-inset ring-lavoro-blue/30 dark:text-lavoro-lightblue dark:ring-lavoro-blue/40">
                                    {{ loc.name }}
                                    <button type="button" @click="removeLocation(loc.id)"
                                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                                        <span class="sr-only">Remove</span>
                                        <svg viewBox="0 0 14 14"
                                            class="h-3.5 w-3.5 text-lavoro-blue/60 stroke-lavoro-blue/75 dark:text-lavoro-blue dark:stroke-lavoro-blue">
                                            <path d="M4 4l6 6m0-6l-6 6" />
                                        </svg>
                                        <span class="absolute -inset-1" />
                                    </button>
                                </span>
                                <button type="button" @click="resetFilters"
                                    class="text-xs text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">Reset</button>
                            </template>
                            <span v-else class="text-xs text-gray-500 dark:text-slate-400">Alle machines</span>
                        </div>
                    </div>
                    <div class="mt-4" v-if="canReadAssets && hasAssetsFiltered">
                        <AssetListComponent :assets="assetsFiltered" :customer-id="customer.id"
                            :locations="customer.locations || []" />
                    </div>
                    <p v-if="canReadAssets && !hasAssetsFiltered"
                        class="text-sm text-gray-400 dark:text-slate-500 italic mt-4">
                        Geen machines gevonden.
                    </p>
                </BoxComponent>
            </template>

            <!-- Chapter 2: Werkbonnen & Projecten -->
            <template #chapter-2>
                <TwoThirdsOneThird>
                    <template #main>
                        <BoxComponent>
                            <div
                                class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-2 justify-between items-center">
                                <div class="flex items-center">
                                    <FolderIcon class="size-5 flex-none text-gray-500 dark:text-slate-400 mr-2" />
                                    <h2 class="font-semibold text-base dark:text-slate-200">Projecten</h2>
                                </div>
                                <AddButton v-if="canCreateProject"
                                    @click="projectFormRef?.show()"
                                    v-tooltip="`Maak een nieuw project aan voor ${form.name}`" />
                            </div>

                            <CreateRecordForm ref="projectFormRef" external-trigger action="/projects"
                                :fields="projectFields" submit-label="Opslaan" />

                            <div v-if="!sortedProjects.length" class="text-sm text-gray-500 dark:text-slate-400">
                                Nog geen projecten
                            </div>
                            <div v-else class="space-y-3" v-auto-animate>
                                <div v-for="project in sortedProjects" :key="project.id"
                                    class="rounded-md border border-gray-200 dark:border-slate-700/60 p-3 bg-white dark:bg-slate-900/40">
                                    <div class="flex items-start justify-between gap-2">
                                        <component :is="hasPermission('project.read') ? Link : 'span'"
                                            :href="`/projects/${project.id}`" :class="{
                                                'text-gray-800 dark:text-slate-200 font-medium': true,
                                                'underline hover:text-gray-600 dark:hover:text-slate-400': hasPermission('project.read')
                                            }">
                                            {{ project.title }}
                                        </component>
                                        <span class="text-xs" :class="projectStatusClass(project.status)">{{
                                            project.status }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                        Start: {{ project.start_date ? nlDate(project.start_date) : 'Onbekend' }}
                                        <span v-if="project.project_manager"> • Projectleider: {{
                                            project.project_manager.name }}</span>
                                    </div>
                                    <h4
                                        class="mt-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-slate-400">
                                        Werkbonnen
                                    </h4>
                                    <div class="mt-2 space-y-2" v-auto-animate>
                                        <div v-if="!project.service_orders?.length"
                                            class="text-xs text-gray-500 dark:text-slate-400">
                                            Geen werkbonnen binnen dit project
                                        </div>
                                        <ServiceOrderRow v-for="serviceorder in project.service_orders"
                                            :key="serviceorder.id" :serviceorder="serviceorder" />
                                    </div>
                                </div>
                            </div>
                        </BoxComponent>
                    </template>

                    <template #sidebar>
                        <div class="mt-4 md:mt-0">
                            <BoxComponent>
                                <div
                                    class="flex mb-4 border-b border-gray-200 dark:border-slate-700/60 pb-2 justify-between items-center">
                                    <div class="flex items-center">
                                        <ClipboardDocumentListIcon
                                            class="size-5 flex-none text-gray-500 dark:text-slate-400 mr-2" />
                                        <h2 class="font-semibold text-base dark:text-slate-200">Losse werkbonnen</h2>
                                    </div>
                                    <AddButton v-if="canCreateServiceOrder"
                                        @click="newServiceOrderForm.post(`/serviceorders`, { preserveScroll: true })"
                                        v-tooltip="`Maak een nieuwe werkbon aan voor ${form.name}`" />
                                </div>
                                <div v-if="!serviceOrdersWithoutProject.length"
                                    class="text-sm text-gray-500 dark:text-slate-400">
                                    Geen losse werkbonnen
                                </div>
                                <ServiceOrderRow v-for="serviceorder in serviceOrdersWithoutProject"
                                    :key="serviceorder.id" :serviceorder="serviceorder" />
                            </BoxComponent>
                        </div>
                    </template>
                </TwoThirdsOneThird>
            </template>

            <!-- Chapter 3: Afspraken -->
            <template #chapter-3>
                <BoxComponent>
                    <div class="flex items-center mb-4">
                        <ClockIcon class="size-5 mr-2 text-gray-500 dark:text-slate-400" />
                        <span class="text-md font-bold dark:text-slate-100">Afspraken</span>
                    </div>
                    <EventTimelineComponent :events="eventList" />
                </BoxComponent>
            </template>

        </ChapterContents>
    </ChaptersComponent>

    <DrawerComponent v-model="showContactDrawer" :title="`Nieuw contact voor ${form.name}`"
        subtitle="Vul de gegevens in van het nieuwe contact.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Voornaam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.first_name" type="text"
                        :hasError="Boolean(newContactForm.errors.first_name)"
                        :errorMessage="newContactForm.errors.first_name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Achternaam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.last_name" type="text"
                        :hasError="Boolean(newContactForm.errors.last_name)"
                        :errorMessage="newContactForm.errors.last_name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">E-mail</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.email" type="text"
                        :hasError="Boolean(newContactForm.errors.email)" :errorMessage="newContactForm.errors.email" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Telefoon</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.phone" type="text"
                        :hasError="Boolean(newContactForm.errors.phone)" :errorMessage="newContactForm.errors.phone" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Mobiel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newContactForm.mobile" type="text"
                        :hasError="Boolean(newContactForm.errors.mobile)" :errorMessage="newContactForm.errors.mobile" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeContactDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewContact" :disabled="newContactForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>

    <DrawerComponent v-model="showMaintenanceContractDrawer" :title="`Nieuw onderhoudscontract voor ${form.name}`"
        subtitle="Vul de gegevens in van het nieuwe contract.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.title" type="text" placeholder="Optioneel" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Startdatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.start_date" type="date"
                        :hasError="Boolean(newMaintenanceContractForm.errors.start_date)"
                        :errorMessage="newMaintenanceContractForm.errors.start_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Einddatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMaintenanceContractForm.end_date" type="date" placeholder="Optioneel" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="newMaintenanceContractForm.price"
                        :hasError="Boolean(newMaintenanceContractForm.errors.price)"
                        :errorMessage="newMaintenanceContractForm.errors.price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijsinterval</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="maintenanceContractIntervalOptions" v-model="newMaintenanceContractForm.price_interval" />
                </div>
            </div>
            <transition :css="false" @enter="collapseEnter" @after-enter="collapseAfterEnter" @enter-cancelled="collapseCancelled" @leave="collapseLeave" @leave-cancelled="collapseCancelled">
                <div v-if="newMaintenanceContractForm.price_interval === 'Aangepast (dagen)'"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="newMaintenanceContractForm.price_interval_days" type="number" />
                    </div>
                </div>
            </transition>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Frequentie per machine beheren</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="newMaintenanceContractForm.manage_frequency_per_asset" />
                </div>
            </div>
            <transition :css="false" @enter="collapseEnter" @after-enter="collapseAfterEnter" @enter-cancelled="collapseCancelled" @leave="collapseLeave" @leave-cancelled="collapseCancelled">
                <div v-if="!newMaintenanceContractForm.manage_frequency_per_asset"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Servicefrequentie</label>
                    <div class="sm:col-span-2">
                        <ComboBox :options="maintenanceContractIntervalOptions" v-model="newMaintenanceContractForm.frequency" />
                    </div>
                </div>
            </transition>
            <transition :css="false" @enter="collapseEnter" @after-enter="collapseAfterEnter" @enter-cancelled="collapseCancelled" @leave="collapseLeave" @leave-cancelled="collapseCancelled">
                <div v-if="!newMaintenanceContractForm.manage_frequency_per_asset && newMaintenanceContractForm.frequency === 'Aangepast (dagen)'"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="newMaintenanceContractForm.frequency_days" type="number" />
                    </div>
                </div>
            </transition>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeMaintenanceContractDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewMaintenanceContract" :disabled="newMaintenanceContractForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>

    <DrawerComponent v-model="addAssetDrawerOpen" :title="`Nieuwe machine voor ${form.name}`">
        <AddAssetForm :customerId="customer.id" :allProducts="allProducts" :products-use-ajax="productsUseAjax"
            :bare="true" :required-productables-by-product="requiredProductablesByProduct"
            @created="addAssetDrawerOpen = false" />
    </DrawerComponent>
</template>

<script setup>
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import {
    BuildingOffice2Icon, ClipboardDocumentListIcon, PlusIcon, PuzzlePieceIcon,
    XCircleIcon, FolderIcon, UserIcon, MapPinIcon, EnvelopeIcon,
    PhoneIcon, DevicePhoneMobileIcon, BanknotesIcon, IdentificationIcon, GlobeAltIcon,
    ReceiptPercentIcon, BuildingLibraryIcon, ChevronRightIcon, ClockIcon,
    UsersIcon, EllipsisVerticalIcon, TrashIcon, LinkSlashIcon, ArrowRightIcon, CalendarDaysIcon, ShieldCheckIcon,
} from '@heroicons/vue/24/outline';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import AddButton from '@/Components/UI/AddButton.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import CurrencyInput from '@/Components/UI/CurrencyInput.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import CircularCounter from '@/Components/UI/CircularCounter.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { hasPermission, nlDate, projectStatusClass, mapsLinkFromCustomer, initials, maintenanceContractStatusText, maintenanceContractStatusBadgeColor, maintenanceContractStatusMeta } from '@/Utilities/Utilities';
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue';
import EventTimelineComponent from '@/Components/Timeline/EventTimelineComponent.vue';
import AddAssetForm from '@/Components/AddAssetForm.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';
import { useComboSearch } from '@/Composables/useComboSearch';
import OpenStreetMapWidget from '@/Components/OpenStreetMapWidget.vue';
import CustomerLocationsWidget from '@/Components/Locations/CustomerLocationsWidget.vue';
import TitleValueIconComponent from '@/Components/UI/TitleValueIconComponent.vue';
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue';
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue';
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue';
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue';
import ChapterContents from '@/Components/Chapters/ChapterContents.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    assets: {
        type: Array,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
    customersUseAjax: { type: Boolean, default: false },
    allProducts: {
        type: Array,
        required: true,
    },
    productsUseAjax: { type: Boolean, default: false },
    users: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    requiredProductablesByProduct: {
        type: Object,
        default: () => ({}),
    },
    contractIntervalOptions: {
        type: Array,
        default: () => [],
    },
});

const addAssetDrawerOpen = ref(false);

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const form = useForm({
    name: props.customer.name,
    contactname: props.customer.contactname,
    email: props.customer.email,
    phone: props.customer.phone,
    mobile: props.customer.mobile,
    website: props.customer.website,
    address: props.customer.address,
    postal_code: props.customer.postal_code,
    city: props.customer.city,
    country: props.customer.country,
    postal_address: props.customer.postal_address,
    postal_postal_code: props.customer.postal_postal_code,
    postal_city: props.customer.postal_city,
    postal_country: props.customer.postal_country,
    invoice_email: props.customer.invoice_email,
    quotes_email: props.customer.quotes_email,
    iban: props.customer.iban,
    vat_number: props.customer.vat_number,
    chamber_of_commerce_number: props.customer.chamber_of_commerce_number,
    billing_customer_id: props.customer.billing_customer_id || null,
});

watch([
    () => form.name,
    () => form.contactname,
    () => form.email,
    () => form.phone,
    () => form.mobile,
    () => form.website,
    () => form.address,
    () => form.postal_code,
    () => form.city,
    () => form.country,
    () => form.postal_address,
    () => form.postal_postal_code,
    () => form.postal_city,
    () => form.postal_country,
    () => form.invoice_email,
    () => form.quotes_email,
    () => form.iban,
    () => form.vat_number,
    () => form.chamber_of_commerce_number,
], () => {
    form.patch(`/customers/${props.customer.id}`, { preserveScroll: true });
});

const newServiceOrderForm = useForm({
    customer_id: props.customer.id,
});

const projectFormRef = ref(null)

const showContactDrawer = ref(false)

const newContactForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    mobile: '',
    customer_id: props.customer.id,
})

function submitNewContact() {
    newContactForm.post('/contacts', {
        preserveScroll: true,
        onSuccess: () => {
            showContactDrawer.value = false
            newContactForm.reset()
            newContactForm.customer_id = props.customer.id
        },
    })
}

function closeContactDrawer() {
    showContactDrawer.value = false
    newContactForm.reset()
    newContactForm.clearErrors()
    newContactForm.customer_id = props.customer.id
}

const contactPreviewCount = 2
const showAllContacts = ref(false)
const visibleContacts = computed(() =>
    showAllContacts.value
        ? props.customer.contacts
        : (props.customer.contacts ?? []).slice(0, contactPreviewCount)
)

function detachContact(contact) {
    router.patch(`/contacts/${contact.id}`, { customer_id: null }, { preserveScroll: true })
}

function deleteContact(contact) {
    if (!confirm(`Weet je zeker dat je ${contact.full_name} volledig wilt verwijderen?`)) return
    router.delete(`/contacts/${contact.id}`, { preserveScroll: true })
}

const canCreateMaintenanceContract = computed(() => hasPermission('maintenancecontract.create'))

const contractStatusOrder = ['actief', 'toekomstig', 'verlopen', 'geannuleerd']

const contractCounts = computed(() => {
    const counts = {}
    for (const contract of props.customer.maintenance_contracts || []) {
        counts[contract.status] = (counts[contract.status] || 0) + 1
    }
    return counts
})

const contractDonutSegments = computed(() =>
    contractStatusOrder
        .map(status => ({ color: maintenanceContractStatusMeta[status].color, value: contractCounts.value[status] || 0 }))
        .filter(segment => segment.value > 0)
)

const contractStatusLegend = computed(() =>
    contractStatusOrder
        .filter(status => status !== 'geannuleerd' || (contractCounts.value.geannuleerd || 0) > 0)
        .map(status => ({
            status,
            label: maintenanceContractStatusMeta[status].label,
            color: maintenanceContractStatusMeta[status].color,
            count: contractCounts.value[status] || 0,
        }))
)

const contractPreviewCount = 2
const showAllContracts = ref(false)
const visibleContracts = computed(() => {
    const contracts = props.customer.maintenance_contracts || []
    return showAllContracts.value ? contracts : contracts.slice(0, contractPreviewCount)
})

const collapseDurationMs = 250

function clearCollapse(el) {
    if (el._collapseTimer) {
        window.clearTimeout(el._collapseTimer)
        el._collapseTimer = null
    }
}

function resetCollapse(el) {
    el.style.height = ''
    el.style.opacity = ''
    el.style.overflow = ''
    el.style.transition = ''
}

function collapseEnter(el, done) {
    clearCollapse(el)
    el.style.overflow = 'hidden'
    el.style.height = '0'
    el.style.opacity = '0'
    void el.offsetHeight
    el.style.transition = `height ${collapseDurationMs}ms ease, opacity ${collapseDurationMs}ms ease`
    el.style.height = el.scrollHeight + 'px'
    el.style.opacity = '1'
    el._collapseTimer = window.setTimeout(() => {
        el._collapseTimer = null
        done()
    }, collapseDurationMs)
}

function collapseLeave(el, done) {
    clearCollapse(el)
    el.style.overflow = 'hidden'
    el.style.height = el.scrollHeight + 'px'
    el.style.opacity = '1'
    void el.offsetHeight
    el.style.transition = `height ${collapseDurationMs}ms ease, opacity ${collapseDurationMs}ms ease`
    el.style.height = '0'
    el.style.opacity = '0'
    el._collapseTimer = window.setTimeout(() => {
        el._collapseTimer = null
        done()
    }, collapseDurationMs)
}

function collapseCancelled(el) {
    clearCollapse(el)
    resetCollapse(el)
}

function collapseAfterEnter(el) {
    resetCollapse(el)
}
const showMaintenanceContractDrawer = ref(false)

// comboBoxArray() gives {id: case-name, name: case-value}; the model casts by
// value, so both id and name must be the value for direct v-model binding.
const maintenanceContractIntervalOptions = computed(() =>
    (props.contractIntervalOptions || []).map(o => ({ id: o.name, name: o.name }))
)

const newMaintenanceContractForm = useForm({
    customer_id: props.customer.id,
    title: '',
    start_date: '',
    end_date: '',
    price: null,
    price_interval: 'Maandelijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Jaarlijks',
    frequency_days: null,
})

function submitNewMaintenanceContract() {
    newMaintenanceContractForm.post('/maintenancecontracts', {
        preserveScroll: true,
        onSuccess: () => {
            showMaintenanceContractDrawer.value = false
            newMaintenanceContractForm.reset()
            newMaintenanceContractForm.customer_id = props.customer.id
        },
    })
}

function closeMaintenanceContractDrawer() {
    showMaintenanceContractDrawer.value = false
    newMaintenanceContractForm.reset()
    newMaintenanceContractForm.clearErrors()
    newMaintenanceContractForm.customer_id = props.customer.id
}

const canCreateServiceOrder = computed(() => hasPermission('serviceorder.create'));
const canCreateProject = computed(() => hasPermission('project.create'));
const canUpdate = computed(() => hasPermission('customer.update'))
const canReadAssets = computed(() => hasPermission('asset.read'))

const updateBillingCustomer = () => {
    form.patch(`/customers/${props.customer.id}`, { preserveScroll: true })
};

const clearBillingCustomer = () => {
    form.billing_customer_id = null;
    updateBillingCustomer();
};

const selectedProductTypeIds = ref([]);
const selectedLocationIds = ref([]);

const productTypeOptions = computed(() => {
    const map = new Map();
    (props.assets || []).forEach(a => {
        const pt = a?.product?.product_type;
        if (pt && !map.has(pt.id)) map.set(pt.id, { id: pt.id, name: pt.name });
    });
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
});

const locationFilterOptions = computed(() => {
    const map = new Map();
    let hasNone = false;
    (props.assets || []).forEach(a => {
        if (a.linked_location) {
            if (!map.has(a.linked_location.id)) map.set(a.linked_location.id, { id: a.linked_location.id, name: a.linked_location.title });
        } else {
            hasNone = true;
        }
    });
    const opts = Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
    if (hasNone) opts.push({ id: 'none', name: 'Geen locatie' });
    return opts;
});

const selectedProductTypes = computed(() => {
    const optionMap = Object.fromEntries(productTypeOptions.value.map(o => [o.id, o]));
    return selectedProductTypeIds.value.map(id => optionMap[id]).filter(Boolean);
});

const selectedLocations = computed(() => {
    const optionMap = Object.fromEntries(locationFilterOptions.value.map(o => [o.id, o]));
    return selectedLocationIds.value.map(id => optionMap[id]).filter(Boolean);
});

const removeProductType = (id) => {
    selectedProductTypeIds.value = selectedProductTypeIds.value.filter(x => x !== id);
};
const removeLocation = (id) => {
    selectedLocationIds.value = selectedLocationIds.value.filter(x => x !== id);
};
const resetFilters = () => { selectedProductTypeIds.value = []; selectedLocationIds.value = []; };

const hasActiveFilters = computed(() => selectedProductTypeIds.value.length > 0 || selectedLocationIds.value.length > 0);

const assetsFiltered = computed(() => {
    let result = props.assets || [];
    if (selectedProductTypeIds.value.length) {
        result = result.filter(a => selectedProductTypeIds.value.includes(a?.product?.product_type?.id));
    }
    if (selectedLocationIds.value.length) {
        result = result.filter(a => selectedLocationIds.value.includes(a.linked_location?.id ?? 'none'));
    }
    return result;
});

const hasAssetsFiltered = computed(() => assetsFiltered.value.length > 0);

const sortedProjects = computed(() => {
    return (props.customer.projects || []).slice().sort((a, b) => {
        if (!a.start_date && !b.start_date) return 0
        if (!a.start_date) return 1
        if (!b.start_date) return -1
        return a.start_date.localeCompare(b.start_date)
    })
})

const serviceOrdersWithoutProject = computed(() => {
    return (props.customer.service_orders || []).filter(serviceorder => !serviceorder.project_id)
})

const projectFields = [
    { key: 'customer_id', type: 'number', default: props.customer.id, label: '', class: 'hidden' },
    { key: 'title', label: 'Titel', type: 'text', class: 'md:col-span-4' },
    { key: 'project_manager_id', label: 'Projectleider', type: 'combobox', options: props.users, initialId: props.users[0]?.id, class: 'md:col-span-4' },
    { key: 'status', label: 'Status', type: 'combobox', options: props.statuses, initialId: props.statuses[0]?.id, emitValue: true, class: 'md:col-span-4' },
    { key: 'start_date', label: 'Startdatum', type: 'date', class: 'md:col-span-4' },
    { key: 'end_date', label: 'Einddatum', type: 'date', class: 'md:col-span-4' },
    { key: 'description', label: 'Omschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
]

const eventList = computed(() => {
    const orders = props.customer.service_orders || [];
    return orders.flatMap(o => (o.events || []).map(e => ({
        ...e,
        service_order_id: o.id,
    })));
});

const chapters = computed(() => [
    'Overzicht',
    `Machines (${props.assets.length})`,
    `Werkbonnen & Projecten (${sortedProjects.value.length + serviceOrdersWithoutProject.value.length})`,
    `Afspraken (${eventList.value.length})`,
])
</script>
