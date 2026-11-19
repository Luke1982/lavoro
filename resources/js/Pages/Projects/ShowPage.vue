<template>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div class="flex items-center gap-2 min-w-0">
            <span class="rounded-md bg-gray-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:text-slate-300 tabular-nums">
                {{ project.number }}
            </span>
            <span :class="projectStatusClass(project.status)" class="text-xs font-medium">{{ project.status }}</span>
        </div>
        <div class="flex items-center gap-2">
            <ExcelExportButton :url="`/projects/${project.id}/export`" />
            <AssistantButton />
        </div>
    </div>
    <div v-if="isClosed" class="mb-4 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-700/60 dark:bg-amber-900/25 dark:text-amber-200">
        <ExclamationTriangleIcon class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
        Dit project is {{ project.status.toLowerCase() }}: er kunnen geen werkbonnen meer onder. Zet de status terug op
        Gestart om er weer werk aan toe te voegen.
    </div>
    <ChaptersComponent>
        <ChapterHeaders>
            <ChapterHeader v-for="(chapter, index) in chapters" :key="index" :index="index">
                {{ chapter }}
            </ChapterHeader>
        </ChapterHeaders>
        <ChapterContents>
            <!-- Chapter 0: Project -->
            <template #chapter-0>
            <TwoThirdsOneThird>
                <template #main>
                    <BoxComponent>
                        <SectionHeader :icon="DocumentTextIcon" title="Details"
                            subtitle="Titel, looptijd en locatie van dit project." chapter="details" />
                        <StepsProgressBar :steps="statuses" v-model="form.status"
                            class="mb-4 border-b border-gray-200 dark:border-slate-700 pb-4"
                            :class="{ 'pointer-events-none': !canUpdate }" />
                        <div class="grid grid-cols-12 gap-0 sm:gap-4">
                            <div class="col-span-12 md:col-span-2 text-slate-400">
                                <span class="text-xs font-bold">Titel</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField v-model="form.title" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Omschrijving</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField v-model="form.description" type="textarea" class="w-full" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Locatie</span>
                            </div>
                            <div class="col-span-12 md:col-span-10">
                                <EditableTextField type="combobox" v-model="form.location_id" :options="locationOptions"
                                    :empty-message="locationMessage" :error="form.errors.location_id"
                                    @revert="form.clearErrors('location_id')" :readonly="!canUpdate">
                                    <template #display>
                                        <Link v-if="selectedLocation" :href="`/locations/${selectedLocation.id}`"
                                            class="text-lavoro-blue dark:text-blue-400 hover:underline">
                                            {{ selectedLocation.name ?? selectedLocation.title }}
                                        </Link>
                                        <span v-else class="text-gray-400 dark:text-slate-500">Geen locatie van de klant (vrije invoer)</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <template v-if="!form.location_id">
                                <div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                    <span class="text-xs font-bold">Vrije locatie</span>
                                </div>
                                <div class="col-span-12 md:col-span-10">
                                    <EditableTextField v-model="form.location" class="w-full" :readonly="!canUpdate"
                                        placeholder="Adres waar het werk gebeurt" />
                                </div>
                            </template>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Klant</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10">
                                <EditableTextField type="combobox" v-model="form.customer_id" :options="customers"
                                    :error="form.errors.customer_id" @revert="form.clearErrors('customer_id')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <Link v-if="project.customer" :href="`/customers/${project.customer.id}`"
                                            class="text-lavoro-blue dark:text-blue-400 hover:underline">
                                            {{ project.customer.name }}
                                        </Link>
                                        <span v-else class="text-gray-400">Selecteer klant</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Contact</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10">
                                <EditableTextField type="combobox" v-model="form.contact_id" :options="contacts"
                                    empty-message="Deze klant heeft nog geen contactpersonen. Je voegt ze toe bij de klant."
                                    :error="form.errors.contact_id" @revert="form.clearErrors('contact_id')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <span v-if="project.contact">{{ project.contact.full_name }}</span>
                                        <span v-else-if="project.customer?.contactname">
                                            {{ project.customer.contactname }}
                                            <span class="text-gray-400 dark:text-slate-500">· contactpersoon van de klant</span>
                                        </span>
                                        <span v-else class="text-gray-400 dark:text-slate-500">Geen contactpersoon</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Projectleider</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField type="combobox" v-model="form.project_manager_id" :options="users"
                                    :error="form.errors.project_manager_id" @revert="form.clearErrors('project_manager_id')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <span v-if="project.project_manager">{{ project.project_manager.name }}</span>
                                        <span v-else class="text-gray-400">Selecteer projectleider</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Startdatum</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField v-model="form.start_date" type="input" input-type="date" class="w-full"
                                    :error="form.errors.start_date" @revert="form.clearErrors('start_date')" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Einddatum</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField v-model="form.end_date" type="input" input-type="date" class="w-full"
                                    :error="form.errors.end_date" @revert="form.clearErrors('end_date')" :readonly="!canUpdate" />
                            </div>
                            <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                <span class="text-xs font-bold">Begrote uren</span>
                            </div>
                            <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                <EditableTextField v-model="form.budget_hours" type="input" input-type="number" class="w-full"
                                    :error="form.errors.budget_hours" @revert="form.clearErrors('budget_hours')"
                                    :readonly="!canUpdate">
                                    <template #display>
                                        <span v-if="project.budget_hours !== null">{{ nlDecimal(project.budget_hours) }} uur</span>
                                        <span v-else class="text-gray-400 dark:text-slate-500">Niet begroot</span>
                                    </template>
                                </EditableTextField>
                            </div>
                            <template v-if="canManageFinancials">
                                <div class="col-span-12 sm:col-span-2 mt-2 sm:mt-0 text-slate-400">
                                    <span class="text-xs font-bold">Begroot bedrag</span>
                                </div>
                                <div class="col-span-12 sm:col-span-10 md:col-span-4">
                                    <EditableTextField v-model="form.budget_amount" type="input" input-type="currency"
                                        class="w-full" :error="form.errors.budget_amount"
                                        @revert="form.clearErrors('budget_amount')" :readonly="!canUpdate">
                                        <template #display>
                                            <span v-if="project.budget_amount !== null">{{ nlCurrency(project.budget_amount) }}</span>
                                            <span v-else class="text-gray-400 dark:text-slate-500">Niet begroot</span>
                                        </template>
                                    </EditableTextField>
                                </div>
                            </template>
                        </div>
                    </BoxComponent>

                    <BoxComponent class="mt-4">
                        <SectionHeader :icon="ChartBarIcon" title="Voortgang"
                            subtitle="Gerekend zoals de Excel-export van dit project." chapter="details" border />
                        <ProjectProgress :progress="progress" />
                    </BoxComponent>
                </template>
                <template #sidebar>
                    <div class="h-full flex flex-col">
                        <BoxComponent v-if="project.customer" class="mt-4 sm:mt-0">
                            <SectionHeader :icon="BuildingOfficeIcon" title="Klant"
                                subtitle="De opdrachtgever van dit project." chapter="customer" border />
                            <dl class="space-y-2 text-sm">
                                <div>
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Naam</dt>
                                    <dd class="text-gray-800 dark:text-slate-200 font-medium">
                                        <Link :href="`/customers/${project.customer.id}`"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.name }}
                                        </Link>
                                    </dd>
                                </div>
                                <div v-if="project.contact">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Contactpersoon voor dit project</dt>
                                    <dd class="text-gray-800 dark:text-slate-200">
                                        {{ project.contact.full_name }}
                                        <a v-if="project.contact.phone || project.contact.mobile"
                                            :href="'tel:' + (project.contact.mobile || project.contact.phone)"
                                            class="block text-indigo-600 dark:text-indigo-400 hover:underline">
                                            {{ project.contact.mobile || project.contact.phone }}
                                        </a>
                                        <a v-if="project.contact.email" :href="'mailto:' + project.contact.email"
                                            class="block text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.contact.email }}</a>
                                    </dd>
                                </div>
                                <div v-if="project.customer.contactname">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Contactpersoon klant</dt>
                                    <dd class="text-gray-800 dark:text-slate-200">{{ project.customer.contactname }}</dd>
                                </div>
                                <div v-if="project.customer.email">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">E-mail</dt>
                                    <dd><a :href="'mailto:' + project.customer.email"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.email
                                            }}</a></dd>
                                </div>
                                <div v-if="project.customer.phone">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Telefoon</dt>
                                    <dd><a :href="'tel:' + project.customer.phone"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.phone
                                            }}</a></dd>
                                </div>
                                <div v-if="project.customer.mobile">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Mobiel</dt>
                                    <dd><a :href="'tel:' + project.customer.mobile"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ project.customer.mobile
                                            }}</a></dd>
                                </div>
                                <div v-if="project.customer.address">
                                    <dt class="text-[11px] text-gray-500 dark:text-slate-500">Adres</dt>
                                    <dd>
                                        <a :href="mapsLinkFromCustomer(project.customer)" target="_blank"
                                            class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                            {{ project.customer.address }}<br>
                                            {{ project.customer.postal_code }} {{ project.customer.city }}
                                        </a>
                                    </dd>
                                </div>
                            </dl>
                        </BoxComponent>

                        <BoxComponent class="grow mt-4">
                            <SectionHeader :icon="FlagIcon" title="Mijlpalen"
                                subtitle="De ijkpunten waarlangs dit project vordert." chapter="milestones" border />
                            <div v-if="canCreateMilestone" class="-mt-1 mb-4 flex items-center justify-end gap-1.5">
                                <DropdownMenu v-if="templates.length" placement="bottom-end" width-class="w-56"
                                    title="Mijlpalen uit een sjabloon"
                                    button-class="inline-flex items-center gap-1 rounded border border-gray-300 dark:border-slate-600 px-2 py-1.5 text-xs font-semibold text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800 cursor-pointer">
                                    <template #button>
                                        Uit sjabloon
                                        <ChevronDownIcon class="size-3.5" />
                                    </template>
                                    <MenuItem v-for="template in templates" :key="template.id" v-slot="{ active }">
                                        <button type="button" @click="addMilestonesFromTemplate(template)"
                                            :class="['block w-full px-3 py-2 text-left text-sm text-gray-700 dark:text-slate-200 cursor-pointer', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                                            {{ template.name }}
                                        </button>
                                    </MenuItem>
                                </DropdownMenu>
                                <button type="button" @click="showMilestoneDrawer = true"
                                    class="px-2 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90 inline-flex items-center gap-1 cursor-pointer">
                                    <PlusIcon class="size-3.5" />
                                    Toevoegen
                                </button>
                            </div>

                            <div v-if="!sortedMilestones.length" class="text-xs text-gray-500 dark:text-slate-500">
                                Nog geen mijlpalen
                            </div>
                            <div v-else class="flow-root">
                                <ul role="list" class="-mb-5" v-auto-animate>
                                    <li v-for="(ms, idx) in sortedMilestones" :key="ms.id">
                                        <div class="relative pb-5">
                                            <span v-if="idx !== sortedMilestones.length - 1"
                                                class="absolute top-3 left-3 -ml-px h-full w-0.5 bg-gray-200 dark:bg-slate-700/60"
                                                aria-hidden="true" />
                                            <div class="relative flex space-x-3">
                                                <div>
                                                    <span v-if="!canUpdateMilestone"
                                                        :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm']">
                                                        <CheckIcon v-if="ms.actual_date" class="size-4 text-white" />
                                                        <ClockIcon v-else class="size-4 text-white" />
                                                    </span>
                                                    <button v-else type="button"
                                                        v-tooltip="ms.actual_date ? 'Markeer als niet afgerond' : 'Markeer als afgerond met vandaag als werkelijke datum'"
                                                        @click="toggleMilestoneComplete(ms)"
                                                        :class="[milestoneColor(ms), 'flex size-7 items-center justify-center rounded-full border border-white dark:border-slate-700 shadow-sm cursor-pointer hover:brightness-110']">
                                                        <CheckIcon v-if="ms.actual_date" class="size-4 text-white" />
                                                        <ClockIcon v-else class="size-4 text-white" />
                                                    </button>
                                                </div>
                                                <div class="min-w-0 flex-1 pt-1">
                                                    <div class="flex items-start justify-between">
                                                        <div>
                                                            <p class="text-sm font-medium text-gray-800 dark:text-slate-200">{{
                                                                ms.title }}</p>
                                                            <p v-if="ms.assigned_user"
                                                                class="mt-0.5 text-xs text-gray-500 dark:text-slate-500 flex items-center gap-1">
                                                                <UserIcon class="size-3" />
                                                                {{ ms.assigned_user.name }}
                                                            </p>
                                                        </div>
                                                        <div class="flex items-center gap-1.5 ml-2 shrink-0">
                                                            <PencilSquareIcon v-if="canUpdateMilestone"
                                                                class="size-4 text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-slate-200 cursor-pointer"
                                                                @click="toggleEditMilestone(ms)" />
                                                            <TrashIcon v-if="canDeleteMilestone"
                                                                class="size-4 text-red-400 dark:text-red-300 hover:text-red-600 dark:hover:text-red-400 cursor-pointer"
                                                                @click="deleteMilestone(ms.id)" />
                                                        </div>
                                                    </div>
                                                    <div
                                                        class="flex flex-wrap gap-x-3 mt-1 text-[11px] text-gray-500 dark:text-slate-500">
                                                        <span v-if="ms.projected_date" class="flex items-center gap-1">
                                                            <CalendarIcon class="size-3" />Gepland: {{ nlDate(ms.projected_date) }}
                                                        </span>
                                                        <span v-if="ms.actual_date"
                                                            class="text-green-600 dark:text-green-400 flex items-center gap-1">
                                                            <CalendarIcon class="size-3" />Behaald: {{ nlDate(ms.actual_date) }}
                                                        </span>
                                                        <span v-if="ordersOf(ms).length" class="flex items-center gap-1"
                                                            v-tooltip="ordersOf(ms).map(so => formatWbNumber(so.id)).join(', ')">
                                                            <ClipboardDocumentListIcon class="size-3" />
                                                            {{ ordersOf(ms).filter(so => so.is_closed).length }}/{{ ordersOf(ms).length }} werkbonnen gesloten
                                                        </span>
                                                    </div>
                                                    <div v-auto-animate>
                                                        <div v-if="ms.description && editingMilestoneId !== ms.id" key="desc"
                                                            class="mt-1.5">
                                                            <div class="description-clamp"
                                                                :class="{ 'expanded': expandedDescriptions[ms.id] }">
                                                                <p class="text-xs text-gray-600 dark:text-slate-400">
                                                                    {{ ms.description }}
                                                                </p>
                                                            </div>
                                                            <button v-if="ms.description.length > 80"
                                                                @click="expandedDescriptions[ms.id] = !expandedDescriptions[ms.id]"
                                                                class="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline mt-0.5">
                                                                {{ expandedDescriptions[ms.id] ? 'Toon minder' : 'Lees meer' }}
                                                            </button>
                                                        </div>
                                                        <div v-if="editingMilestoneId === ms.id" key="edit"
                                                            class="mt-3 p-3 rounded-md bg-gray-50 dark:bg-slate-800 ring-1 ring-gray-200 dark:ring-slate-700 space-y-3">
                                                            <div>
                                                                <span
                                                                    class="text-xs font-bold text-gray-600 dark:text-slate-400">Titel</span>
                                                                <EditableTextField v-model="editForms[ms.id].title"
                                                                    class="w-full" />
                                                            </div>
                                                            <div>
                                                                <span
                                                                    class="text-xs font-bold text-gray-600 dark:text-slate-400">Omschrijving</span>
                                                                <EditableTextField v-model="editForms[ms.id].description"
                                                                    type="textarea" class="w-full" />
                                                            </div>
                                                            <div>
                                                                <span
                                                                    class="text-xs font-bold text-gray-600 dark:text-slate-400">Toegewezen
                                                                    gebruiker</span>
                                                                <EditableTextField type="combobox"
                                                                    v-model="editForms[ms.id].assigned_user_id" :options="users"
                                                                    :error="milestoneForm.errors.assigned_user_id"
                                                                    @revert="milestoneForm.clearErrors('assigned_user_id')">
                                                                    <template #display>
                                                                        <span v-if="ms.assigned_user">{{ ms.assigned_user.name
                                                                            }}</span>
                                                                        <span v-else class="text-gray-400">Selecteer
                                                                            gebruiker</span>
                                                                    </template>
                                                                </EditableTextField>
                                                            </div>
                                                            <div>
                                                                <span
                                                                    class="text-xs font-bold text-gray-600 dark:text-slate-400">Geplande
                                                                    datum</span>
                                                                <EditableTextField v-model="editForms[ms.id].projected_date"
                                                                    type="input" input-type="date" class="w-full" />
                                                            </div>
                                                            <div>
                                                                <span
                                                                    class="text-xs font-bold text-gray-600 dark:text-slate-400">Werkelijke
                                                                    datum</span>
                                                                <EditableTextField v-model="editForms[ms.id].actual_date"
                                                                    type="input" input-type="date" class="w-full" />
                                                            </div>
                                                            <div>
                                                                <span class="text-xs font-bold text-gray-600 dark:text-slate-400">Werkbonnen</span>
                                                                <ComboBox :options="serviceOrderOptions" multiple
                                                                    v-model="editForms[ms.id].service_order_ids"
                                                                    placeholder="Geen werkbonnen gekoppeld"
                                                                    empty-message="Er staan nog geen werkbonnen onder dit project." />
                                                                <p class="mt-1 text-[11px] text-gray-500 dark:text-slate-400">
                                                                    Is de laatste hiervan gesloten, dan is de mijlpaal behaald.
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </BoxComponent>
                    </div>
                </template>
            </TwoThirdsOneThird>

            <BoxComponent class="mt-4">
                <SectionHeader :icon="CalendarIcon" title="Tijdlijn"
                    subtitle="De looptijd van dit project met de mijlpalen erop uitgezet." chapter="timeline" border />
                <ProjectTimeline :project-id="project.id" :project-start-date="project.start_date"
                    :project-end-date="project.end_date" :project-milestones="project.milestones" />
            </BoxComponent>

            <TwoThirdsOneThird class="mt-4">
                <template #main>
                    <DocumentUploadComponent :existing="project.documents" :documentable-id="project.id"
                        documentable-type="\App\Models\Project" class="h-full" />
                </template>
                <template #sidebar>
                    <div class="h-full flex flex-col">
                        <BoxComponent class="mt-4 md:mt-0">
                            <ImageUploadComponent :existing="project.images" :imageable-id="project.id"
                                imageable-type="\App\Models\Project" />
                        </BoxComponent>

                        <BoxComponent class="mt-4 grow"
                            v-tray-drop="{ target: { type: 'project', id: project.id }, label: 'een werkbon onder dit project te maken', accepts: isClosed ? [] : undefined }">
                            <SectionHeader :icon="ClipboardDocumentListIcon" title="Werkbonnen"
                                subtitle="Het werk dat onder dit project wordt uitgevoerd." chapter="serviceorders" border>
                                <template v-if="canCreateServiceOrder && !isClosed" #actions>
                                    <button @click="createServiceOrder"
                                        class="px-3 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90">
                                        Werkbon aanmaken
                                    </button>
                                </template>
                            </SectionHeader>
                            <div v-auto-animate>
                                <div v-if="!project.service_orders?.length" key="empty"
                                    class="text-xs text-gray-500 dark:text-slate-500">
                                    Nog geen werkbonnen
                                </div>
                                <ServiceOrderList v-else :serviceorders="project.service_orders"
                                    :stages="serviceOrderStages" />
                            </div>
                        </BoxComponent>
                    </div>
                </template>
            </TwoThirdsOneThird>

            <BoxComponent class="mt-4">
                <SectionHeader :icon="ClockIcon" title="Geschiedenis"
                    subtitle="Wie wat veranderde aan dit project, zijn mijlpalen en zijn werkbonnen." chapter="timeline" border />
                <TimelineComponent v-if="project.activities?.length" :activities="project.activities" />
                <p v-else class="text-xs text-gray-500 dark:text-slate-500">Nog niets gebeurd.</p>
            </BoxComponent>
            </template>

            <!-- Chapter 1: Administratie -->
            <template v-if="canManageFinancials" #chapter-1>
                <BoxComponent>
                    <SectionHeader :icon="CalculatorIcon" title="Administratie"
                        subtitle="Financiële aantekeningen bij dit project." chapter="financial" border>
                        <template #actions>
                            <span class="text-[11px]"
                                :class="financialNotesError ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-slate-500'">
                                {{ financialNotesStatus }}
                            </span>
                        </template>
                    </SectionHeader>
                    <div v-if="financialNotesIsStale"
                        class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700/60 dark:bg-amber-900/25">
                        <div class="flex items-center gap-2">
                            <ExclamationTriangleIcon class="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                <span class="font-semibold">{{ financialNotesStaleBy || 'Iemand anders' }}</span>
                                heeft deze administratie inmiddels gewijzigd. Als je nu opslaat overschrijf je die
                                wijzigingen. Herlaad om de nieuwste versie te zien — niet-opgeslagen wijzigingen gaan
                                dan verloren.
                            </p>
                        </div>
                        <button type="button" @click="reloadFinancialNotes"
                            class="shrink-0 rounded bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">
                            Herladen
                        </button>
                    </div>
                    <SpreadsheetComponent v-model="financialNotes" :min-dimensions="[6, 20]" />
                </BoxComponent>
            </template>
        </ChapterContents>
    </ChaptersComponent>

    <DrawerComponent v-model="showMilestoneDrawer" max-width-class="max-w-2xl" title="Nieuwe mijlpaal"
        subtitle="Vul onderstaande velden in om een mijlpaal aan dit project toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center p-4">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.title" type="text"
                        :hasError="Boolean(newMilestoneForm.errors.title)"
                        :errorMessage="newMilestoneForm.errors.title" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-start">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200 sm:pt-2">Omschrijving</label>
                <div class="sm:col-span-2">
                    <textarea v-model="newMilestoneForm.description" rows="4"
                        class="block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 text-sm p-2"
                        placeholder="Optioneel"></textarea>
                    <p v-if="newMilestoneForm.errors.description" class="text-red-600 dark:text-red-400 text-sm mt-1">
                        {{ newMilestoneForm.errors.description }}
                    </p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Toegewezen gebruiker</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="users" v-model="newMilestoneForm.assigned_user_id"
                        placeholder="Selecteer gebruiker" :hasError="Boolean(newMilestoneForm.errors.assigned_user_id)"
                        :errorMessage="newMilestoneForm.errors.assigned_user_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Geplande datum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.projected_date" type="date"
                        :hasError="Boolean(newMilestoneForm.errors.projected_date)"
                        :errorMessage="newMilestoneForm.errors.projected_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Werkelijke datum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newMilestoneForm.actual_date" type="date"
                        :hasError="Boolean(newMilestoneForm.errors.actual_date)"
                        :errorMessage="newMilestoneForm.errors.actual_date" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeMilestoneDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitNewMilestone" :disabled="newMilestoneForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Aanmaken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { vTrayDrop } from '@/Utilities/trayDrop.js'
import { ref, reactive, watch, computed, nextTick } from 'vue'
import AssistantButton from '@/Components/Assistant/AssistantButton.vue'
import ExcelExportButton from '@/Components/UI/ExcelExportButton.vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import ProjectProgress from '@/Components/Projects/ProjectProgress.vue'
import { useCustomerLocations } from '@/Composables/useCustomerLocations'
import axios from 'axios'
import BoxComponent from '@/Components/BoxComponent.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import { ChartBarIcon, ChevronDownIcon, ClipboardDocumentListIcon, DocumentTextIcon, FlagIcon, PencilSquareIcon, PlusIcon, TrashIcon, CheckIcon, ClockIcon, UserIcon, BuildingOfficeIcon, CalendarIcon, CalculatorIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import SpreadsheetComponent from '@/Components/UI/SpreadsheetComponent.vue'
import StepsProgressBar from '@/Components/UI/StepsProgressBar.vue'
import ServiceOrderList from '@/Components/ServiceOrderList.vue'
import ProjectTimeline from '@/Components/Projects/ProjectTimeline.vue'
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import { formatLocalDateAsISO, formatWbNumber, nlCurrency, nlDate, nlDecimal, nlTime, mapsLinkFromCustomer, hasPermission, projectStatusClass } from '@/Utilities/Utilities'
import { useFinancialNotesFreshness } from '@/Composables/useFinancialNotesFreshness'

const props = defineProps({
    project: { type: Object, required: true },
    customers: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    serviceOrderStages: { type: Array, default: () => [] },
    progress: { type: Object, required: true },
    /** The contact persons of the project's customer, as { id, name }. */
    contacts: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
})

const initialStatus = props.statuses.find(s => s.name === props.project.status)

const sortedMilestones = computed(() =>
    (props.project.milestones || []).slice().sort((a, b) => {
        const dateA = a.projected_date || a.actual_date || ''
        const dateB = b.projected_date || b.actual_date || ''
        return dateA.localeCompare(dateB)
    })
)

const canUpdate = computed(() => hasPermission('project.update'))
const canCreateServiceOrder = computed(() => hasPermission('serviceorder.create'))
const canCreateMilestone = computed(() => hasPermission('projectmilestone.create'))
const canUpdateMilestone = computed(() => hasPermission('projectmilestone.update'))
const canDeleteMilestone = computed(() => hasPermission('projectmilestone.delete'))
const canManageFinancials = computed(() => hasPermission('project.manage_financials'))
const isClosed = computed(() => props.project.is_closed)

const chapters = computed(() => canManageFinancials.value ? ['Project', 'Administratie'] : ['Project'])

function milestoneColor(ms) {
    if (ms.actual_date) return 'bg-green-600'
    if (!ms.projected_date) return 'bg-gray-400'
    const today = new Date().toISOString().substring(0, 10)
    if (ms.projected_date < today) return 'bg-red-500'
    return 'bg-blue-600'
}

const expandedDescriptions = reactive({})

const form = useForm({
    title: props.project.title,
    description: props.project.description,
    location: props.project.location ?? null,
    location_id: props.project.location_id ?? null,
    contact_id: props.project.contact_id ?? null,
    start_date: props.project.start_date?.substring(0, 10) ?? null,
    end_date: props.project.end_date?.substring(0, 10) ?? null,
    customer_id: props.project.customer_id,
    project_manager_id: props.project.project_manager_id,
    budget_hours: props.project.budget_hours ?? null,
    budget_amount: props.project.budget_amount ?? null,
    status: initialStatus?.id ?? props.project.status,
})

/**
 * Set while the form takes over what the server decided by itself, such as the
 * location and contact person it let go when the customer changed. Those values
 * are already stored; sending them back would be a second save of nothing.
 */
let syncingFromServer = false

function patchField(field, value) {
    if (syncingFromServer) return
    form.transform(() => ({
        [field]: value === '' ? null : value,
    })).patch(`/projects/${props.project.id}`, {
        preserveScroll: true,
    })
}

const editableFields = ['title', 'description', 'location', 'location_id', 'contact_id', 'start_date', 'end_date',
    'customer_id', 'project_manager_id', 'budget_hours', ...(canManageFinancials.value ? ['budget_amount'] : [])]

editableFields.forEach(field => watch(() => form[field], val => patchField(field, val)))

watch(() => form.status, (val) => {
    const statusName = props.statuses.find(s => s.id === val)?.name || val
    patchField('status', statusName)
})

watch(() => [props.project.location_id, props.project.contact_id], ([locationId, contactId]) => {
    syncingFromServer = true
    form.location_id = locationId ?? null
    form.contact_id = contactId ?? null
    nextTick(() => { syncingFromServer = false })
})

const { locations: locationOptions, load: loadLocations, message: locationMessage } = useCustomerLocations()

watch(() => props.project.customer_id, loadLocations, { immediate: true })

const selectedLocation = computed(() =>
    locationOptions.value.find(location => location.id === form.location_id)
    ?? (props.project.linked_location?.id === form.location_id ? props.project.linked_location : null)
)

const showMilestoneDrawer = ref(false)
const newMilestoneForm = useForm({
    project_id: props.project.id,
    title: '',
    description: '',
    assigned_user_id: null,
    projected_date: null,
    actual_date: null,
})

function submitNewMilestone() {
    newMilestoneForm.post('/projectmilestones', {
        preserveScroll: true,
        onSuccess: () => {
            showMilestoneDrawer.value = false
            newMilestoneForm.reset()
        },
    })
}

function closeMilestoneDrawer() {
    showMilestoneDrawer.value = false
    newMilestoneForm.reset()
    newMilestoneForm.clearErrors()
}

const editingMilestoneId = ref(null)
const editForms = reactive({})
const milestoneForm = useForm({})
let milestoneWatchStops = []

function patchMilestoneField(milestoneId, field, value) {
    milestoneForm.transform(() => ({ [field]: value })).patch(`/projectmilestones/${milestoneId}`, {
        preserveScroll: true,
    })
}

function ordersOf(ms) {
    return (props.project.service_orders ?? []).filter(so => so.project_milestone_id === ms.id)
}

const serviceOrderOptions = computed(() => (props.project.service_orders ?? []).map(so => ({
    id: so.id,
    name: formatWbNumber(so.id) + (so.description ? ' ' + so.description : ''),
})))

function addMilestonesFromTemplate(template) {
    if (sortedMilestones.value.length
        && !confirm(`Dit project heeft al mijlpalen. De mijlpalen uit '${template.name}' komen erbij. Doorgaan?`)) return
    router.post(`/projects/${props.project.id}/milestones/from-template`, { project_template_id: template.id }, {
        preserveScroll: true,
    })
}

function toggleEditMilestone(ms) {
    if (editingMilestoneId.value === ms.id) {
        editingMilestoneId.value = null
        milestoneWatchStops.forEach(stop => stop())
        milestoneWatchStops = []
        return
    }

    milestoneWatchStops.forEach(stop => stop())
    milestoneWatchStops = []

    editingMilestoneId.value = ms.id

    editForms[ms.id] = reactive({
        title: ms.title,
        description: ms.description,
        projected_date: ms.projected_date?.substring(0, 10) ?? null,
        actual_date: ms.actual_date?.substring(0, 10) ?? null,
        assigned_user_id: ms.assigned_user_id,
        service_order_ids: ordersOf(ms).map(so => so.id),
    })

        ;['title', 'description', 'projected_date', 'actual_date', 'assigned_user_id', 'service_order_ids'].forEach(field => {
            const stop = watch(() => editForms[ms.id]?.[field], (val, oldVal) => {
                if (JSON.stringify(val) === JSON.stringify(oldVal)) return
                patchMilestoneField(ms.id, field, val)
            })
            milestoneWatchStops.push(stop)
        })
}

function deleteMilestone(id) {
    if (!confirm('Weet je zeker dat je deze mijlpaal wilt verwijderen?')) return
    milestoneForm.delete(`/projectmilestones/${id}`, { preserveScroll: true })
}

function toggleMilestoneComplete(ms) {
    patchMilestoneField(ms.id, 'actual_date', ms.actual_date ? null : formatLocalDateAsISO(new Date()))
}

const financialNotes = ref(props.project.financial_notes ?? null)
const financialNotesSaving = ref(false)
const financialNotesError = ref(null)
const financialNotesSavedAt = ref(null)

let financialNotesQueued = null
let financialNotesCsrfReady = false

const {
    isStale: financialNotesIsStale,
    staleByName: financialNotesStaleBy,
    markSaved: markFinancialNotesSaved,
} = useFinancialNotesFreshness(props.project.id, {
    initialSavedAt: props.project.financial_notes_updated_at ?? null,
    isPaused: financialNotesSaving,
    enabled: canManageFinancials.value,
})

function reloadFinancialNotes() {
    window.location.reload()
}

const financialNotesStatus = computed(() => {
    if (financialNotesError.value) return financialNotesError.value
    if (financialNotesSaving.value) return 'Opslaan…'
    if (financialNotesSavedAt.value) return 'Opgeslagen om ' + financialNotesSavedAt.value
    return 'Wijzigingen worden automatisch opgeslagen'
})

async function saveFinancialNotes(grid) {
    if (financialNotesSaving.value) {
        financialNotesQueued = grid
        return
    }

    financialNotesSaving.value = true
    try {
        if (!financialNotesCsrfReady) {
            await axios.get('/sanctum/csrf-cookie')
            financialNotesCsrfReady = true
        }
        const response = await axios.patch(
            `/api/projects/${props.project.id}/financial-notes`,
            { financial_notes: grid }
        )
        financialNotesError.value = null
        financialNotesSavedAt.value = nlTime(response.data.saved_at)
        markFinancialNotesSaved(response.data.saved_at)
    } catch (e) {
        const status = e.response?.status
        if (status === 419 || status === 401) {
            financialNotesCsrfReady = false
        }
        financialNotesError.value = status === 422
            ? 'Opslaan mislukt — de tabel is te groot of bevat ongeldige cellen'
            : 'Opslaan mislukt — je wijzigingen zijn niet bewaard'
    } finally {
        financialNotesSaving.value = false

        if (financialNotesQueued) {
            const next = financialNotesQueued
            financialNotesQueued = null
            saveFinancialNotes(next)
        }
    }
}

watch(financialNotes, (val) => saveFinancialNotes(val))

const serviceOrderForm = useForm({
    customer_id: props.project.customer_id,
    project_id: props.project.id,
})

function createServiceOrder() {
    serviceOrderForm.post('/serviceorders', { preserveScroll: true })
}
</script>

<style scoped>
.description-clamp {
    max-height: 2.8em;
    overflow: hidden;
    transition: max-height 0.35s ease;
}

.description-clamp.expanded {
    max-height: 40em;
}
</style>
