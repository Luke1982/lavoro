<template>
    <div class="w-full mx-auto" v-if="mayUpload() || maySee()" v-tray-drop="photoDrop">
        <SectionHeader v-if="!bare" :icon="PhotoIcon" :title="title" :subtitle="resolvedSubtitle" :internal="internal"
            chapter="photos" />
        <div v-if="mayUpload()" @dragover="handleDragOver" @dragleave.prevent="isDragging = false"
            @drop="handleDrop" :class="[
                'flex flex-col items-center justify-center w-full rounded-lavoro-sm border-2 border-dashed py-8 px-4 transition-colors',
                isDragging ? 'bg-lavoro-blue/5 border-lavoro-blue dark:bg-slate-700 dark:border-slate-500' : 'bg-gray-50 border-gray-300 dark:bg-slate-800/60 dark:border-slate-600'
            ]">
            <CloudArrowUpIcon class="size-9 text-slate-400 dark:text-slate-500" />
            <p class="mt-3 text-sm text-gray-600 dark:text-slate-300">Sleep afbeeldingen hierheen</p>
            <p class="text-sm text-gray-400 dark:text-slate-500">of</p>
            <div class="flex gap-3 mt-3">
                <button @click="openCamera"
                    class="inline-flex items-center gap-1.5 rounded-lavoro-sm border border-lavoro-blue px-4 py-1.5 text-sm font-medium text-lavoro-blue hover:bg-lavoro-blue hover:text-white transition-colors cursor-pointer">
                    <CameraIcon class="size-4" />
                    Camera
                </button>
                <button @click="openFilePicker"
                    class="inline-flex items-center gap-1.5 rounded-lavoro-sm border border-lavoro-blue px-4 py-1.5 text-sm font-medium text-lavoro-blue hover:bg-lavoro-blue hover:text-white transition-colors cursor-pointer">
                    <PhotoIcon class="size-4" />
                    Galerij
                </button>
            </div>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="handleFiles" multiple />
            <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden"
                @change="handleFiles" />

            <div v-if="showProgress" class="mt-4 w-full max-w-sm">
                <div class="flex items-baseline justify-between text-sm text-gray-600 dark:text-slate-300">
                    <span>{{ uploading ? 'Bezig met uploaden…' : 'Uploaden afgerond' }}</span>
                    <span class="tabular-nums">{{ queueCompleted }} / {{ queueTotal }}</span>
                </div>
                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-slate-700">
                    <div class="h-full rounded-full bg-lavoro-blue transition-all duration-300"
                        :style="{ width: `${progressPercentage}%` }"></div>
                </div>
                <div v-if="queueHasFailures" class="mt-2 flex items-center justify-between gap-3 text-sm">
                    <span class="text-red-600 dark:text-red-400">{{ queueFailed }} mislukt</span>
                    <button type="button" @click="retryFailed()" :disabled="uploading"
                        class="font-medium text-lavoro-blue dark:text-blue-400 hover:underline disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        Opnieuw proberen
                    </button>
                </div>
                <ul v-if="queueHasFailures"
                    class="mt-1 max-h-32 space-y-0.5 overflow-y-auto text-xs text-red-600 dark:text-red-400">
                    <li v-for="item in failedItems" :key="item.id" class="break-words">
                        <span class="font-medium">{{ item.name }}</span> — {{ item.error }}
                    </li>
                </ul>
            </div>
        </div>
        <div v-else-if="maySee() && thumbnails.length === 0"
            class="text-center text-sm text-gray-400 dark:text-slate-500 py-5 rounded-lavoro-sm border border-dashed border-gray-200 dark:border-slate-700">
            Er zijn nog geen afbeeldingen.
        </div>

        <div v-if="thumbnails.length > 0" data-tour="photos.select"
            class="mt-4 flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
            <p v-if="selecting" class="mr-auto min-w-0 text-sm text-gray-500 dark:text-slate-400">
                <span class="hidden sm:inline">Klik op foto's om ze te kiezen, met Shift een hele reeks.</span>
                <span class="sm:hidden">Tik op foto's om ze te kiezen.</span>
            </p>
            <button v-if="selecting" type="button" @click="toggleAll" :class="SELECTION_BUTTON">
                {{ allSelected ? 'Niets' : 'Alles' }}
            </button>
            <button type="button" @click="selecting ? stopSelecting() : startSelecting()"
                :aria-pressed="selecting" :class="SELECTION_BUTTON">
                <CheckCircleIcon v-if="!selecting" class="size-4" aria-hidden="true" />
                {{ selecting ? 'Klaar' : 'Selecteren' }}
            </button>
        </div>

        <SelectionBar :count="selectedIds.length" noun="foto's" @clear="stopSelecting">
            <TraySelectionButton kind="image" :ids="selectedIds" @placed="stopSelecting" />
            <button v-if="mayDelete()" type="button" :disabled="ownSelected.length === 0" @click="confirmDelete(ownSelected)"
                class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                <TrashIcon class="size-4" aria-hidden="true" />
                Verwijderen
            </button>
        </SelectionBar>

        <div v-if="thumbnails.length > 0" class="relative mt-2">
            <div ref="emblaRef" class="overflow-hidden">
                <div class="flex gap-2">
                    <div v-for="{ image, received } in thumbnails" :key="image.id" :title="image.name"
                        @click.capture="clickToSelect($event, image.id) && $event.stopPropagation()" :class="[
                        'group relative size-24 flex-none cursor-pointer rounded-lavoro-sm overflow-hidden border-2 transition-colors',
                        selecting ? 'select-none' : '',
                        isSelected(image.id) ? 'border-lavoro-blue bg-lavoro-blue/10' : image.pivot?.main ? 'border-yellow-400' : 'border-gray-200 dark:border-slate-700'
                    ]">
                        <a :href="imageFileUrl(image)" data-type="image" class="glightbox block size-full"
                            :role="selecting ? 'checkbox' : null" :aria-checked="selecting ? isSelected(image.id) : null"
                            @click.capture="captureScrollBeforeOpen" @dblclick.stop.prevent="!selecting && !received && mayUpdate() && changeTitle(image.name, image.id)">
                            <img :src="imageFileUrl(image)" :alt="image.name" :class="[
                                'size-full object-cover transition-transform duration-200 motion-reduce:transition-none',
                                isSelected(image.id) ? 'scale-[0.82] rounded-md' : ''
                            ]">
                            <span v-if="received || image.is_shared" :title="received ? 'Gedeeld vanuit het project' : 'Gedeeld met de werkbonnen'"
                                class="absolute bottom-1 right-1 flex items-center justify-center rounded-full bg-lavoro-blue p-1 shadow-md">
                                <ShareIconSolid class="size-3 text-white" />
                            </span>
                        </a>
                        <span v-if="selecting" aria-hidden="true" :class="[
                            'pointer-events-none absolute left-1 top-1 flex size-5 items-center justify-center rounded-full border-2 shadow-md transition-colors',
                            isSelected(image.id) ? 'border-lavoro-blue bg-lavoro-blue text-white' : 'border-white bg-black/25'
                        ]">
                            <CheckIcon v-if="isSelected(image.id)" class="size-3 stroke-[3]" />
                        </span>
                        <div v-if="image.pivot?.main"
                            class="pointer-events-none absolute bottom-1 left-1 flex items-center justify-center rounded-full bg-yellow-400 p-1 shadow-md">
                            <StarIconSolid class="size-3 text-white" />
                        </div>
                        <div v-if="!selecting && !received && (mayEdit() || mayUpdate() || mayDelete())"
                            class="pointer-events-none absolute inset-x-1 top-1 hidden items-center justify-end gap-1 opacity-0 transition-opacity sm:flex group-hover:opacity-100 group-hover:pointer-events-auto">
                            <button v-if="mayEdit()" @click.stop="openEditor(image)" title="Bewerken"
                                class="flex items-center justify-center rounded-full bg-white dark:bg-slate-900 shadow-md p-1.5 text-gray-700 dark:text-slate-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-800">
                                <PencilSquareIcon class="size-4" />
                            </button>
                            <button v-if="mayShare()" @click.stop="toggleShared(image)"
                                class="flex items-center justify-center rounded-full bg-white dark:bg-slate-900 shadow-md p-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-800"
                                :class="image.is_shared ? 'text-lavoro-blue dark:text-blue-400' : 'text-gray-700 dark:text-slate-300'"
                                :title="image.is_shared ? 'Niet meer delen met de werkbonnen' : 'Delen met de werkbonnen van dit project'">
                                <ShareIconSolid v-if="image.is_shared" class="size-4" />
                                <ShareIcon v-else class="size-4" />
                            </button>
                            <button v-if="mayUpdate()" @click.stop="setMain(image.id, image.pivot?.main)"
                                class="flex items-center justify-center rounded-full bg-white dark:bg-slate-900 shadow-md p-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-800"
                                :class="image.pivot?.main ? 'text-yellow-500' : 'text-gray-700 dark:text-slate-300'"
                                :title="image.pivot?.main ? 'Dit is de hoofdafbeelding' : 'Instellen als hoofdafbeelding'">
                                <StarIconSolid v-if="image.pivot?.main" class="size-4" />
                                <StarIcon v-else class="size-4" />
                            </button>
                            <button v-if="mayDelete()" @click.stop="confirmDelete([image])" title="Verwijder deze afbeelding"
                                class="flex items-center justify-center rounded-full bg-white dark:bg-slate-900 shadow-md p-1.5 text-red-600 dark:text-red-400 cursor-pointer hover:bg-gray-100 dark:hover:bg-slate-800">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                        <ImageThumbnailMenu v-if="!selecting && !received" :image="image"
                            :can-set-main="mayUpdate()" :can-share="mayShare()" :can-edit="mayEdit()" :can-delete="mayDelete()"
                            can-select @select="startSelecting(image.id)"
                            @view="openLightboxFor(image)" @favorite="setMain(image.id, image.pivot?.main)" @share="toggleShared(image)"
                            @annotate="openEditor(image)" @delete="confirmDelete([image])" />
                    </div>
                </div>
            </div>
            <button @click="scrollThumbsPrev" title="Vorige foto's tonen" :class="[
                'absolute inset-y-0 left-0 z-10 flex w-14 items-center justify-start bg-gradient-to-r from-white/80 to-transparent pl-1 transition-opacity dark:from-slate-900/80 cursor-pointer',
                canScrollThumbsPrev ? 'opacity-100' : 'pointer-events-none opacity-0'
            ]">
                <span class="flex size-8 flex-none items-center justify-center rounded-full bg-white shadow-md ring-1 ring-gray-900/10 text-gray-700 dark:bg-slate-800 dark:ring-slate-600 dark:text-slate-300">
                    <ChevronLeftIcon class="size-4" />
                </span>
            </button>
            <button @click="scrollThumbsNext" title="Meer foto's tonen" :class="[
                'absolute inset-y-0 right-0 z-10 flex w-14 items-center justify-end bg-gradient-to-l from-white/80 to-transparent pr-1 transition-opacity dark:from-slate-900/80 cursor-pointer',
                canScrollThumbsNext ? 'opacity-100' : 'pointer-events-none opacity-0'
            ]">
                <span class="flex size-8 flex-none items-center justify-center rounded-full bg-white shadow-md ring-1 ring-gray-900/10 text-gray-700 dark:bg-slate-800 dark:ring-slate-600 dark:text-slate-300">
                    <ChevronRightIcon class="size-4" />
                </span>
            </button>
        </div>

        <div class="mt-4" v-if="previewBeforeUpload && stagedItems.length > 0">
            <h3 class="text-lg font-semibold">Deze foto's wil je uploaden</h3>
            <div class="grid grid-cols-4 gap-4 mt-2">
                <div v-for="item in stagedItems" :key="item.id"
                    class="text-center rounded-md border border-gray-00 dark:border-slate-800 p-5 col-span-4 md:col-span-2 relative">
                    <img v-if="item.previewUrl" :src="item.previewUrl" alt="Image preview"
                        class="w-24 h-24 object-cover rounded-lg border border-gray-300 dark:border-slate-600 inline-block" />
                    <p v-else class="text-gray-700 dark:text-slate-300">{{ item.name }}</p>
                    <p class="text-sm text-gray-500 dark:text-slate-400 truncate mt-1">{{ item.name }}</p>
                    <input type="text" v-model="item.title" class="rounded-md border-gray-200 dark:border-slate-700 my-2 w-full"
                        placeholder="Titel">
                    <TrashIcon class="h-5 w-5 text-red-500 cursor-pointer absolute top-2 right-2"
                        @click="removeStaged(item.id)" />
                </div>
            </div>
        </div>
        <button v-if="previewBeforeUpload && stagedItems.length > 0" :disabled="uploading"
            :class="[uploading ? 'bg-slate-500' : 'bg-lavoro-blue', 'w-full text-white rounded-md p-3 mt-3 font-bold']"
            @click="uploadPhotos">Verzenden</button>

        <ImageAnnotator v-if="imageToEdit" :image="imageToEdit" :imageable-type="imageableType"
            :imageable-id="imageableId" :persist="saveAnnotatedImage" @close="imageToEdit = null" />

        <ModalDialog :open="deleteConfirmOpen" @update:open="deleteConfirmOpen = $event" max-width-class="sm:max-w-sm">
            <div class="sm:flex sm:items-start gap-4">
                <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:size-10">
                    <ExclamationTriangleIcon class="size-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 sm:mt-0 text-center sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ pendingDelete.length > 1 ? `${pendingDelete.length} afbeeldingen verwijderen` : 'Afbeelding verwijderen' }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        <template v-if="pendingDelete.length > 1">Weet je zeker dat je deze {{ pendingDelete.length }} afbeeldingen wilt verwijderen?</template>
                        <template v-else>Weet je zeker dat je{{ pendingDelete[0]?.name ? ` "${pendingDelete[0].name}"` : ' deze afbeelding' }} wilt verwijderen?</template>
                    </p>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="deleteConfirmOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">
                        Annuleren
                    </button>
                    <button type="button" :disabled="deletingImage" @click="performPendingDelete"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-red-600 text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity cursor-pointer">
                        Verwijderen
                    </button>
                </div>
            </template>
        </ModalDialog>
    </div>
</template>

<script setup>
import {
    ref, computed, watch, onUnmounted, onMounted, nextTick, defineAsyncComponent

} from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { TrashIcon, StarIcon, CameraIcon, PhotoIcon, PencilSquareIcon, CloudArrowUpIcon, ChevronLeftIcon, ChevronRightIcon, ExclamationTriangleIcon, ShareIcon, CheckCircleIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid, ShareIcon as ShareIconSolid } from '@heroicons/vue/24/solid';
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';
import emblaCarouselVue from 'embla-carousel-vue';
import ImageThumbnailMenu from '@/Components/UI/ImageThumbnailMenu.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { hasPermission, morphBasename, subjectSubtitle } from '@/Utilities/Utilities.js';
import { useScrollLock } from '@/Composables/useScrollLock.js';
import { useUploadQueue } from '@/Composables/useUploadQueue.js';
import { useImageCompression } from '@/Composables/useImageCompression.js';
import { imageFileUrl } from '@/Utilities/imageFiles.js';
import { vTrayDrop } from '@/Utilities/trayDrop.js';
import { isTrayDrag } from '@/Composables/useTray.js';
import { useClickSelection } from '@/Composables/useClickSelection.js';
import { requestFailureMessage } from '@/Utilities/requestFailure.js';
import SelectionBar from '@/Components/UI/SelectionBar.vue';
import TraySelectionButton from '@/Components/Tray/TraySelectionButton.vue';

const ImageAnnotator = defineAsyncComponent(() => import('@/Components/Images/ImageAnnotator.vue'));

let lightbox = null;
const { captureScroll: captureScrollBeforeOpen, lock: lockBodyScroll, unlock: unlockBodyScroll } = useScrollLock();

const [emblaRef, emblaApi] = emblaCarouselVue({ align: 'start', dragFree: true, containScroll: 'trimSnaps' });
const canScrollThumbsPrev = ref(false);
const canScrollThumbsNext = ref(false);

function updateScrollAffordance() {
    canScrollThumbsPrev.value = emblaApi.value?.canScrollPrev() ?? false;
    canScrollThumbsNext.value = emblaApi.value?.canScrollNext() ?? false;
}

watch(emblaApi, (api) => {
    if (!api) return;
    api.on('select', updateScrollAffordance);
    api.on('reInit', updateScrollAffordance);
    updateScrollAffordance();
});

function scrollThumbsPrev() {
    emblaApi.value?.scrollPrev();
}

function scrollThumbsNext() {
    emblaApi.value?.scrollNext();
}

onMounted(() => {
    lightbox = GLightbox({
        selector: '.glightbox',
        touchNavigation: true,
        loop: true,
        zoomable: true,
        closeEffect: 'none', // no animation delay before the page is unlocked
    });
    lightbox.on('open', lockBodyScroll);
    lightbox.on('close', unlockBodyScroll);
});

const page = usePage();

const emit = defineEmits(['imagesUploaded', 'imageUpdated', 'imageDeleted']);

const props = defineProps({
    imageableId: Number,
    imageableType: String,
    existing: Array,
    /** Photos shared here from elsewhere: shown after the own ones, to look at only. */
    shared: {
        type: Array,
        default: () => []
    },
    internal: {
        type: Boolean,
        default: false
    },
    apiMode: {
        type: Boolean,
        default: false
    },
    canManage: {
        type: [Boolean, null],
        default: null
    },
    previewBeforeUpload: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        default: 'Foto\'s'
    },
    /** Overrides the subject phrase derived from imageableType. */
    subtitle: {
        type: String,
        default: ''
    },
    /** Without its own heading, for a box that already says what it holds. */
    bare: {
        type: Boolean,
        default: false
    },
});

const resolvedSubtitle = computed(() => props.subtitle || (props.internal
    ? subjectSubtitle(
        props.imageableType,
        (subject) => `Foto's bij ${subject}, alleen voor intern gebruik.`,
        'Foto\'s, alleen voor intern gebruik.',
    )
    : subjectSubtitle(
        props.imageableType,
        (subject) => `Beeldmateriaal bij ${subject}, direct vanaf de camera of uit de galerij.`,
        'Beeldmateriaal, direct vanaf de camera of uit de galerij.',
    )));

const thumbnails = computed(() => [
    ...props.existing.map((image) => ({ image, received: false })),
    ...props.shared.map((image) => ({ image, received: true })),
]);

const thumbnailSignature = computed(() => thumbnails.value.map(({ image }) => `${image.id}:${image.path}`).join(','));

watch(thumbnailSignature, () => {
    nextTick(() => {
        lightbox && lightbox.reload();
        emblaApi.value?.reInit();
    });
});

const mayUpload = () => props.canManage !== null ? props.canManage : hasPermission('image.upload');
const maySee = () => props.canManage !== null ? props.canManage : hasPermission('image.see');
const mayEdit = () => props.canManage !== null ? props.canManage : hasPermission('image.edit');
const mayUpdate = () => props.canManage !== null ? props.canManage : hasPermission('image.update');
const mayDelete = () => props.canManage !== null ? props.canManage : hasPermission('image.delete');

/** Only a project's photos have somewhere to be shared to: the work orders under it. */
const mayShare = () => !props.apiMode && morphBasename(props.imageableType) === 'Project' && mayUpdate();

function openLightboxFor(image) {
    const index = props.existing.findIndex(existingImage => existingImage.id === image.id);
    if (lightbox && index !== -1) {
        captureScrollBeforeOpen();
        lightbox.openAt(index);
    }
}

const uploadImagesForm = useForm({
    imageable_id: props.imageableId,
    imageable_type: props.imageableType,
    newTitle: '',
    internal: props.internal,
});

const fileInput = ref(null);
const cameraInput = ref(null);
const isDragging = ref(false);

const imagesEndpoint = computed(() => (props.apiMode ? '/api/images' : '/images'));

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

    batch.forEach((item, index) => {
        data.append(`images[${index}]`, item.prepared);
        data.append(`titles[${index}]`, item.title || item.prepared.name);
    });

    data.append('imageable_id', props.imageableId);
    data.append('imageable_type', props.imageableType);
    data.append('internal', props.internal ? '1' : '0');

    const response = await axios.post(imagesEndpoint.value, data, {
        headers: { Accept: 'application/json' },
        onUploadProgress: (event) => event.total && onProgress(event.loaded / event.total),
        signal,
    });

    emit('imagesUploaded', response.data);
};

/** Outside api mode the page's own props hold the photos, so it is asked for them again. */
const reloadPage = () => {
    if (!props.apiMode) {
        router.reload({ preserveScroll: true, preserveState: true });
    }
};

const { compressImage } = useImageCompression();

const {
    items: queueItems,
    isActive: uploading,
    total: queueTotal,
    completed: queueCompleted,
    failed: queueFailed,
    hasFailures: queueHasFailures,
    percentage: progressPercentage,
    enqueue,
    stage,
    start,
    retryFailed,
    remove: removeStaged,
    dispose: disposeQueue,
} = useUploadQueue({
    uploadChunk,
    onDrained: reloadPage,
    prepare: compressImage,
    prepareFailureMessage: 'Comprimeren is mislukt',
});

const stagedItems = computed(() => queueItems.value.filter((item) => item.status === 'staged'));
const failedItems = computed(() => queueItems.value.filter((item) => item.status === 'failed'));
const showProgress = computed(() => queueTotal.value > 0 && stagedItems.value.length === 0);

const openFilePicker = () => {
    fileInput.value.click();
};

const openCamera = () => {
    cameraInput.value.click();
};

const acceptFiles = (files) => {
    if (files.length === 0) {
        return;
    }

    if (props.previewBeforeUpload) {
        stage(files);

        return;
    }

    enqueue(files);
};

// Handle files added through the file picker
const handleFiles = (event) => {
    acceptFiles(Array.from(event.target.files));
    event.target.value = '';
};

/** Something dragged off the bakje is not a file: the drop on the whole box takes it. */
const handleDragOver = (event) => {
    if (isTrayDrag(event)) return;

    event.preventDefault();
    isDragging.value = true;
};

const handleDrop = (event) => {
    if (isTrayDrag(event)) return;

    event.preventDefault();
    acceptFiles(Array.from(event.dataTransfer.files));
    isDragging.value = false;
};

const changeTitle = async (name, id) => {
    const newTitle = prompt('Geef deze afbeelding een nieuwe titel', name);
    if (newTitle !== null) {
        uploadImagesForm.newTitle = newTitle;

        if (props.apiMode) {
            await axios.get('/sanctum/csrf-cookie');
            const data = new FormData();
            data.append('newTitle', newTitle);
            data.append('imageable_id', props.imageableId);
            data.append('imageable_type', props.imageableType);
            const response = await axios.post(`/api/images/update/${id}`, data);
            emit('imageUpdated', response.data);
            return;
        }

        uploadImagesForm.post(`/images/update/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                //todo emit new image
                emit('imageUpdated', JSON.parse(page.props.flash.extra));
                uploadImagesForm.newTitle = '';
            }
        })
    }
};

const uploadPhotos = () => {
    const untitled = stagedItems.value.some((item) => !item.title);

    if (untitled) {
        alert('Iedere afbeelding moet een titel hebben');

        return;
    }

    start();
};

const deleteImage = async (id) => {
    if (props.apiMode) {
        await axios.delete(`/api/images/${id}`, {
            data: {
                imageable_id: props.imageableId,
                imageable_type: props.imageableType,
            },
        });
        emit('imageDeleted', id);
        return;
    }

    uploadImagesForm.delete(`/images/${id}`, {
        preserveScroll: true,
    });
    emit('imageDeleted', id);
}

const deleteConfirmOpen = ref(false);
/** The photos the open confirmation asks about: one from its own button, or the chosen ones. */
const pendingDelete = ref([]);
const deletingImage = ref(false);

function confirmDelete(images) {
    pendingDelete.value = images;
    deleteConfirmOpen.value = true;
}

async function performPendingDelete() {
    const target = pendingDelete.value;
    if (target.length === 0) return;
    deletingImage.value = true;
    try {
        await (target.length === 1 ? deleteImage(target[0].id) : deleteSeveral(target));
        stopSelecting();
    } finally {
        deletingImage.value = false;
        // Only clear state if this is still the confirmation the user is looking at —
        // they may have cancelled and opened a different image's confirmation while
        // this delete was in flight.
        if (pendingDelete.value === target) {
            deleteConfirmOpen.value = false;
            pendingDelete.value = [];
        }
    }
}

const setMain = async (id, isCurrentlyMain) => {
    if (props.apiMode) {
        await axios.post(`/api/images/${id}/set-main`, {
            imageable_id: props.imageableId,
            imageable_type: props.imageableType,
            currently_main: isCurrentlyMain,
        });
        return;
    }

    router.post(`/images/${id}/set-main`, {
        imageable_id: props.imageableId,
        imageable_type: props.imageableType,
        currently_main: isCurrentlyMain,
    }, { preserveScroll: true });
}

/*
 * Choosing photos to do something with together: put them on the bakje, to paste
 * them among the photos of another record or on this record's other side, or
 * delete them. On a phone the menu of a photo starts choosing too.
 */
const SELECTION_BUTTON = 'inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer';

const {
    active: selecting, selectedIds, isSelected, allSelected, start: startSelecting, stop: stopSelecting, toggleAll, click: clickToSelect,
} = useClickSelection(() => thumbnails.value.map(({ image }) => image.id));
const ownSelected = computed(() => props.existing.filter((image) => isSelected(image.id)));

/**
 * One request per photo, then the photos are fetched once, not after every one.
 * What was deleted before a failure stays deleted, and the page says why it stopped.
 */
async function deleteSeveral(images) {
    try {
        await ensureCsrfCookie();

        for (const image of images) {
            await axios.delete(`${imagesEndpoint.value}/${image.id}`, {
                data: { imageable_id: props.imageableId, imageable_type: props.imageableType },
                headers: { Accept: 'application/json' },
            });
            emit('imageDeleted', image.id);
        }
    } catch (error) {
        page.props.flash.error = requestFailureMessage(error, 'Verwijderen is mislukt');
    } finally {
        reloadPage();
    }
}

/*
 * Photos off the bakje are pasted here as copies, on this side: copying the
 * internal photos of a werkbon to the ones the customer sees is a paste onto the
 * same werkbon. Without a page to reload, the copies are handed up, and taken
 * away again when the paste is undone.
 */
let lastPastedIds = [];

const photoDrop = computed(() => ({
    accepts: mayUpload() ? ['image'] : [],
    target: { type: 'photos', id: props.imageableId, record: props.imageableType, internal: props.internal },
    label: props.internal ? "bij deze interne foto's te plakken" : "bij deze foto's te plakken",
    onDone: afterPaste,
}));

function afterPaste(data) {
    if (data.created?.images) {
        lastPastedIds = data.created.images.map((image) => image.id);
        if (props.apiMode) emit('imagesUploaded', data.created.images);
    } else if (props.apiMode) {
        lastPastedIds.forEach((id) => emit('imageDeleted', id));
        lastPastedIds = [];
    }

    reloadPage();
}

const toggleShared = (image) => {
    router.post(`/images/${image.id}/share`, { shared: !image.is_shared }, { preserveScroll: true });
}

defineExpose({ deleteImage, uploadPhotos, setMain })

// Stop the queue and release object URLs on component unmount
onUnmounted(() => {
    disposeQueue();
});

const imageToEdit = ref(null);

const openEditor = (image) => {
    imageToEdit.value = image;
};

/**
 * The annotator waits for this before it closes, so a failed save leaves the drawing
 * on screen with the reason. The server keeps the untouched photo next to this one.
 */
const saveAnnotatedImage = async ({ file, annotations }) => {
    await ensureCsrfCookie();

    const data = new FormData();
    data.append('imageToUpdate', file);
    data.append('annotations', JSON.stringify(annotations));
    data.append('imageable_id', props.imageableId);
    data.append('imageable_type', props.imageableType);

    const response = await axios.post(`${imagesEndpoint.value}/update/${imageToEdit.value.id}`, data, {
        headers: { Accept: 'application/json' },
    });

    emit('imageUpdated', response.data);
    reloadPage();
};

</script>
