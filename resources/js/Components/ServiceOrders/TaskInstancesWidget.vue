<template>
    <BoxComponent>
        <div class="flex items-center justify-between mb-5">
            <div class="flex">
                <ClipboardListIcon class="size-6 mr-2 flex-none object-cover" />
                <h2 class="text-base font-semibold text-gray-800 dark:text-slate-200">
                    Werkbontaken
                </h2>
            </div>
            <button v-if="canCreate" type="button" @click="addDrawerOpen = true"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-lavoro-blue hover:opacity-80 transition-opacity ml-2 sm:ml-0 justify-center py-3 sm:pt-2 px-3 sm:px-0 border-1 border-gray-200 rounded-lavoro-sm sm:border-0 cursor-pointer">
                <PlusIcon class="w-4 h-4" />
                <span class="hidden sm:inline">Taak toevoegen</span>
            </button>
        </div>

        <div v-auto-animate>
            <div v-if="internalInstances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-2">
                Nog geen taken toegevoegd.
            </div>
            <div v-for="instance in internalInstances" :key="instance.id"
                class="flex items-start gap-3 py-3 border-b border-gray-100 dark:border-slate-800/60 last:border-0">
                <CheckboxComponent :key="`cb-${instance.id}-${checkboxResetKeys[instance.id] ?? 0}`"
                    :model-value="instance.is_complete" :disabled="!canToggle || instance.is_cancelled"
                    @update:modelValue="toggleComplete(instance, $event)" />
                <div class="flex flex-col sm:flex-row justify-between flex-grow gap-y-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">
                            {{ effectiveTitle(instance) }}
                        </p>
                        <p v-if="effectiveDescription(instance)"
                            class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-2">
                            {{ effectiveDescription(instance) }}
                        </p>
                        <p v-if="instance.product" class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                            {{ instance.quantity }}× {{ instance.product.brand.name }} {{ instance.product.model }}
                        </p>
                        <div v-if="serialCounts(instance).expected" class="mt-1">
                            <component :is="canToggle ? 'button' : 'span'" :type="canToggle ? 'button' : null"
                                @click="canToggle && openSerialDrawer(instance)" :class="[
                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold transition-colors',
                                    canToggle ? 'cursor-pointer hover:opacity-80' : '',
                                    serialCounts(instance).filled >= serialCounts(instance).expected
                                        ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400'
                                        : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-400',
                                ]">
                                <ScanBarcodeIcon class="w-3 h-3" />
                                {{ serialCounts(instance).filled }}/{{ serialCounts(instance).expected }} serienummers
                            </component>
                        </div>
                        <div v-if="instance.user_roles?.length" class="flex flex-wrap gap-1 mt-1">
                            <span v-for="role in instance.user_roles" :key="role.id"
                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full text-white"
                                :style="{ backgroundColor: role.color }">
                                {{ role.name }}
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-end gap-x-3 sm:gap-x-1 sm:justify-start items-start">
                        <div class="flex flex-col items-end gap-2 flex-none">
                            <BadgeComponent
                                :color="instance.is_cancelled ? 'red' : instance.is_complete ? 'green' : 'gray'"
                                :has-dot="false">
                                {{ instance.is_cancelled ? 'Geannuleerd' : instance.is_complete ? 'Voltooid' : `In
                                uitvoering` }}
                            </BadgeComponent>
                            <span v-if="instance.is_cancelled && instance.cancellation_reason"
                                class="text-xs text-gray-400 dark:text-gray-300 max-w-40 leading-tigh mr-2">
                                {{ instance.cancellation_reason }}
                            </span>
                        </div>
                        <button v-if="instance.signed_by" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openViewModal(instance)" v-tooltip="'Ondertekend'">
                            <BadgeCheckIcon class="w-4 h-4 text-green-500" />
                        </button>
                        <button v-if="canSign && instance.is_complete && !instance.signed_by" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openSignModal(instance)" v-tooltip="'Laten ondertekenen'">
                            <PenLineIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
                        </button>
                        <button v-if="canCancel && !instance.is_cancelled && !instance.is_complete" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openCancelModal(instance)" v-tooltip="'Annuleer taak'">
                            <BanIcon class="w-4 h-4 text-red-500" />
                        </button>
                        <button v-if="canEdit" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openEditDrawer(instance)" v-tooltip="'Bewerk taak'">
                            <EllipsisVerticalIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
                        </button>
                        <button v-if="canDelete" type="button"
                            class="flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="deleteInstance(instance.id)" v-tooltip="'Verwijder taak'">
                            <TrashIcon class="w-4 h-4 text-red-500" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add drawer -->
        <DrawerComponent v-model="addDrawerOpen" title="Taak toevoegen">
            <div class="p-4 sm:p-6 flex flex-col gap-4">
                <ComboBox :options="taskOptions" v-model="newTaskId" label="Bestaande taak (optioneel)"
                    placeholder="Zoek een taak..." @update:modelValue="onNewTaskSelected" />
                <TextInput v-model="newTitle" label="Titel" placeholder="Laat leeg om de taaknaam te gebruiken" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Omschrijving</label>
                    <textarea v-model="newDescription" rows="3" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <ComboBox :options="productOptions" v-model="newProductId" label="Product (optioneel)"
                    placeholder="Zoek een product...">
                    <template #option="{ option, active }">
                        <div>
                            <span class="block">{{ option.name }}</span>
                            <span v-if="option.attributes?.length"
                                :class="['block text-xs mt-0.5', active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400']">
                                {{option.attributes.map(a => `${a.name}: ${a.value}`).join(' · ')}}
                            </span>
                        </div>
                    </template>
                </ComboBox>
                <div v-if="newProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="newQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <div v-if="userRoles.length">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Rollen (optioneel)</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="role in userRoles" :key="role.id" type="button"
                            @click="toggleRoleId(newUserRoleIds, role.id)" :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                newUserRoleIds.includes(role.id)
                                    ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]">
                            {{ role.name }}
                        </button>
                    </div>
                </div>
                <p v-if="addForm.errors.description || addForm.errors.title" class="text-xs text-red-600">
                    {{ addForm.errors.description || addForm.errors.title }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="addDrawerOpen = false; newUserRoleIds = []"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>

        <!-- Edit drawer -->
        <DrawerComponent v-model="editDrawerOpen" title="Taak bewerken">
            <div class="p-4 sm:p-6 flex flex-col gap-4">
                <TextInput v-model="editTitle" label="Titel" placeholder="Laat leeg om de taaknaam te gebruiken" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Omschrijving</label>
                    <textarea v-model="editDescription" rows="3" placeholder="Omschrijving (optioneel)"
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <ComboBox :options="productOptions" v-model="editProductId" label="Product (optioneel)"
                    placeholder="Zoek een product...">
                    <template #option="{ option, active }">
                        <div>
                            <span class="block">{{ option.name }}</span>
                            <span v-if="option.attributes?.length"
                                :class="['block text-xs mt-0.5', active ? 'text-indigo-100' : 'text-gray-500 dark:text-slate-400']">
                                {{option.attributes.map(a => `${a.name}: ${a.value}`).join(' · ')}}
                            </span>
                        </div>
                    </template>
                </ComboBox>
                <div v-if="editProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="editQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <div v-if="userRoles.length">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Rollen (optioneel)</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="role in userRoles" :key="role.id" type="button"
                            @click="toggleRoleId(editUserRoleIds, role.id)" :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                editUserRoleIds.includes(role.id)
                                    ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]">
                            {{ role.name }}
                        </button>
                    </div>
                </div>
                <p v-if="editForm.errors.title || editForm.errors.description" class="text-xs text-red-600">
                    {{ editForm.errors.title || editForm.errors.description }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="editDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="editForm.processing" @click="saveEdit"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>

        <!-- Serial number drawer -->
        <DrawerComponent v-model="serialDrawerOpen" title="Serienummers invoeren"
            :subtitle="serialInstance ? `Voer de serienummers in voor: ${effectiveTitle(serialInstance)}` : ''"
            max-width-class="max-w-lg">
            <div class="p-4 sm:p-6 space-y-6">
                <p class="text-xs text-gray-500 dark:text-slate-400">
                    Elk serienummer wordt los opgeslagen als machine. Je kunt tussendoor stoppen en later verder gaan.
                </p>
                <template v-for="group in serialGroups" :key="group.product_id">
                    <div>
                        <p class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">
                            {{ group.label }}
                            <span class="text-xs font-normal text-gray-400 ml-1">
                                ({{ group.rows.filter(r => r.asset_id).length }}/{{ group.expected }})
                            </span>
                        </p>
                        <div class="space-y-2">
                            <div v-for="(row, i) in group.rows" :key="row.asset_id ?? `new-${i}`">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400 w-5 shrink-0 text-right">{{ i + 1 }}.</span>
                                    <input v-model="row.serial_number" type="text"
                                        :placeholder="`Serienummer ${i + 1}`" @input="row.error = ''"
                                        :class="['flex-1 min-w-0 rounded-md border-0 py-1.5 px-3 text-sm ring-1 ring-inset focus:ring-2 focus:ring-inset focus:outline-none bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder:text-gray-400', row.error ? 'ring-red-300 focus:ring-red-500' : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600']" />
                                    <ScanSerialButton @picked="row.serial_number = $event; row.error = ''" />
                                    <button v-if="rowIsDirty(row)" type="button" :disabled="serialSubmitting"
                                        @click="saveRow(row)" v-tooltip="'Serienummer opslaan'"
                                        class="flex-none p-1.5 rounded-md bg-lavoro-green text-gray-900 hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                                        <CheckIcon class="w-4 h-4" />
                                    </button>
                                    <span v-else-if="row.asset_id" class="flex-none p-1.5"
                                        v-tooltip="'Opgeslagen als machine'">
                                        <CheckIcon class="w-4 h-4 text-green-500" />
                                    </span>
                                    <span v-else class="flex-none w-7" />
                                </div>
                                <p v-if="row.error" class="text-xs text-red-600 mt-1 ml-7">{{ row.error }}</p>
                            </div>
                        </div>
                    </div>
                </template>
                <p v-if="serialError" class="text-xs text-red-600">{{ serialError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="serialDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Sluiten
                    </button>
                    <button type="button" :disabled="serialSubmitting || (!serialHasDirty && !serialCanComplete)"
                        @click="saveSerials({ complete: serialCanComplete })"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        {{ serialPrimaryLabel }}
                    </button>
                </div>
            </template>
        </DrawerComponent>

        <!-- Sign modal -->
        <ModalDialog :open="signModalOpen" @update:open="signModalOpen = $event" title="Taak ondertekenen"
            max-width-class="sm:max-w-lg">
            <div class="flex flex-col gap-4">
                <TextInput v-model="signName" label="Naam klant" placeholder="Volledige naam" />
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Handtekening</label>
                    <SignaturePad ref="signaturePadRef" :key="signModalKey" v-model="signatureData" />
                </div>
                <p v-if="signError" class="text-xs text-red-600">{{ signError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="closeSignModal"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="signForm.processing" @click="submitSign"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Ondertekenen
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- View signature modal -->
        <ModalDialog :open="viewModalOpen" @update:open="viewModalOpen = $event" title="Ondertekening"
            max-width-class="sm:max-w-md">
            <div v-if="viewingInstance" class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <span class="text-gray-500 dark:text-slate-400">Naam</span>
                    <span class="text-gray-900 dark:text-slate-100 font-medium">{{ viewingInstance.signed_by }}</span>
                    <span class="text-gray-500 dark:text-slate-400">Datum</span>
                    <span class="text-gray-900 dark:text-slate-100">{{ nlDate(viewingInstance.signed_at) }}</span>
                    <span class="text-gray-500 dark:text-slate-400">Tijd</span>
                    <span class="text-gray-900 dark:text-slate-100">{{ nlTime(viewingInstance.signed_at) }}</span>
                </div>
                <div class="mt-2 border border-gray-200 dark:border-slate-600 rounded-lg p-3">
                    <img :src="viewingInstance.signature_base64" alt="Handtekening" class="max-h-32 w-auto">
                </div>
            </div>
            <template #footer>
                <div class="flex justify-between items-center">
                    <button v-if="canSign" type="button" @click="openUnsignConfirm(viewingInstance)"
                        class="inline-flex items-center p-2 rounded-full border border-gray-200 bg-white text-red-500 hover:text-red-700 hover:border-gray-300 transition-colors"
                        v-tooltip="'Verwijder handtekening'">
                        <TrashIcon class="w-4 h-4" />
                    </button>
                    <button type="button" @click="viewModalOpen = false"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 transition-opacity ml-auto">
                        Sluiten
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- Cancel modal -->
        <ModalDialog :open="cancelModalOpen" @update:open="cancelModalOpen = $event" title="Taak annuleren"
            max-width-class="sm:max-w-md">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-gray-500 dark:text-slate-400">
                    Geef een reden op voor het annuleren van deze taak.
                </p>
                <div>
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Reden</label>
                    <textarea v-model="cancelReason" rows="3" placeholder="Reden voor annulering..."
                        class="w-full rounded-md border-0 py-1.5 pl-3 pr-3 text-gray-900 dark:text-white placeholder:text-gray-400 dark:placeholder:text-gray-600 ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm sm:leading-6 resize-y" />
                </div>
                <p v-if="cancelError" class="text-xs text-red-600">{{ cancelError }}</p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="closeCancelModal"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="cancelForm.processing" @click="submitCancel"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-red-600 text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Taak annuleren
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- Uncomplete signed confirm modal -->
        <ModalDialog :open="uncompleteSignedConfirmOpen" @update:open="uncompleteSignedConfirmOpen = $event"
            max-width-class="sm:max-w-sm">
            <div class="sm:flex sm:items-start gap-4">
                <div
                    class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-yellow-100 sm:mx-0 sm:size-10">
                    <AlertTriangleIcon class="size-6 text-yellow-600" />
                </div>
                <div class="mt-3 sm:mt-0 text-center sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Handtekening wordt verwijderd</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Deze taak is ondertekend. Als je de taak heropent, wordt de handtekening verwijderd. Weet je
                        zeker dat je door wilt gaan?
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="cancelUncomplete"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" @click="confirmUncomplete"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-red-600 text-white hover:opacity-90 transition-opacity">
                        Doorgaan
                    </button>
                </div>
            </template>
        </ModalDialog>

        <!-- Unsign confirm modal -->
        <ModalDialog :open="unsignConfirmOpen" @update:open="unsignConfirmOpen = $event" max-width-class="sm:max-w-sm">
            <div class="sm:flex sm:items-start gap-4">
                <div
                    class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:size-10">
                    <AlertTriangleIcon class="size-6 text-red-600" />
                </div>
                <div class="mt-3 sm:mt-0 text-center sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Handtekening verwijderen</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Weet je zeker dat je de handtekening van deze taak wilt verwijderen?
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="unsignConfirmOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="unsignForm.processing" @click="confirmUnsign"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-red-600 text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Verwijderen
                    </button>
                </div>
            </template>
        </ModalDialog>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { Plus as PlusIcon, Trash2 as TrashIcon, EllipsisVertical as EllipsisVerticalIcon, ClipboardListIcon, PenLine as PenLineIcon, BadgeCheck as BadgeCheckIcon, AlertTriangle as AlertTriangleIcon, Ban as BanIcon, Check as CheckIcon, ScanBarcode as ScanBarcodeIcon } from '@lucide/vue'
import { hasPermission, nlDate, nlTime } from '@/Utilities/Utilities'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import CheckboxComponent from '@/Components/UI/AnimatedCheckbox.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import ScanSerialButton from '@/Components/UI/ScanSerialButton.vue'

const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    instances: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
})

const canCreate = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.create'))
const canToggle = computed(() => !props.isClosed && (hasPermission('serviceordertaskinstance.open_close') || hasPermission('serviceordertaskinstance.update')))
const canEdit = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.update'))
const canDelete = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.delete'))
const canSign = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.open_close'))
const canCancel = computed(() => !props.isClosed && hasPermission('serviceordertaskinstance.cancel'))

const internalInstances = ref(props.instances.map(i => ({ ...i })))

watch(() => props.instances, (new_val) => {
    internalInstances.value = new_val.map(i => ({ ...i }))
}, { deep: true })

const taskOptions = computed(() => props.availableTasks.map(t => ({ id: t.id, name: t.title })))
const productOptions = computed(() => props.products.map(p => ({
    id: p.id,
    name: p.name,
    attributes: p.attributes ?? [],
    search: p.search ?? '',
})))

function toggleRoleId(list, id) {
    const idx = list.indexOf(id)
    if (idx === -1) {
        list.push(id)
    } else {
        list.splice(idx, 1)
    }
}

// ── Add drawer ────────────────────────────────────────────────────────────────
const addDrawerOpen = ref(false)
const newTaskId = ref(null)
const newTitle = ref('')
const newDescription = ref('')
const newProductId = ref(null)
const newQuantity = ref(1)
const newUserRoleIds = ref([])

const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    product_id: null,
    quantity: 1,
    title: '',
    description: '',
    is_complete: false,
    user_role_ids: [],
})

function onNewTaskSelected(id) {
    if (id) {
        const task = props.availableTasks.find(t => t.id === id)
        if (task) {
            if (!newTitle.value) newTitle.value = task.title ?? ''
            if (!newDescription.value) newDescription.value = task.description ?? ''
        }
    } else {
        newDescription.value = ''
    }
}

function addInstance() {
    addForm.service_order_task_id = newTaskId.value
    addForm.product_id = newProductId.value
    addForm.quantity = newProductId.value ? newQuantity.value : 1
    addForm.title = newTitle.value.trim() || null
    addForm.description = newDescription.value.trim() || null
    addForm.user_role_ids = newUserRoleIds.value

    addForm.post('/serviceordertaskinstances', {
        preserveScroll: true,
        onSuccess: () => {
            addDrawerOpen.value = false
            newTaskId.value = null
            newTitle.value = ''
            newDescription.value = ''
            newProductId.value = null
            newQuantity.value = 1
            newUserRoleIds.value = []
            addForm.reset()
            addForm.service_order_id = props.serviceOrderId
        },
    })
}

// ── Edit drawer ───────────────────────────────────────────────────────────────
const editDrawerOpen = ref(false)
const editingInstance = ref(null)
const editTitle = ref('')
const editDescription = ref('')
const editProductId = ref(null)
const editQuantity = ref(1)
const editUserRoleIds = ref([])

const editForm = useForm({ title: '', description: '', product_id: null, quantity: 1, user_role_ids: [] })

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editProductId.value = instance.product_id ?? null
    editQuantity.value = instance.quantity ?? 1
    editUserRoleIds.value = (instance.user_roles ?? []).map(r => r.id)
    editDrawerOpen.value = true
}

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null
    editForm.product_id = editProductId.value
    editForm.quantity = editProductId.value ? editQuantity.value : 1
    editForm.user_role_ids = editUserRoleIds.value

    editForm.patch(`/serviceordertaskinstances/${editingInstance.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingInstance.value.title = editForm.title
            editingInstance.value.description = editForm.description
            editingInstance.value.product_id = editForm.product_id
            editingInstance.value.quantity = editForm.quantity
            editingInstance.value.user_roles = props.userRoles.filter(r => editForm.user_role_ids.includes(r.id))
            editDrawerOpen.value = false
            editForm.reset()
        },
    })
}

// ── Toggle ────────────────────────────────────────────────────────────────────
const checkboxResetKeys = ref({})

function resetCheckbox(id) {
    checkboxResetKeys.value = { ...checkboxResetKeys.value, [id]: (checkboxResetKeys.value[id] ?? 0) + 1 }
}

function toggleComplete(instance, new_value) {
    if (!canToggle.value) return

    if (!new_value && instance.signed_by) {
        uncompleteSignedInstance.value = instance
        uncompleteSignedConfirmOpen.value = true
        return
    }

    const counts = serialCounts(instance)

    if (new_value && counts.filled < counts.expected) {
        resetCheckbox(instance.id)
        openSerialDrawer(instance)
        return
    }

    doToggle(instance, new_value)
}

function doToggle(instance, new_value) {
    const row = internalInstances.value.find(i => i.id === instance.id) ?? instance
    const previous = row.is_complete
    row.is_complete = new_value

    useForm({ is_complete: new_value }).patch(`/serviceordertaskinstances/${row.id}/toggle`, {
        preserveScroll: true,
        onError: (errors) => {
            row.is_complete = previous
            resetCheckbox(row.id)
            if (errors.task) {
                usePage().props.flash.error = errors.task
            }
        },
    })
}

// ── Serial numbers ────────────────────────────────────────────────────────────
const serialDrawerOpen = ref(false)
const serialInstance = ref(null)
const serialGroups = ref([])
const serialError = ref('')
const serialSubmitting = ref(false)

function serialCounts(instance) {
    const slots = instance.serial_slots ?? []

    return {
        expected: slots.reduce((total, group) => total + group.expected, 0),
        filled: slots.reduce((total, group) => total + Math.min(group.expected, group.assets.length), 0),
    }
}

/**
 * The server decides which machines a task expects; a row is only ever a saved asset
 * or an empty slot waiting for one.
 */
function buildSerialGroups(instance) {
    return (instance.serial_slots ?? []).map(group => {
        const saved = group.assets.map(asset => ({
            asset_id: asset.id,
            product_id: group.product_id,
            serial_number: asset.serial_number ?? '',
            saved_serial: asset.serial_number ?? '',
            error: '',
        }))
        const empty = Array.from({ length: Math.max(0, group.expected - saved.length) }, () => ({
            asset_id: null,
            product_id: group.product_id,
            serial_number: '',
            saved_serial: '',
            error: '',
        }))

        return {
            product_id: group.product_id,
            label: group.label,
            expected: group.expected,
            rows: [...saved, ...empty],
        }
    })
}

function openSerialDrawer(instance) {
    serialInstance.value = instance
    serialGroups.value = buildSerialGroups(instance)
    serialError.value = ''
    serialSubmitting.value = false
    serialDrawerOpen.value = true
}

watch(serialDrawerOpen, (is_open) => {
    if (!is_open) {
        serialInstance.value = null
        serialGroups.value = []
        serialError.value = ''
        serialSubmitting.value = false
    }
})

function serialRows() {
    return serialGroups.value.flatMap(g => g.rows)
}

function rowIsDirty(row) {
    return row.serial_number.trim() !== '' && row.serial_number.trim() !== row.saved_serial
}

const serialHasDirty = computed(() => serialRows().some(rowIsDirty))

const serialCanComplete = computed(() => {
    if (!serialInstance.value || serialInstance.value.is_complete || serialInstance.value.is_cancelled) return false

    return serialRows().every(row => row.serial_number.trim() !== '')
})

const serialPrimaryLabel = computed(() => {
    if (serialSubmitting.value) return 'Opslaan...'
    if (serialCanComplete.value) return serialHasDirty.value ? 'Opslaan en voltooien' : 'Voltooien'

    return 'Opslaan'
})

function inertiaVisit(method, url, data) {
    return new Promise((resolve) => {
        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => resolve({ ok: true }),
            onError: (errors) => resolve({ ok: false, errors }),
        })
    })
}

/**
 * Rows that were just sent come back as saved assets in the refreshed props, so they are
 * dropped here — leaving them in would re-apply their serial to the next empty slot.
 * Anything still typed but unsent is carried over so a save never loses what is on screen.
 */
async function syncSerialGroups(sent = []) {
    await nextTick()

    const fresh = props.instances.find(i => i.id === serialInstance.value?.id)

    if (!fresh) {
        serialDrawerOpen.value = false
        return
    }

    const leftovers = {}
    serialGroups.value.forEach(group => {
        leftovers[group.product_id] = group.rows
            .filter(row => !row.asset_id && row.serial_number.trim() !== '' && !sent.includes(row))
            .map(row => row.serial_number)
    })

    serialInstance.value = fresh
    serialGroups.value = buildSerialGroups(fresh)
    serialGroups.value.forEach(group => {
        const queue = leftovers[group.product_id] ?? []
        group.rows.filter(row => !row.asset_id).forEach((row, i) => {
            if (queue[i] !== undefined) row.serial_number = queue[i]
        })
    })
}

async function saveRow(row) {
    await saveSerials({ complete: false, rows: [row] })
}

async function saveSerials({ complete, rows = null }) {
    if (serialSubmitting.value) return

    const dirty = (rows ?? serialRows()).filter(rowIsDirty)
    serialError.value = ''
    dirty.forEach(row => { row.error = '' })
    serialSubmitting.value = true

    for (const row of dirty.filter(r => r.asset_id)) {
        const result = await inertiaVisit(
            'patch',
            `/serviceordertaskinstances/${serialInstance.value.id}/assets/${row.asset_id}`,
            { serial_number: row.serial_number.trim() },
        )

        if (!result.ok) {
            row.error = result.errors.serial_number ?? 'Opslaan is mislukt.'
            serialSubmitting.value = false
            return
        }
    }

    const created = dirty.filter(r => !r.asset_id)

    if (created.length) {
        const result = await inertiaVisit(
            'post',
            `/serviceordertaskinstances/${serialInstance.value.id}/assets`,
            {
                assets: created.map(row => ({
                    product_id: row.product_id,
                    serial_number: row.serial_number.trim(),
                })),
            },
        )

        if (!result.ok) {
            created.forEach((row, i) => {
                row.error = result.errors[`assets.${i}.serial_number`] ?? ''
            })
            serialError.value = result.errors.assets ?? ''
            serialSubmitting.value = false
            return
        }
    }

    await syncSerialGroups(dirty)
    serialSubmitting.value = false

    if (complete && serialInstance.value) {
        doToggle(serialInstance.value, true)
        serialDrawerOpen.value = false
    }
}

// ── Sign ──────────────────────────────────────────────────────────────────────
const signModalOpen = ref(false)
const signingInstance = ref(null)
const signName = ref('')
const signatureData = ref('')
const signError = ref('')
const signModalKey = ref(0)
const signaturePadRef = ref(null)
const signForm = useForm({ signed_by: '', signature_base64: '' })

function openSignModal(instance) {
    signingInstance.value = instance
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signModalKey.value++
    signModalOpen.value = true
}

function closeSignModal() {
    signModalOpen.value = false
    signingInstance.value = null
    signName.value = ''
    signatureData.value = ''
    signError.value = ''
    signForm.reset()
}

function submitSign() {
    signError.value = ''
    if (!signName.value.trim()) {
        signError.value = 'Vul een naam in.'
        return
    }
    if (!signaturePadRef.value || signaturePadRef.value.isEmpty()) {
        signError.value = 'Teken een handtekening.'
        return
    }
    signaturePadRef.value.save()
    const data_url = signaturePadRef.value.getDataUrl()
    signForm.signed_by = signName.value.trim()
    signForm.signature_base64 = data_url
    signForm.post(`/serviceordertaskinstances/${signingInstance.value.id}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === signingInstance.value.id)
            if (inst) {
                inst.signed_by = signForm.signed_by
                inst.signature_base64 = signForm.signature_base64
                inst.signed_at = new Date().toISOString()
            }
            closeSignModal()
        },
        onError: () => {
            signError.value = 'Er is een fout opgetreden. Probeer het opnieuw.'
        },
    })
}

// ── View signature ────────────────────────────────────────────────────────────
const viewModalOpen = ref(false)
const viewingInstance = ref(null)

function openViewModal(instance) {
    viewingInstance.value = instance
    viewModalOpen.value = true
}

// ── Unsign ────────────────────────────────────────────────────────────────────
const unsignConfirmOpen = ref(false)
const unsigningInstance = ref(null)
const unsignForm = useForm({})

function openUnsignConfirm(instance) {
    viewModalOpen.value = false
    viewingInstance.value = null
    unsigningInstance.value = instance
    unsignConfirmOpen.value = true
}

function confirmUnsign() {
    unsignForm.delete(`/serviceordertaskinstances/${unsigningInstance.value.id}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === unsigningInstance.value.id)
            if (inst) {
                inst.signed_by = null
                inst.signature_base64 = null
                inst.signed_at = null
            }
            unsignConfirmOpen.value = false
            unsigningInstance.value = null
        },
    })
}

// ── Uncomplete with signature guard ───────────────────────────────────────────
const uncompleteSignedConfirmOpen = ref(false)
const uncompleteSignedInstance = ref(null)

function cancelUncomplete() {
    uncompleteSignedConfirmOpen.value = false
}

watch(uncompleteSignedConfirmOpen, (is_open) => {
    if (!is_open && uncompleteSignedInstance.value) {
        const id = uncompleteSignedInstance.value.id
        uncompleteSignedInstance.value = null
        checkboxResetKeys.value = { ...checkboxResetKeys.value, [id]: (checkboxResetKeys.value[id] ?? 0) + 1 }
    }
})

function confirmUncomplete() {
    const instance = uncompleteSignedInstance.value
    uncompleteSignedConfirmOpen.value = false
    uncompleteSignedInstance.value = null
    doToggle(instance, false, [])
}

// ── Cancel ───────────────────────────────────────────────────────────────────
const cancelModalOpen = ref(false)
const cancellingInstance = ref(null)
const cancelReason = ref('')
const cancelError = ref('')
const cancelForm = useForm({ cancellation_reason: '' })

function openCancelModal(instance) {
    cancellingInstance.value = instance
    cancelReason.value = ''
    cancelError.value = ''
    cancelModalOpen.value = true
}

function closeCancelModal() {
    cancelModalOpen.value = false
    cancellingInstance.value = null
    cancelReason.value = ''
    cancelError.value = ''
    cancelForm.reset()
}

function submitCancel() {
    cancelError.value = ''
    if (!cancelReason.value.trim()) {
        cancelError.value = 'Vul een reden in.'
        return
    }
    cancelForm.cancellation_reason = cancelReason.value.trim()
    cancelForm.post(`/serviceordertaskinstances/${cancellingInstance.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            const inst = internalInstances.value.find(i => i.id === cancellingInstance.value.id)
            if (inst) {
                inst.is_cancelled = true
                inst.cancellation_reason = cancelForm.cancellation_reason
            }
            closeCancelModal()
        },
        onError: () => {
            cancelError.value = 'Er is een fout opgetreden. Probeer het opnieuw.'
        },
    })
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteInstance(id) {
    if (!canDelete.value) return
    if (!confirm('Weet je zeker dat je deze taak wilt verwijderen?')) return
    useForm({}).delete(`/serviceordertaskinstances/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            const idx = internalInstances.value.findIndex(i => i.id === id)
            if (idx !== -1) internalInstances.value.splice(idx, 1)
        },
    })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function effectiveTitle(instance) {
    return instance.title || instance.service_order_task?.title || '(geen titel)'
}

function effectiveDescription(instance) {
    return instance.description || instance.service_order_task?.description || ''
}
</script>
