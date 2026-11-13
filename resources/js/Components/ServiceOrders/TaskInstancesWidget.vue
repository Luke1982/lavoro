<template>
    <component :is="boxed && !compact ? BoxComponent : 'div'">
        <SectionHeader v-if="!compact" :icon="ClipboardListIcon" title="Werkbontaken"
            subtitle="De taken die op deze werkbon uitgevoerd moeten worden." chapter="serviceorders">
            <template v-if="canCreate" #actions>
                <button type="button" @click="addDrawerOpen = true"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-lavoro-blue hover:opacity-80 transition-opacity ml-2 sm:ml-0 justify-center py-3 sm:pt-2 px-3 sm:px-0 border-1 border-gray-200 rounded-lavoro-sm sm:border-0 cursor-pointer">
                    <PlusIcon class="w-4 h-4" />
                    <span class="hidden sm:inline">Taak toevoegen</span>
                </button>
            </template>
        </SectionHeader>

        <p v-if="hiddenCount > 0" class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-slate-400"
            :class="compact ? 'pb-1' : 'py-2 border-b border-gray-100 dark:border-slate-800/60'">
            <EyeOffIcon class="w-3.5 h-3.5 mt-px flex-none" />
            <span>
                Er {{ hiddenCount === 1 ? 'is 1 taak' : `zijn ${hiddenCount} taken` }} op deze bon die jij niet kunt
                zien. {{ hiddenCount === 1 ? 'Die hoort' : 'Die horen' }} bij een rol waarin jij deze werkbon niet
                uitvoert.
            </span>
        </p>

        <div v-if="compact" class="flex flex-col gap-2" v-auto-animate>
            <div v-for="instance in internalInstances" :key="instance.id">
                <WorkItemRow :icon="ListChecksIcon" tone="green" :title="taskInstanceTitle(instance)"
                    :subtitle="taskInstanceDescription(instance) || productLine(instance)"
                    :action-label="actionLabel(instance)" :action-icon="summaries[instance.id].hasWork ? PlayIcon : CheckIcon"
                    done-label="Afgevinkt" :done="instance.is_complete"
                    :disabled="!canToggle && !summaries[instance.id].hasWork" :muted="instance.is_cancelled"
                    muted-label="Geannuleerd" @action="act(instance)">
                    <TaskProgressBadges :summary="summaries[instance.id]" @open="toggleOpen(instance)" />
                </WorkItemRow>
                <div class="grid transition-[grid-template-rows] duration-200 ease-out"
                    :class="openInstanceId === instance.id ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                    <div class="overflow-hidden" :inert="openInstanceId !== instance.id">
                        <TaskWorkPanel v-if="unfolded.has(instance.id)" :instance="instance" :can-toggle="canToggle"
                            class="mt-2 rounded-lavoro-sm border border-gray-200 bg-gray-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/40"
                            @complete="completeInline(instance)" @reopen="toggleComplete(instance, false)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else v-auto-animate>
            <div v-if="internalInstances.length === 0" class="text-sm text-gray-400 dark:text-slate-500 py-2">
                {{ hiddenCount > 0 ? 'Verder staan er geen taken op deze bon.' : 'Nog geen taken toegevoegd.' }}
            </div>
            <div v-for="instance in internalInstances" :key="instance.id"
                class="flex items-start gap-3 py-3 border-b border-gray-100 dark:border-slate-800/60 last:border-0">
                <CheckboxComponent :key="`cb-${instance.id}-${checkboxResetKeys[instance.id] ?? 0}`"
                    :model-value="instance.is_complete" :disabled="!canToggle || instance.is_cancelled"
                    @update:modelValue="toggleComplete(instance, $event)" />
                <div class="flex flex-col sm:flex-row justify-between flex-grow gap-y-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800 dark:text-slate-200 truncate">
                            {{ taskInstanceTitle(instance) }}
                        </p>
                        <p v-if="taskInstanceDescription(instance)"
                            class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-2">
                            {{ taskInstanceDescription(instance) }}
                        </p>
                        <p v-if="instance.product" class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                            {{ productLine(instance) }}
                        </p>
                        <p v-if="summaries[instance.id]?.parts.length"
                            class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 line-clamp-2">
                            <span v-for="(part, index) in summaries[instance.id].parts" :key="part.key">
                                <span v-if="index" class="text-gray-300 dark:text-slate-600"> · </span>{{ part.count }}×
                                {{ part.label }}<span v-if="!part.requires_serial"
                                    class="text-gray-400 dark:text-slate-500"> (geen serienr.)</span>
                            </span>
                        </p>
                        <TaskProgressBadges :summary="summaries[instance.id]" @open="openSerialDrawer(instance)" />
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
                        <button v-if="canManageMaterials" type="button"
                            class="relative flex-none p-1 rounded hover:bg-gray-100 dark:hover:bg-slate-700"
                            @click="openMaterialsModal(instance)" v-tooltip="'Materialen voor deze taak'">
                            <PackageIcon class="w-4 h-4 text-gray-500 dark:text-slate-400" />
                            <span v-if="materialCount(instance) > 0"
                                class="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-lavoro-green px-1 text-[10px] font-semibold text-gray-800">
                                {{ materialCount(instance) }}
                            </span>
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
                <FlexPartsFieldset v-if="newFlexParts.length" :parts="newFlexParts" :quantities="newFlexQuantities"
                    :error="addForm.errors.flex_parts" :bundle-count="newQuantity"
                    @change="(id, value) => newFlexQuantities[id] = value" />
                <TaskCheckOptions v-model:per-unit="newChecksPerUnit" v-model:extras="newExtras"
                    :quantity="newProductId ? newQuantity : 0" :checks="taskChecks" :checklists="taskChecklists" />
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
                <p v-for="message in formErrors(addForm)" :key="message" class="text-xs text-red-600">{{ message }}</p>
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
                <FlexPartsFieldset v-if="editFlexParts.length" :parts="editFlexParts" :quantities="editFlexQuantities"
                    :error="editForm.errors.flex_parts" :bundle-count="editQuantity"
                    @change="(id, value) => editFlexQuantities[id] = value" />
                <TaskCheckOptions v-model:per-unit="editChecksPerUnit" v-model:extras="editExtras"
                    :quantity="editProductId ? editQuantity : 0" :locked="editHasTicks" :checks="taskChecks"
                    :checklists="taskChecklists" />
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
                <p v-for="message in formErrors(editForm)" :key="message" class="text-xs text-red-600">{{ message }}</p>
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

        <!-- Task work drawer -->
        <DrawerComponent v-model="serialDrawerOpen" title="Taak uitvoeren"
            :subtitle="serialInstance ? taskInstanceTitle(serialInstance) : ''" max-width-class="max-w-lg">
            <TaskWorkPanel v-if="serialInstance" :key="serialInstance.id" :instance="serialInstance"
                :can-toggle="canToggle" class="p-4 sm:p-6" @complete="completeFromPanel(serialInstance)"
                @reopen="serialDrawerOpen = false; toggleComplete(serialInstance, false)" />
            <template #footer>
                <div class="flex justify-end">
                    <button type="button" @click="serialDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Sluiten
                    </button>
                </div>
            </template>
        </DrawerComponent>

        <!-- Materials modal -->
        <ModalDialog :open="materialsModalOpen" @update:open="materialsModalOpen = $event"
            :title="materialsInstance ? `Materialen — ${taskInstanceTitle(materialsInstance)}` : 'Materialen'"
            max-width-class="sm:max-w-3xl">
            <button type="button" @click="materialsModalOpen = false" aria-label="Sluiten"
                class="absolute top-4 right-4 z-10 flex-shrink-0 w-9 h-9 border border-gray-200 dark:border-gray-700 rounded-xl hidden sm:flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:border-gray-300 transition-colors">
                <XIcon class="h-5 w-5" />
            </button>
            <MaterialsWidget v-if="materialsInstance" :service-order-id="serviceOrderId"
                :endpoint-base="`/serviceordertaskinstances/${materialsInstance.id}`"
                :task-instance-id="materialsInstance.id" :materials="materialsInstance.materials ?? []"
                :freeform-materials="materialsInstance.freeform_materials ?? []" :all-materials="allMaterials"
                :materials-use-ajax="materialsUseAjax" :categories="materialCategories"
                :usage-units="materialUsageUnits" :is-closed="isClosed" :type="type" />
        </ModalDialog>

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
    </component>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Plus as PlusIcon, Trash2 as TrashIcon, EllipsisVertical as EllipsisVerticalIcon, ClipboardListIcon, PenLine as PenLineIcon, BadgeCheck as BadgeCheckIcon, AlertTriangle as AlertTriangleIcon, Ban as BanIcon, Check as CheckIcon, Package as PackageIcon, X as XIcon, EyeOff as EyeOffIcon, ListChecks as ListChecksIcon, Play as PlayIcon } from '@lucide/vue'
import { hasPermission, hasAnyPermission, nlDate, nlTime, taskInstanceTitle, taskInstanceDescription } from '@/Utilities/Utilities'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import CheckboxComponent from '@/Components/UI/AnimatedCheckbox.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import MaterialsWidget from '@/Components/Materials/MaterialsWidget.vue'
import FlexPartsFieldset from '@/Components/ServiceOrders/FlexPartsFieldset.vue'
import WorkItemRow from '@/Components/ServiceOrders/WorkItemRow.vue'
import TaskWorkPanel from '@/Components/ServiceOrders/Tasks/TaskWorkPanel.vue'
import TaskProgressBadges from '@/Components/ServiceOrders/Tasks/TaskProgressBadges.vue'
import TaskCheckOptions from '@/Components/ServiceOrders/Tasks/TaskCheckOptions.vue'
import { serialCounts, bundleParts } from '@/Components/ServiceOrders/Tasks/taskSerials.js'
import { checkCounts, extrasOf } from '@/Components/ServiceOrders/Tasks/taskChecks.js'

const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    instances: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
    type: { type: String, default: null },
    allMaterials: { type: Array, default: () => [] },
    materialsUseAjax: { type: Boolean, default: false },
    materialCategories: { type: Array, default: () => [] },
    materialUsageUnits: { type: Array, default: () => [] },
    /** Every bundle's parts, keyed by the bundle product, so a taak can fill in the vrije aantallen. */
    bundlePartsByProduct: { type: Object, default: () => ({}) },
    /** The library of checks and checklists, for the extras picked on a task. */
    taskChecks: { type: Array, default: () => [] },
    taskChecklists: { type: Array, default: () => [] },
    /** Opt out where the widget is one section among others that sit flush, rather than a card of its own. */
    boxed: { type: Boolean, default: true },
    /** Tasks on this order that fall outside the reader's roles, and so never reach the list below. */
    hiddenCount: { type: Number, default: 0 },
    /**
     * The monteur's checklist: one row and one button per task, no header and none of the
     * management around it. Ticking off still goes through the same windows — serial
     * numbers, a signed task — as the full list.
     */
    compact: { type: Boolean, default: false },
})

/** "2× Daikin FTXM35R": what a task installs, when it installs something. */
function productLine(instance) {
    return instance.product
        ? instance.quantity + '× ' + instance.product.brand.name + ' ' + instance.product.model
        : ''
}

const canManageMaterials = computed(() => hasAnyPermission([
    'material.read.serviceorder',
    'materiable.create.serviceorder',
    'materiable.update.serviceorder',
    'materiable.delete.serviceorder',
    'freeformmaterial.read',
    'freeformmaterial.create',
    'freeformmaterial.update',
    'freeformmaterial.delete',
]))

function materialCount(instance) {
    return (instance.materials?.length ?? 0) + (instance.freeform_materials?.length ?? 0)
}

const materialsInstanceId = ref(null)
const materialsModalOpen = ref(false)

/** Resolved from props so the list refreshes in place after an add or a removal. */
const materialsInstance = computed(() =>
    materialsInstanceId.value == null
        ? null
        : (props.instances.find(i => i.id === materialsInstanceId.value) ?? null)
)

function openMaterialsModal(instance) {
    materialsInstanceId.value = instance.id
    materialsModalOpen.value = true
}

watch(materialsModalOpen, (is_open) => {
    if (!is_open) materialsInstanceId.value = null
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
const newFlexQuantities = reactive({})

const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    product_id: null,
    quantity: 1,
    title: '',
    description: '',
    is_complete: false,
    user_role_ids: [],
    flex_parts: [],
    checks_per_unit: false,
    extra_check_ids: [],
    extra_checklist_ids: [],
    extra_titles: [],
})

const emptyExtras = () => ({ check_ids: [], checklist_ids: [], titles: [] })
const newChecksPerUnit = ref(false)
const newExtras = ref(emptyExtras())

/**
 * The parts of the chosen bundle whose aantal is not settled by the product. They are asked
 * for on the taak, because that is where "one omvormer with 14 panelen" is first known.
 */
function flexPartsOf(productId) {
    return (props.bundlePartsByProduct[productId] ?? []).filter(part => part.flex_quantity)
}

/** @param {Object} quantities the reactive map to reset, keyed by the part's own product */
function seedFlexQuantities(quantities, parts, instance = null) {
    Object.keys(quantities).forEach(key => delete quantities[key])

    const filled_in = new Map(
        (instance?.productables ?? []).map(row => [row.product_id, row.quantity])
    )

    parts.forEach(part => {
        quantities[part.child_product_id] = filled_in.get(part.child_product_id)
            ?? (part.is_required ? 1 : 0)
    })
}

function flexPartsPayload(quantities, parts) {
    return parts.map(part => ({
        product_id: part.child_product_id,
        quantity: Math.max(0, quantities[part.child_product_id] ?? 0),
    }))
}

const newFlexParts = computed(() => flexPartsOf(newProductId.value))

watch(newProductId, (productId) => seedFlexQuantities(newFlexQuantities, flexPartsOf(productId)))

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
    addForm.flex_parts = flexPartsPayload(newFlexQuantities, newFlexParts.value)
    addForm.checks_per_unit = !!(newProductId.value && newQuantity.value > 1 && newChecksPerUnit.value)
    addForm.extra_check_ids = newExtras.value.check_ids
    addForm.extra_checklist_ids = newExtras.value.checklist_ids
    addForm.extra_titles = newExtras.value.titles

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
            newChecksPerUnit.value = false
            newExtras.value = emptyExtras()
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
const editFlexQuantities = reactive({})

const editForm = useForm({
    title: '',
    description: '',
    product_id: null,
    quantity: 1,
    user_role_ids: [],
    flex_parts: [],
    checks_per_unit: false,
    extra_check_ids: [],
    extra_checklist_ids: [],
    extra_titles: [],
})

const editChecksPerUnit = ref(false)
const editExtras = ref(emptyExtras())
const editHasTicks = computed(() => (editingInstance.value?.checks ?? []).some(check => check.is_done))

const editFlexParts = computed(() => flexPartsOf(editProductId.value))

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editProductId.value = instance.product_id ?? null
    editQuantity.value = instance.quantity ?? 1
    editUserRoleIds.value = (instance.user_roles ?? []).map(r => r.id)
    seedFlexQuantities(editFlexQuantities, flexPartsOf(instance.product_id), instance)
    editChecksPerUnit.value = !!instance.checks_per_unit
    editExtras.value = extrasOf(instance.checks)
    editForm.clearErrors()
    editDrawerOpen.value = true
}

/**
 * Idempotent on purpose: opening the drawer sets the product, which fires this a tick later
 * with exactly what openEditDrawer already seeded. Switching away and back restores the
 * aantallen the taak was saved with rather than leaving the other product's behind.
 */
watch(editProductId, (productId) => {
    const instance = editingInstance.value

    seedFlexQuantities(
        editFlexQuantities,
        flexPartsOf(productId),
        productId === (instance?.product_id ?? null) ? instance : null,
    )
})

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null
    editForm.product_id = editProductId.value
    editForm.quantity = editProductId.value ? editQuantity.value : 1
    editForm.user_role_ids = editUserRoleIds.value
    editForm.flex_parts = flexPartsPayload(editFlexQuantities, editFlexParts.value)
    editForm.checks_per_unit = editProductId.value && editQuantity.value > 1
        ? !!editChecksPerUnit.value
        : !!editingInstance.value.checks_per_unit && editHasTicks.value
    editForm.extra_check_ids = editExtras.value.check_ids
    editForm.extra_checklist_ids = editExtras.value.checklist_ids
    editForm.extra_titles = editExtras.value.titles

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

    const summary = summaries.value[instance.id]

    if (new_value && (summary.filled < summary.expected || summary.checks.openRequired > 0)) {
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
const serialInstanceId = ref(null)
const serialInstance = computed(() => internalInstances.value.find(i => i.id === serialInstanceId.value) ?? null)

/**
 * Everything the row says about a taak — its machines, its checks, whether it opens up —
 * worked out once per taak instead of once per mention.
 */
const summaries = computed(() => Object.fromEntries(
    internalInstances.value.map(instance => [
        instance.id,
        taskSummary(instance),
    ])
))

function taskSummary(instance) {
    const serials = serialCounts(instance)
    const checks = checkCounts(instance.checks)

    return { ...serials, parts: bundleParts(instance), checks, hasWork: checks.total > 0 || serials.expected > 0 }
}

/** The form's messages, except the bundle aantallen, which their own fieldset shows. */
const formErrors = form => [...new Set(Object.entries(form.errors)
    .filter(([field]) => !field.startsWith('flex_parts'))
    .map(([, message]) => message))]

const openInstanceId = ref(null)
const unfolded = reactive(new Set())

function actionLabel(instance) {
    if (!summaries.value[instance.id].hasWork) return 'Afvinken'

    return openInstanceId.value === instance.id ? 'Sluiten' : 'Uitvoeren'
}

/** A task with work opens up in place; anything else is ticked off with one tap. */
function act(instance) {
    if (summaries.value[instance.id].hasWork) toggleOpen(instance)
    else toggleComplete(instance, !instance.is_complete)
}

function toggleOpen(instance) {
    if (openInstanceId.value === instance.id) {
        openInstanceId.value = null
        return
    }

    unfolded.add(instance.id)
    openInstanceId.value = instance.id
}

function completeInline(instance) {
    openInstanceId.value = null
    doToggle(instance, true)
}

function openSerialDrawer(instance) {
    serialInstanceId.value = instance.id
    serialDrawerOpen.value = true
}

function completeFromPanel(instance) {
    serialDrawerOpen.value = false
    doToggle(instance, true)
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

</script>
