<template>
    <!-- Sized against the container, not the viewport: the same widget sits in a
         narrow sidebar and a wide main column on one and the same screen. -->
    <div v-if="mayShow"
        class="@container bg-white dark:bg-slate-900 text-gray-800 dark:text-slate-100 border-lavoro-box shadow-lavoro-box rounded-lavoro-sm overflow-hidden">

        <div class="p-5 @2xl:p-6">
            <SectionHeader :icon="FolderIcon" :title="title" :subtitle="resolvedSubtitle" :internal="internal"
                chapter="documents" flush stack-actions>
                <!-- Compact: the two buttons split the row evenly (flex-1 also gives a
                     lone button the full width). Wide: back to natural widths. -->
                <template v-if="mayManageCategories || mayUpload" #actions>
                    <button v-if="mayManageCategories" type="button" @click="openCategoryModal"
                        class="inline-flex flex-1 min-w-0 items-center justify-center gap-2 rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors cursor-pointer @3xl:flex-none">
                        <FolderPlusIcon class="size-5 flex-none" />
                        <span class="truncate">Categorie aanmaken</span>
                    </button>
                    <button v-if="mayUpload" type="button" @click="openFilePicker"
                        class="inline-flex flex-1 min-w-0 items-center justify-center gap-2 rounded-lavoro-sm bg-lavoro-blue px-4 py-2.5 text-sm font-medium text-white hover:opacity-90 transition-opacity cursor-pointer @3xl:flex-none">
                        <PlusIcon class="size-5 flex-none" />
                        <span class="truncate">Document uploaden</span>
                    </button>
                </template>
            </SectionHeader>
        </div>

        <div v-if="maySee && existing.length > 0"
            class="flex flex-col gap-3 px-5 pb-5 @2xl:px-6 @2xl:pb-6 @3xl:flex-row @3xl:items-center @3xl:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="activeCategoryId = null" :class="[
                    'inline-flex items-center gap-2 rounded-lavoro-sm px-3.5 py-2 text-sm font-medium transition-colors cursor-pointer',
                    activeCategoryId === null
                        ? 'bg-lavoro-lightblue text-lavoro-blue dark:bg-lavoro-blue/20 dark:text-blue-300'
                        : 'border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'
                ]">
                    Alle documenten
                    <span :class="[
                        'inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                        activeCategoryId === null
                            ? 'bg-lavoro-blue text-white'
                            : 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400'
                    ]">{{ existing.length }}</span>
                </button>

                <button v-for="category in categories" :key="category.id" type="button"
                    @click="toggleCategoryFilter(category.id)" :class="[
                        'inline-flex items-center gap-2 rounded-lavoro-sm px-3.5 py-2 text-sm font-medium transition-colors cursor-pointer',
                        activeCategoryId === category.id
                            ? 'bg-lavoro-lightblue text-lavoro-blue dark:bg-lavoro-blue/20 dark:text-blue-300'
                            : 'border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'
                    ]">
                    {{ category.name }}
                    <span :class="[
                        'inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                        activeCategoryId === category.id
                            ? 'bg-lavoro-blue text-white'
                            : 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400'
                    ]">{{ countFor(category.id) }}</span>
                </button>

                <button v-if="countFor(UNCATEGORIZED) > 0" type="button" @click="toggleCategoryFilter(UNCATEGORIZED)" :class="[
                    'inline-flex items-center gap-2 rounded-lavoro-sm px-3.5 py-2 text-sm font-medium transition-colors cursor-pointer',
                    activeCategoryId === UNCATEGORIZED
                        ? 'bg-lavoro-lightblue text-lavoro-blue dark:bg-lavoro-blue/20 dark:text-blue-300'
                        : 'border border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'
                ]">
                    Zonder categorie
                    <span :class="[
                        'inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                        activeCategoryId === UNCATEGORIZED
                            ? 'bg-lavoro-blue text-white'
                            : 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400'
                    ]">{{ countFor(UNCATEGORIZED) }}</span>
                </button>
            </div>

            <div class="relative @3xl:w-72">
                <MagnifyingGlassIcon
                    class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-gray-400" />
                <input v-model="search" type="search" placeholder="Zoek documenten..."
                    class="w-full rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 py-2.5 pl-10 pr-3 text-sm text-gray-900 dark:text-slate-100 placeholder:text-gray-400 focus:border-lavoro-blue focus:outline-none focus:ring-1 focus:ring-lavoro-blue" />
            </div>
        </div>

        <Transition enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-2 max-h-0" enter-to-class="opacity-100 translate-y-0 max-h-24"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0 max-h-24" leave-to-class="opacity-0 -translate-y-2 max-h-0">
            <div v-if="selectedIds.length > 0"
                class="flex flex-wrap items-center gap-3 overflow-hidden border-y border-gray-200 dark:border-slate-700/60 bg-lavoro-lightblue/60 dark:bg-lavoro-blue/10 px-5 py-3 @2xl:px-6">
                <span class="text-sm font-medium text-lavoro-blue dark:text-blue-300">
                    {{ selectedIds.length }} geselecteerd
                </span>
                <DropdownMenu v-if="mayUpdate && categories.length > 0" placement="bottom-start" width-class="w-56"
                    button-class="inline-flex items-center gap-1.5 rounded-lavoro-sm border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer">
                    <template #button>
                        <FolderIcon class="size-4" />
                        Categorie toewijzen
                        <ChevronDownIcon class="size-4 text-gray-400" />
                    </template>
                    <MenuItem v-for="category in categories" :key="category.id" v-slot="{ active }">
                    <button type="button" @click="assignCategoryToSelection(category.id)"
                        :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm cursor-pointer', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                        <span
                            :class="['size-2.5 flex-none rounded-full', documentCategorySwatchClasses(category.color)]"></span>
                        <span class="truncate">{{ category.name }}</span>
                    </button>
                    </MenuItem>
                </DropdownMenu>
                <button v-if="mayDelete" type="button" @click="bulkDeleteConfirmOpen = true"
                    class="inline-flex items-center gap-1.5 rounded-lavoro-sm border border-red-200 dark:border-red-800/60 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer">
                    <TrashIcon class="size-4" />
                    Verwijderen
                </button>
            </div>
        </Transition>

        <div v-if="maySee && existing.length > 0" class="overflow-x-auto">
            <table class="w-full min-w-[30rem] border-collapse text-left">
                <thead
                    class="border-y border-gray-200 dark:border-slate-700/60 bg-lavoro-lightgray dark:bg-slate-800/60">
                    <tr class="text-sm font-medium text-gray-600 dark:text-slate-300">
                        <th class="w-12 px-5 py-3 @2xl:px-6">
                            <input type="checkbox" :checked="allVisibleSelected" :indeterminate="someVisibleSelected"
                                @change="toggleSelectAll" aria-label="Alles selecteren"
                                class="size-4 rounded border-gray-300 dark:border-slate-600 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
                        </th>
                        <th class="px-3 py-3">
                            <button type="button" @click="toggleSort('name')"
                                class="inline-flex items-center gap-1.5 cursor-pointer hover:text-gray-900 dark:hover:text-slate-100">
                                Naam
                                <component :is="sortIcon('name')" class="size-4 text-gray-400" />
                            </button>
                        </th>
                        <th class="hidden px-3 py-3 @xl:table-cell">Categorie</th>
                        <th class="hidden px-3 py-3 @5xl:table-cell">Bestandsnaam</th>
                        <th class="hidden px-3 py-3 @3xl:table-cell">Grootte</th>
                        <th class="hidden px-3 py-3 @2xl:table-cell">
                            <button type="button" @click="toggleSort('created_at')"
                                class="inline-flex items-center gap-1.5 cursor-pointer hover:text-gray-900 dark:hover:text-slate-100">
                                Aangemaakt
                                <component :is="sortIcon('created_at')" class="size-4 text-gray-400" />
                            </button>
                        </th>
                        <th class="px-5 py-3 text-right @2xl:px-6">Acties</th>
                    </tr>
                </thead>

                <tbody v-auto-animate>
                    <tr v-for="doc in visibleDocuments" :key="doc.id"
                        class="border-b border-gray-100 dark:border-slate-800 last:border-0 hover:bg-gray-50/70 dark:hover:bg-slate-800/40 transition-colors">
                        <td class="px-5 py-3 align-top @2xl:px-6 @xl:align-middle">
                            <input type="checkbox" :value="doc.id" v-model="selectedIds"
                                :aria-label="`${doc.title || doc.name} selecteren`"
                                class="mt-1 size-4 rounded border-gray-300 dark:border-slate-600 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer @xl:mt-0" />
                        </td>

                        <td class="px-3 py-3">
                            <div class="flex items-start gap-3">
                                <span :class="[
                                    'flex size-10 flex-none items-center justify-center rounded-lavoro-sm text-[10px] font-bold ring-1 ring-inset',
                                    documentFileBadge(doc.name).classes
                                ]">{{ documentFileBadge(doc.name).label }}</span>
                                <div class="min-w-0">
                                    <EditableTextField v-if="mayUpdate" :modelValue="doc.title" placeholder="Geen titel"
                                        @update:modelValue="updateTitle(doc.id, $event)" />
                                    <button v-else type="button" @click="openDocument(doc)"
                                        class="block w-full truncate text-left text-sm font-semibold text-gray-900 dark:text-slate-100 hover:text-lavoro-blue cursor-pointer">
                                        {{ doc.title || doc.name }}
                                    </button>
                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-slate-400 @xl:hidden">
                                        <span v-if="doc.category">{{ doc.category.name }}</span>
                                        <span v-if="doc.category && doc.size">·</span>
                                        <span v-if="doc.size">{{ formatFileSize(doc.size) }}</span>
                                    </p>
                                </div>
                            </div>
                        </td>

                        <td class="hidden px-3 py-3 @xl:table-cell">
                            <DropdownMenu v-if="mayUpdate" placement="bottom-start" width-class="w-52"
                                button-class="cursor-pointer">
                                <template #button>
                                    <span v-if="doc.category" :class="[
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset',
                                        documentCategoryPillClasses(doc.category.color)
                                    ]">
                                        <FolderIcon class="size-3.5" />
                                        {{ doc.category.name }}
                                    </span>
                                    <span v-else
                                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-gray-300 dark:border-slate-600 px-2.5 py-1 text-xs text-gray-400 dark:text-slate-500 hover:border-lavoro-blue hover:text-lavoro-blue">
                                        <PlusIcon class="size-3.5" />
                                        Categorie
                                    </span>
                                </template>
                                <MenuItem v-for="category in categories" :key="category.id" v-slot="{ active }">
                                <button type="button" @click="setCategory(doc, category.id)"
                                    :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm cursor-pointer', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                                    <span
                                        :class="['size-2.5 flex-none rounded-full', documentCategorySwatchClasses(category.color)]"></span>
                                    <span class="flex-1 truncate">{{ category.name }}</span>
                                    <CheckIcon v-if="doc.category?.id === category.id"
                                        class="size-4 flex-none text-lavoro-blue" />
                                </button>
                                </MenuItem>
                                <p v-if="categories.length === 0"
                                    class="px-3 py-2 text-sm text-gray-500 dark:text-slate-400">
                                    Nog geen categorieën.
                                </p>
                            </DropdownMenu>
                            <span v-else-if="doc.category" :class="[
                                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset',
                                documentCategoryPillClasses(doc.category.color)
                            ]">
                                <FolderIcon class="size-3.5" />
                                {{ doc.category.name }}
                            </span>
                        </td>

                        <td class="hidden px-3 py-3 @5xl:table-cell">
                            <span class="block max-w-56 truncate text-sm text-gray-500 dark:text-slate-400"
                                :title="doc.name">{{ doc.name }}</span>
                        </td>

                        <td class="hidden px-3 py-3 @3xl:table-cell">
                            <span class="text-sm tabular-nums text-gray-500 dark:text-slate-400">
                                {{ formatFileSize(doc.size) || '—' }}
                            </span>
                        </td>

                        <td class="hidden px-3 py-3 @2xl:table-cell">
                            <span class="block text-sm text-gray-600 dark:text-slate-300">{{ nlDate(doc.created_at)
                                }}</span>
                            <span v-if="doc.user" class="block text-xs text-gray-400 dark:text-slate-500">
                                door {{ doc.user.name }}
                            </span>
                        </td>

                        <td class="px-5 py-3 @2xl:px-6">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" @click="openDocument(doc)" :disabled="!canPreview(doc)"
                                    :title="canPreview(doc) ? 'Bekijken' : 'Geen voorbeeld beschikbaar'"
                                    class="flex size-9 items-center justify-center rounded-lavoro-sm border border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-700 dark:hover:text-slate-200 disabled:cursor-not-allowed disabled:opacity-40 cursor-pointer transition-colors">
                                    <EyeIcon class="size-5" />
                                </button>
                                <a :href="`/documents/${doc.id}/download`" download title="Downloaden"
                                    class="flex size-9 items-center justify-center rounded-lavoro-sm border border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-700 dark:hover:text-slate-200 transition-colors">
                                    <ArrowDownTrayIcon class="size-5" />
                                </a>
                                <DropdownMenu placement="bottom-end" width-class="w-56" title="Meer acties"
                                    button-class="flex size-9 items-center justify-center rounded-lavoro-sm border border-gray-200 dark:border-slate-700 text-gray-500 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-800 hover:text-gray-700 dark:hover:text-slate-200 cursor-pointer transition-colors">
                                    <template #button>
                                        <EllipsisVerticalIcon class="size-5" />
                                    </template>
                                    <MenuItem v-slot="{ active }">
                                    <a :href="`/documents/${doc.id}/preview`" target="_blank" rel="noopener"
                                        :class="['flex items-center gap-2 px-3 py-2 text-sm', active ? 'bg-gray-50 dark:bg-slate-700' : '']">
                                        <ArrowTopRightOnSquareIcon class="size-4 text-gray-400" />
                                        Openen in nieuw tabblad
                                    </a>
                                    </MenuItem>
                                    <MenuItem v-if="mayDelete" v-slot="{ active }">
                                    <button type="button" @click="confirmDelete(doc)"
                                        :class="['flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 cursor-pointer', active ? 'bg-red-50 dark:bg-red-900/20' : '']">
                                        <TrashIcon class="size-4" />
                                        Verwijderen
                                    </button>
                                    </MenuItem>
                                </DropdownMenu>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="visibleDocuments.length === 0">
                        <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                            Geen documenten gevonden.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="showProgress" class="border-t border-gray-200 dark:border-slate-700/60 px-5 py-4 @2xl:px-6">
            <div class="flex items-baseline justify-between gap-3 text-sm text-gray-600 dark:text-slate-300">
                <span>{{ progressLabel }}</span>
                <span class="tabular-nums">
                    {{ queueCompleted }} / {{ queueTotal }} · {{ progressPercentage }}%
                </span>
            </div>
            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-slate-700">
                <div class="h-full rounded-full transition-all duration-300"
                    :class="queueHasFailures ? 'bg-red-500' : 'bg-lavoro-blue'"
                    :style="{ width: `${progressPercentage}%` }"></div>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                <span v-if="queueHasFailures" class="text-red-600 dark:text-red-400">{{ queueFailed }} mislukt</span>
                <span v-else></span>
                <div class="flex items-center gap-4">
                    <button v-if="queueHasFailures" type="button" @click="retryFailed()" :disabled="uploading"
                        class="font-medium text-lavoro-blue hover:underline disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        Opnieuw proberen
                    </button>
                    <button type="button" @click="cancelUploads()"
                        class="font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 hover:underline cursor-pointer">
                        {{ uploading ? 'Uploaden annuleren' : 'Sluiten' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="mayUpload" class="p-5 @2xl:p-6">
            <div @click="openFilePicker" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop" :class="[
                    'relative overflow-hidden rounded-lavoro-md border-2 border-dashed px-6 py-10 transition-colors cursor-pointer',
                    isDragging
                        ? 'border-lavoro-blue bg-lavoro-blue/5 dark:border-lavoro-blue dark:bg-lavoro-blue/10'
                        : 'border-gray-200 dark:border-slate-700 bg-lavoro-gray-150 dark:bg-slate-800/60'
                ]">
                <!-- Anchored near the bottom-right corner at every size; only the
                     scale changes. Height and width stay explicit because the div
                     is empty — an auto height would collapse it to nothing. Drops
                     out below @md alongside the copy, where it has no room left. -->
                <div aria-hidden="true"
                    class="pointer-events-none absolute bottom-4 right-4 hidden h-40 w-56 bg-[url('/img/doc-ill.png')] bg-contain bg-right-bottom bg-no-repeat opacity-75
                           @md:block @4xl:h-56 @4xl:w-80 @4xl:opacity-100">
                </div>

                <div class="relative flex flex-col items-center text-center">
                    <div
                        class="flex size-14 items-center justify-center rounded-full bg-lavoro-blue/10 dark:bg-lavoro-blue/20">
                        <CloudArrowUpIcon class="size-7 text-lavoro-blue" />
                    </div>
                    <!-- Below this width the copy wraps into a wall of text taller
                         than the drop zone itself. The icon and the button already
                         say what this is, so the prose goes rather than the action. -->
                    <div class="mt-4 hidden @md:block">
                        <p class="text-base font-semibold text-gray-900 dark:text-slate-100">
                            Sleep bestanden hierheen of klik om te selecteren
                        </p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                            Ondersteunde formaten: {{ SUPPORTED_FORMATS }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-slate-400">Max. {{ MAX_FILE_MB }}MB per bestand</p>
                    </div>
                    <p v-if="uploadTargetCategory" class="mt-1 text-sm text-lavoro-blue">
                        Nieuwe bestanden komen in "{{ uploadTargetCategory.name }}"
                    </p>
                    <button type="button" @click.stop="openFilePicker"
                        class="mt-5 inline-flex items-center gap-2 rounded-lavoro-sm bg-lavoro-blue px-5 py-2.5 text-sm font-medium text-white hover:opacity-90 transition-opacity cursor-pointer">
                        <DocumentIcon class="size-5" />
                        Bestanden selecteren
                    </button>
                </div>
            </div>
        </div>

        <!-- Kept outside the drop zone: .click() on a nested input bubbles back
             into the zone's own click handler. -->
        <input v-if="mayUpload" ref="fileInput" type="file" class="hidden" multiple :accept="ACCEPT"
            @change="handleFiles" />

        <PdfViewerOverlay :open="viewingDoc !== null" :url="viewingDoc ? `/documents/${viewingDoc.id}/download` : ''"
            :title="viewingDoc ? (viewingDoc.title || viewingDoc.name) : ''" @update:open="viewingDoc = null" />

        <SpreadsheetViewerOverlay :open="viewingSheet !== null"
            :url="viewingSheet ? `/documents/${viewingSheet.id}/download` : ''"
            :title="viewingSheet ? (viewingSheet.title || viewingSheet.name) : ''" @update:open="viewingSheet = null" />

        <ModalDialog :open="categoryModalOpen" @update:open="categoryModalOpen = $event" title="Categorie aanmaken"
            max-width-class="sm:max-w-md">
            <div class="space-y-4">
                <TextInput v-model="categoryForm.name" label="Naam" placeholder="Bijvoorbeeld: Facturen"
                    :hasError="Boolean(categoryForm.errors.name)" :errorMessage="categoryForm.errors.name" />
                <div>
                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-300">Kleur</span>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button v-for="color in documentCategoryColors" :key="color" type="button"
                            @click="categoryForm.color = color" :aria-label="color" :class="[
                                'size-9 rounded-full transition-transform cursor-pointer',
                                documentCategorySwatchClasses(color),
                                categoryForm.color === color
                                    ? 'ring-2 ring-offset-2 ring-lavoro-blue dark:ring-offset-slate-800 scale-110'
                                    : 'hover:scale-105'
                            ]"></button>
                    </div>
                    <p v-if="categoryForm.errors.color" class="mt-2 text-sm text-red-600">
                        {{ categoryForm.errors.color }}
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="categoryModalOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">
                        Annuleren
                    </button>
                    <button type="button" :disabled="categoryForm.processing" @click="submitCategory"
                        class="rounded-lavoro-sm bg-lavoro-blue px-4 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity cursor-pointer">
                        Aanmaken
                    </button>
                </div>
            </template>
        </ModalDialog>

        <ModalDialog :open="deleteConfirmOpen" @update:open="deleteConfirmOpen = $event" max-width-class="sm:max-w-sm">
            <div class="gap-4 sm:flex sm:items-start">
                <div
                    class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:size-10">
                    <ExclamationTriangleIcon class="size-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 text-center sm:mt-0 sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Document verwijderen</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Weet je zeker dat je "{{ pendingDelete?.title || pendingDelete?.name }}" wilt verwijderen?
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteConfirmOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">
                        Annuleren
                    </button>
                    <button type="button" @click="performDelete"
                        class="rounded-lavoro-sm bg-red-600 px-4 py-1.5 text-sm text-white hover:opacity-90 transition-opacity cursor-pointer">
                        Verwijderen
                    </button>
                </div>
            </template>
        </ModalDialog>

        <ModalDialog :open="bulkDeleteConfirmOpen" @update:open="bulkDeleteConfirmOpen = $event"
            max-width-class="sm:max-w-sm">
            <div class="gap-4 sm:flex sm:items-start">
                <div
                    class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:size-10">
                    <ExclamationTriangleIcon class="size-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 text-center sm:mt-0 sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Documenten verwijderen</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Weet je zeker dat je {{ selectedIds.length }} document(en) wilt verwijderen?
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="bulkDeleteConfirmOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">
                        Annuleren
                    </button>
                    <button type="button" @click="performBulkDelete"
                        class="rounded-lavoro-sm bg-red-600 px-4 py-1.5 text-sm text-white hover:opacity-90 transition-opacity cursor-pointer">
                        Verwijderen
                    </button>
                </div>
            </template>
        </ModalDialog>
    </div>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { MenuItem } from '@headlessui/vue';
import {
    ArrowDownTrayIcon,
    ArrowTopRightOnSquareIcon,
    CheckIcon,
    ChevronDownIcon,
    ChevronUpDownIcon,
    ChevronUpIcon,
    CloudArrowUpIcon,
    DocumentIcon,
    EllipsisVerticalIcon,
    ExclamationTriangleIcon,
    EyeIcon,
    FolderIcon,
    FolderPlusIcon,
    MagnifyingGlassIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';
import DropdownMenu from '@/Components/UI/DropdownMenu.vue';
import EditableTextField from '@/Components/UI/EditableTextField.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import PdfViewerOverlay from '@/Components/UI/PdfViewerOverlay.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import SpreadsheetViewerOverlay from '@/Components/UI/SpreadsheetViewerOverlay.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { useUploadQueue } from '@/Composables/useUploadQueue.js';
import {
    documentCategoryColors,
    documentCategoryPillClasses,
    documentCategorySwatchClasses,
    documentExtension,
    documentFileBadge,
    formatFileSize,
    hasPermission,
    nlDate,
    subjectSubtitle,
} from '@/Utilities/Utilities';

const props = defineProps({
    documentableId: { type: Number, required: true },
    documentableType: { type: String, required: true },
    existing: { type: Array, default: () => [] },
    internal: { type: Boolean, default: false },
    title: { type: String, default: 'Documenten' },
    /** Overrides the subject phrase derived from documentableType. */
    subtitle: { type: String, default: '' },
});

const resolvedSubtitle = computed(() => props.subtitle || subjectSubtitle(
    props.documentableType,
    (subject) => `Alle relevante documenten bij ${subject}, overzichtelijk en veilig opgeslagen.`,
    'Alle relevante documenten, overzichtelijk en veilig opgeslagen.',
));

// Mirrors DocumentStoreRequest's mimes/max rule — the dropzone must not promise
// more than the server accepts.
const MAX_FILE_MB = 20;
const EXTENSIONS = [
    'pdf', 'odt', 'odf', 'ods', 'doc', 'docx', 'xls', 'xlsx', 'csv',
    'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png',
];
const ACCEPT = EXTENSIONS.map((extension) => `.${extension}`).join(',');
const SUPPORTED_FORMATS = 'PDF, DOC, DOCX, XLS, XLSX, CSV, PPT, TXT, JPG, PNG';
const SPREADSHEETS = ['xls', 'xlsx', 'csv', 'ods'];
const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const PREVIEWABLE = ['pdf', ...SPREADSHEETS, ...IMAGES];

// Sentinel for the "Zonder categorie" tab, so it can't collide with a real id.
const UNCATEGORIZED = 'none';

const page = usePage();

const maySee = computed(() => hasPermission('document.see'));
const mayUpload = computed(() => hasPermission('document.upload'));
const mayUpdate = computed(() => hasPermission('document.update'));
const mayDelete = computed(() => hasPermission('document.delete'));
const mayManageCategories = computed(() => hasPermission('documentcategory.manage'));
const mayShow = computed(() => (maySee.value && props.existing.length > 0) || mayUpload.value);

const categories = computed(() => page.props.documentCategories ?? []);

const search = ref('');
const activeCategoryId = ref(null);
const sortKey = ref('created_at');
const sortAscending = ref(false);
const selectedIds = ref([]);
const isDragging = ref(false);
const viewingDoc = ref(null);
const viewingSheet = ref(null);
const fileInput = ref(null);

/**
 * Tallied once per document list rather than per tab: called from the template,
 * a filter per category re-walks every document on every single re-render.
 * Uncategorised documents are counted under the UNCATEGORIZED sentinel.
 */
const categoryCounts = computed(() => {
    const counts = new Map();

    props.existing.forEach((doc) => {
        const key = doc.category?.id ?? UNCATEGORIZED;
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return counts;
});

const countFor = (categoryId) => categoryCounts.value.get(categoryId) ?? 0;

function toggleCategoryFilter(categoryId) {
    // Clicking the active tab clears the filter rather than re-applying it.
    activeCategoryId.value = activeCategoryId.value === categoryId ? null : categoryId;
}

function toggleSort(key) {
    if (sortKey.value === key) {
        sortAscending.value = !sortAscending.value;

        return;
    }

    sortKey.value = key;
    sortAscending.value = key === 'name';
}

const sortIcon = (key) => {
    if (sortKey.value !== key) return ChevronUpDownIcon;

    return sortAscending.value ? ChevronUpIcon : ChevronDownIcon;
};

const sortValue = (doc) => (
    sortKey.value === 'name'
        ? (doc.title || doc.name || '').toLowerCase()
        : doc.created_at ?? ''
);

const visibleDocuments = computed(() => {
    const term = search.value.trim().toLowerCase();

    const filtered = props.existing.filter((doc) => {
        if (activeCategoryId.value === UNCATEGORIZED && doc.category) return false;
        if (activeCategoryId.value !== null && activeCategoryId.value !== UNCATEGORIZED
            && doc.category?.id !== activeCategoryId.value) return false;

        if (!term) return true;

        return `${doc.title ?? ''} ${doc.name ?? ''} ${doc.category?.name ?? ''}`
            .toLowerCase()
            .includes(term);
    });

    return [...filtered].sort((left, right) => {
        const comparison = String(sortValue(left)).localeCompare(String(sortValue(right)), 'nl', { numeric: true });

        return sortAscending.value ? comparison : -comparison;
    });
});

const visibleIds = computed(() => visibleDocuments.value.map((doc) => doc.id));
const allVisibleSelected = computed(() => (
    visibleIds.value.length > 0 && visibleIds.value.every((id) => selectedIds.value.includes(id))
));
const someVisibleSelected = computed(() => selectedIds.value.length > 0 && !allVisibleSelected.value);

function toggleSelectAll() {
    selectedIds.value = allVisibleSelected.value ? [] : [...visibleIds.value];
}

// A row that scrolled out of the filter can't be acted on, so drop it from the
// selection instead of silently deleting something the user can no longer see.
watch(visibleIds, (ids) => {
    selectedIds.value = selectedIds.value.filter((id) => ids.includes(id));
});

const uploadTargetCategory = computed(() => (
    categories.value.find((category) => category.id === activeCategoryId.value) ?? null
));

const canPreview = (doc) => PREVIEWABLE.includes(documentExtension(doc.name));

function openDocument(doc) {
    const extension = documentExtension(doc.name);

    if (extension === 'pdf') {
        viewingDoc.value = doc;
    } else if (SPREADSHEETS.includes(extension)) {
        viewingSheet.value = doc;
    } else if (IMAGES.includes(extension)) {
        window.open(`/documents/${doc.id}/preview`, '_blank', 'noopener');
    }
}

let csrf_request = null;

const ensureCsrfCookie = () => {
    if (!csrf_request) {
        csrf_request = axios.get('/sanctum/csrf-cookie');
    }

    return csrf_request;
};

const uploadChunk = async (batch, onProgress, signal) => {
    await ensureCsrfCookie();

    const data = new FormData();

    batch.forEach((item) => data.append('documents[]', item.prepared));

    data.append('documentable_id', props.documentableId);
    data.append('documentable_type', props.documentableType);
    data.append('internal', props.internal ? '1' : '0');

    if (uploadTargetCategory.value) {
        data.append('document_category_id', uploadTargetCategory.value.id);
    }

    await axios.post('/documents', data, {
        headers: { Accept: 'application/json' },
        onUploadProgress: (event) => event.total && onProgress(event.loaded / event.total),
        signal,
    });
};

// The finished run is left standing at 100% — the queue clears it itself on the
// next selection — so the user gets a completed state to read.
const onQueueDrained = () => router.reload({ preserveScroll: true, preserveState: true });

const {
    isActive: uploading,
    total: queueTotal,
    completed: queueCompleted,
    failed: queueFailed,
    hasFailures: queueHasFailures,
    percentage: progressPercentage,
    enqueue,
    retryFailed,
    cancel: cancelUploads,
    dispose: disposeQueue,
} = useUploadQueue({
    uploadChunk,
    onDrained: onQueueDrained,
    // Documents are heavy compared to photos, so a chunk is capped on weight as
    // well as count to stay under PHP's post_max_size.
    chunkSize: 4,
});

const showProgress = computed(() => queueTotal.value > 0);

const progressLabel = computed(() => {
    if (uploading.value) return 'Bezig met uploaden…';
    if (queueHasFailures.value) return 'Uploaden mislukt';

    return 'Uploaden afgerond';
});

const openFilePicker = () => fileInput.value?.click();

const handleFiles = (event) => {
    enqueue(Array.from(event.target.files));
    event.target.value = '';
};

const handleDrop = (event) => {
    isDragging.value = false;
    enqueue(Array.from(event.dataTransfer.files));
};

function updateTitle(id, title) {
    router.put(`/documents/${id}`, { title }, { preserveScroll: true, preserveState: true });
}

function setCategory(doc, categoryId) {
    // Re-picking the assigned category clears it — no separate "geen categorie" item.
    const next = doc.category?.id === categoryId ? null : categoryId;

    router.put(`/documents/${doc.id}`, { document_category_id: next }, {
        preserveScroll: true,
        preserveState: true,
    });
}

function assignCategoryToSelection(categoryId) {
    router.put('/documents/bulk-category', {
        ids: selectedIds.value,
        document_category_id: categoryId,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { selectedIds.value = []; },
    });
}

const deleteConfirmOpen = ref(false);
const bulkDeleteConfirmOpen = ref(false);
const pendingDelete = ref(null);

function confirmDelete(doc) {
    pendingDelete.value = doc;
    deleteConfirmOpen.value = true;
}

function performDelete() {
    if (!pendingDelete.value) return;

    router.delete(`/documents/${pendingDelete.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteConfirmOpen.value = false;
            pendingDelete.value = null;
        },
    });
}

function performBulkDelete() {
    router.delete('/documents/bulk', {
        data: { ids: selectedIds.value },
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
        onFinish: () => { bulkDeleteConfirmOpen.value = false; },
    });
}

const categoryModalOpen = ref(false);
const categoryForm = useForm({ name: '', color: 'blue' });

function openCategoryModal() {
    categoryForm.reset();
    categoryForm.clearErrors();
    categoryModalOpen.value = true;
}

function submitCategory() {
    categoryForm.post('/documentcategories', {
        preserveScroll: true,
        onSuccess: () => {
            categoryModalOpen.value = false;
            categoryForm.reset();
        },
    });
}

onUnmounted(disposeQueue);
</script>
