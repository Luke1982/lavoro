<template>
    <div class="w-full mx-auto" v-if="mayUpload() || maySee()">
        <div v-if="mayUpload()" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop" :class="[
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
                        class="font-medium text-lavoro-blue hover:underline disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        Opnieuw proberen
                    </button>
                </div>
            </div>
        </div>
        <div v-else-if="maySee() && existing.length === 0"
            class="text-center text-sm text-gray-400 dark:text-slate-500 py-5 rounded-lavoro-sm border border-dashed border-gray-200 dark:border-slate-700">
            Er zijn nog geen afbeeldingen.
        </div>

        <div v-if="props.existing.length > 0" class="relative mt-4">
            <div ref="emblaRef" class="overflow-hidden">
                <div class="flex gap-2">
                    <div v-for="image in props.existing" :key="image.id" :title="image.name" :class="[
                        'group relative size-24 flex-none cursor-pointer rounded-lavoro-sm overflow-hidden border-2',
                        image.pivot?.main ? 'border-yellow-400' : 'border-gray-200 dark:border-slate-700'
                    ]">
                        <a :href="`/storage/${image.path}`" class="glightbox block size-full"
                            @click.capture="captureScrollBeforeOpen" @dblclick.stop.prevent="mayUpdate() && changeTitle(image.name, image.id)">
                            <img :src="`/storage/${image.path}`" :alt="image.name" class="size-full object-cover">
                        </a>
                        <div v-if="image.pivot?.main"
                            class="pointer-events-none absolute bottom-1 left-1 flex items-center justify-center rounded-full bg-yellow-400 p-1 shadow-md">
                            <StarIconSolid class="size-3 text-white" />
                        </div>
                        <div v-if="mayEdit() || mayUpdate() || mayDelete()"
                            class="pointer-events-none absolute inset-x-1 top-1 hidden items-center justify-end gap-1 opacity-0 transition-opacity sm:flex group-hover:opacity-100 group-hover:pointer-events-auto">
                            <button v-if="mayEdit()" @click.stop="openEditor(image)" title="Bewerken"
                                class="flex items-center justify-center rounded-full bg-white shadow-md p-1.5 text-gray-700 cursor-pointer hover:bg-gray-100">
                                <PencilSquareIcon class="size-4" />
                            </button>
                            <button v-if="mayUpdate()" @click.stop="setMain(image.id, image.pivot?.main)"
                                class="flex items-center justify-center rounded-full bg-white shadow-md p-1.5 cursor-pointer hover:bg-gray-100"
                                :class="image.pivot?.main ? 'text-yellow-500' : 'text-gray-700'"
                                :title="image.pivot?.main ? 'Dit is de hoofdafbeelding' : 'Instellen als hoofdafbeelding'">
                                <StarIconSolid v-if="image.pivot?.main" class="size-4" />
                                <StarIcon v-else class="size-4" />
                            </button>
                            <button v-if="mayDelete()" @click.stop="confirmDeleteImage(image)" title="Verwijder deze afbeelding"
                                class="flex items-center justify-center rounded-full bg-white shadow-md p-1.5 text-red-600 cursor-pointer hover:bg-gray-100">
                                <TrashIcon class="size-4" />
                            </button>
                        </div>
                        <ImageThumbnailMenu v-if="mayEdit() || mayUpdate() || mayDelete()" :image="image"
                            :can-set-main="mayUpdate()" :can-edit="mayEdit()" :can-delete="mayDelete()"
                            @view="openLightboxFor(image)" @favorite="setMain(image.id, image.pivot?.main)"
                            @annotate="openEditor(image)" @delete="confirmDeleteImage(image)" />
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
                    class="text-center rounded-md border border-gray-00 p-5 col-span-4 md:col-span-2 relative">
                    <img v-if="item.previewUrl" :src="item.previewUrl" alt="Image preview"
                        class="w-24 h-24 object-cover rounded-lg border border-gray-300 inline-block" />
                    <p v-else class="text-gray-700">{{ item.name }}</p>
                    <p class="text-sm text-gray-500 truncate mt-1">{{ item.name }}</p>
                    <input type="text" v-model="item.title" class="rounded-md border-gray-200 my-2 w-full"
                        placeholder="Titel">
                    <TrashIcon class="h-5 w-5 text-red-500 cursor-pointer absolute top-2 right-2"
                        @click="removeStaged(item.id)" />
                </div>
            </div>
        </div>
        <button v-if="previewBeforeUpload && stagedItems.length > 0" :disabled="uploading"
            :class="[uploading ? 'bg-slate-500' : 'bg-lavoro-blue', 'w-full text-white rounded-md p-3 mt-3 font-bold']"
            @click="uploadPhotos">Verzenden</button>

        <div v-if="isEditorOpen" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="relative bg-white rounded-lg overflow-hidden w-full h-full">
                <div ref="editorContainer" class="w-full h-full"></div>
                <button class="absolute bottom-4 right-4 bg-blue-500 text-white p-2 rounded"
                    @click="saveEditedImage">Save</button>
                <div class="flex absolute right-2 top-2 h-10 bg-white rounded-full border-gray-600 border-2"
                    id="image-editor-save-cancel-buttons">
                    <button class="top-2 right-16 text-green-500 font-bold px-6 border-r border-gray-300"
                        @click="saveEditedImage">Bewaar</button>
                    <button class="top-2 right-2 text-red-500 font-bold px-6" @click="closeEditor">X</button>
                </div>
            </div>
        </div>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css" />

        <ModalDialog :open="deleteConfirmOpen" @update:open="deleteConfirmOpen = $event" max-width-class="sm:max-w-sm">
            <div class="sm:flex sm:items-start gap-4">
                <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:size-10">
                    <ExclamationTriangleIcon class="size-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="mt-3 sm:mt-0 text-center sm:text-left">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Afbeelding verwijderen</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        Weet je zeker dat je{{ pendingDeleteImage?.name ? ` "${pendingDeleteImage.name}"` : ' deze afbeelding' }} wilt verwijderen?
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
    ref, computed, watch, onUnmounted, onMounted, nextTick

} from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import ImageEditor from 'tui-image-editor';
import 'tui-image-editor/dist/tui-image-editor.min.css';
import { TrashIcon, StarIcon, CameraIcon, PhotoIcon, PencilSquareIcon, CloudArrowUpIcon, ChevronLeftIcon, ChevronRightIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline';
import { StarIcon as StarIconSolid } from '@heroicons/vue/24/solid';
import GLightbox from 'glightbox';
import emblaCarouselVue from 'embla-carousel-vue';
import ImageThumbnailMenu from '@/Components/UI/ImageThumbnailMenu.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import { hasPermission } from '@/Utilities/Utilities.js';
import { useScrollLock } from '@/Composables/useScrollLock.js';
import { useUploadQueue } from '@/Composables/useUploadQueue.js';
import { useImageCompression } from '@/Composables/useImageCompression.js';

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
});

const existingSignature = computed(() => props.existing.map((image) => `${image.id}:${image.path}`).join(','));

watch(existingSignature, () => {
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

function openLightboxFor(image) {
    const index = props.existing.findIndex(existingImage => existingImage.id === image.id);
    if (lightbox && index !== -1) {
        captureScrollBeforeOpen();
        lightbox.openAt(index);
    }
}

const currentImage = ref({ id: null, path: null });

const uploadImagesForm = useForm({
    imageable_id: props.imageableId,
    imageable_type: props.imageableType,
    imageToUpdate: null,
    newTitle: '',
    internal: props.internal,
});

const fileInput = ref(null);
const cameraInput = ref(null);
const isDragging = ref(false);

let csrf_request = null;

const ensureCsrfCookie = () => {
    if (!csrf_request) {
        csrf_request = axios.get('/sanctum/csrf-cookie');
    }

    return csrf_request;
};

const uploadChunk = async (batch, onProgress) => {
    await ensureCsrfCookie();

    const data = new FormData();

    batch.forEach((item) => {
        data.append('images[]', item.prepared);
        data.append(`titles[${item.prepared.name}]`, item.title || item.prepared.name);
    });

    data.append('imageable_id', props.imageableId);
    data.append('imageable_type', props.imageableType);
    data.append('internal', props.internal ? '1' : '0');

    const response = await axios.post(props.apiMode ? '/api/images' : '/images', data, {
        headers: { Accept: 'application/json' },
        onUploadProgress: (event) => event.total && onProgress(event.loaded / event.total),
    });

    emit('imagesUploaded', response.data);
};

const onQueueDrained = () => {
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
    onDrained: onQueueDrained,
    prepare: compressImage,
    prepareFailureMessage: 'Comprimeren is mislukt',
});

const stagedItems = computed(() => queueItems.value.filter((item) => item.status === 'staged'));
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

// Handle files added through drag-and-drop
const handleDrop = (event) => {
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
const pendingDeleteImage = ref(null);
const deletingImage = ref(false);

function confirmDeleteImage(image) {
    pendingDeleteImage.value = image;
    deleteConfirmOpen.value = true;
}

async function performPendingDelete() {
    const target = pendingDeleteImage.value;
    if (!target) return;
    deletingImage.value = true;
    try {
        await deleteImage(target.id);
    } finally {
        deletingImage.value = false;
        // Only clear state if this is still the confirmation the user is looking at —
        // they may have cancelled and opened a different image's confirmation while
        // this delete was in flight.
        if (pendingDeleteImage.value === target) {
            deleteConfirmOpen.value = false;
            pendingDeleteImage.value = null;
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

defineExpose({ deleteImage, uploadPhotos, setMain })

// Stop the queue and release object URLs on component unmount
onUnmounted(() => {
    disposeQueue();
    if (editorInstance.value) editorInstance.value.destroy(); // TUI Image Editor
});

// Image Editor
const isEditorOpen = ref(false);
const editorInstance = ref(null);
const editorContainer = ref(null);
const imageToEdit = ref(null);

const openEditor = (image) => {
    currentImage.value = { id: image.id, path: image.path };
    imageToEdit.value = image;
    isEditorOpen.value = true;

    setTimeout(() => {
        initEditor();
    }, 10);
};

const initEditor = () => {
    if (editorContainer.value === null) {
        initEditor();
    }
    if (editorContainer.value && !editorInstance.value) {
        editorInstance.value = new ImageEditor(editorContainer.value, {
            includeUI: {
                loadImage: { path: `/storage/${imageToEdit.value.path}`, name: imageToEdit.value.path },
                menu: ['crop', 'flip', 'rotate', 'draw', 'text', 'shape', 'icon'],
                initMenu: 'draw',
                uiSize: { width: '100%', height: '100%' }
            },
            cssMaxWidth: window.innerWidth,
            cssMaxHeight: window.innerHeight
        });
    }
}

const closeEditor = () => {
    isEditorOpen.value = false;
    editorInstance.value = null
};

const saveEditedImage = () => {
    const dataURL = editorInstance.value.toDataURL({ mimeType: 'image/jpeg', quality: 1 });

    const blob = dataUrlToBlob(dataURL);
    const filename = currentImage.value.path.split('/').pop();  // Extract the filename from the path
    const file = new File([blob], filename, { type: "image/jpeg" });

    uploadImagesForm.imageToUpdate = file;  // Overwrite the existing image with the annotated image

    if (props.apiMode) {
        axios.get('/sanctum/csrf-cookie').then(() => {
            const data = new FormData();
            data.append('imageToUpdate', file);
            data.append('imageable_id', props.imageableId);
            data.append('imageable_type', props.imageableType);
            axios.post(`/api/images/update/${currentImage.value.id}`, data).then((response) => {
                emit('imageUpdated', response.data);
                currentImage.value = { id: null, path: null };
            });
        });
        closeEditor();
        return;
    }

    uploadImagesForm.post(`/images/update/${currentImage.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            uploadImagesForm.imageToUpdate = null;
            emit('imageUpdated', JSON.parse(page.props.flash.extra));
            currentImage.value = { id: null, path: null };
        }
    });

    closeEditor();
};

function dataUrlToBlob(dataUrl) {
    const [header, base64String] = dataUrl.split(',');
    const mimeType = header.match(/:(.*?);/)[1]; // Extract the mime type

    const binaryString = atob(base64String);

    const length = binaryString.length;
    const bytes = new Uint8Array(length);
    for (let i = 0; i < length; i++) {
        bytes[i] = binaryString.charCodeAt(i);
    }

    return new Blob([bytes], { type: mimeType });
}

</script>

<style>
.tui-image-editor-header-buttons {
    display: none !important;
}

.tui-colorpicker-clearfix {
    display: flex !important;
    flex-wrap: wrap !important;
}

.tui-colorpicker-clearfix li {
    margin-right: 5px !important;
    margin-bottom: 5px !important;
}

.tui-colorpicker-clearfix li,
.tui-colorpicker-clearfix li .tui-colorpicker-palette-button {
    width: 1.5rem !important;
    height: 1.5rem !important;
}

.tui-image-editor-header-logo {
    display: none !important;
}

@media (max-width: 900px) {
    #image-editor-save-cancel-buttons {
        top: auto !important;
        bottom: 230px !important;
    }
}
</style>
