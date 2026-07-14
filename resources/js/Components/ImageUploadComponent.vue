<template>
    <div class="w-full mx-auto" v-if="mayUpload() || maySee()">
        <ul v-if="props.existing.length > 0" class="flex flex-wrap gap-3 mb-4">
            <li v-for="image in props.existing" :key="image.id"
                class="w-full md:w-full lg:w-[calc(50%-7px)] relative flex cursor-pointer rounded-md overflow-hidden"
                :class="image.pivot?.main ? 'ring-2 ring-yellow-400' : ''">
                <a :href="`/storage/${image.path}`" class="glightbox contents" @click.capture="captureScrollBeforeOpen">
                    <img :src="`/storage/${image.path}`" :alt="image.path" class="object-cover w-full h-48">
                </a>
                <div class="absolute bottom-0 w-full bg-gradient-to-t from-black to-transparent text-center text-white pb-4 pt-8"
                    @click="mayUpdate() && changeTitle(image.name, image.id)">
                    {{ image.name }}
                </div>
                <div class="flex absolute top-2 left-2 gap-x-2">
                    <button v-if="mayEdit()" @click.stop="openEditor(image)"
                        class="text-black font-bold bg-white rounded-full p-2">
                        <PencilIcon class="h-5 w-5" />
                    </button>
                    <button v-if="mayUpdate()" @click.stop="setMain(image.id, image.pivot?.main)"
                        class="font-bold bg-white rounded-full p-2"
                        :class="image.pivot?.main ? 'text-yellow-400' : 'text-gray-400'"
                        :title="image.pivot?.main ? 'Dit is de hoofdafbeelding' : 'Instellen als hoofdafbeelding'">
                        <StarIcon class="h-5 w-5" :class="image.pivot?.main ? 'fill-yellow-400' : ''" />
                    </button>
                </div>
                <button @click.stop="deleteImage(image.id)" v-if="mayDelete()"
                    class="absolute top-2 right-2 text-red-500 font-bold bg-white rounded-full p-2"
                    title="Verwijder deze afbeelding">
                    <TrashIcon class="h-5 w-5" />
                </button>
            </li>
        </ul>
        <div v-else-if="!mayUpload() && maySee() && existing.length === 0"
            class="text-center text-gray-500 p-5 border-2 border-dashed rounded-lg">
            Er zijn nog geen afbeeldingen.
        </div>

        <div v-if="mayUpload()" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop" :class="[
                'flex flex-col items-center justify-center w-full h-48 bg-white border-2 border-dashed rounded-lg',
                isDragging ? 'bg-gray-200 border-gray-400 dark:bg-slate-700 dark:border-slate-600' : 'bg-white border-gray-300 dark:bg-slate-800 dark:border-slate-600'
            ]">
            <p class="text-gray-500">Sleep afbeeldingen hiernaartoe</p>
            <p class="text-gray-500">of</p>
            <div class="flex gap-3 mt-2">
                <button @click="openCamera"
                    class="inline-flex items-center gap-2 rounded-md border border-lavoro-blue px-4 py-2 text-sm font-medium text-lavoro-blue hover:bg-lavoro-blue hover:text-white transition-colors">
                    <CameraIcon class="h-4 w-4" />
                    Camera
                </button>
                <button @click="openFilePicker"
                    class="inline-flex items-center gap-2 rounded-md border border-lavoro-blue px-4 py-2 text-sm font-medium text-lavoro-blue hover:bg-lavoro-blue hover:text-white transition-colors">
                    <PhotoIcon class="h-4 w-4" />
                    Galerij
                </button>
            </div>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="handleFiles" multiple />
            <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden"
                @change="handleFiles" />
        </div>

        <div class="mt-4" v-if="previewBeforeUpload && selectedFiles.length > 0">
            <h3 class="text-lg font-semibold">Deze foto's wil je uploaden</h3>
            <div class="grid grid-cols-4 gap-4 mt-2">
                <div v-for="(file, index) in selectedFiles" :key="index"
                    class="text-center rounded-md border border-gray-00 p-5 col-span-4 md:col-span-2 relative">
                    <img v-if="file.type.startsWith('image/')" :src="file.previewUrl" alt="Image preview"
                        class="w-24 h-24 object-cover rounded-lg border border-gray-300 inline-block" />
                    <p v-else class="text-gray-700">{{ file.name }}</p>
                    <p class="text-sm text-gray-500 truncate mt-1">{{ file.name }}</p>
                    <input type="text" v-model="uploadImagesForm.titles[file.name]"
                        class="rounded-md border-gray-200 my-2 w-full" placeholder="Titel">
                    <TrashIcon class="h-5 w-5 text-red-500 cursor-pointer absolute top-2 right-2"
                        @click="selectedFiles.splice(index, 1); uploadImagesForm.images.splice(index, 1); uploadImagesForm.titles[file.name] = ''" />
                </div>
            </div>
        </div>
        <button v-if="previewBeforeUpload && selectedFiles.length > 0" :disabled="uploading"
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
    </div>
</template>

<script setup>
import {
    ref, onUnmounted, onMounted, onUpdated, nextTick

} from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import ImageEditor from 'tui-image-editor';
import 'tui-image-editor/dist/tui-image-editor.min.css';
import { TrashIcon, StarIcon, CameraIcon, PhotoIcon, PencilIcon } from '@heroicons/vue/24/solid';
import GLightbox from 'glightbox';
import { hasPermission } from '@/Utilities/Utilities.js';
import { useScrollLock } from '@/Composables/useScrollLock.js';
import { useImageCompression } from '@/Composables/useImageCompression.js';

const { compressImage } = useImageCompression();

let lightbox = null;
const { captureScroll: captureScrollBeforeOpen, lock: lockBodyScroll, unlock: unlockBodyScroll } = useScrollLock();

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
onUpdated(() => {
    nextTick(() => {
        lightbox && lightbox.reload();
    });
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

const mayUpload = () => props.canManage !== null ? props.canManage : hasPermission('image.upload');
const maySee = () => props.canManage !== null ? props.canManage : hasPermission('image.see');
const mayEdit = () => props.canManage !== null ? props.canManage : hasPermission('image.edit');
const mayUpdate = () => props.canManage !== null ? props.canManage : hasPermission('image.update');
const mayDelete = () => props.canManage !== null ? props.canManage : hasPermission('image.delete');

const currentImage = ref({ id: null, path: null });

const uploadImagesForm = useForm({
    images: [],
    imageable_id: props.imageableId,
    imageable_type: props.imageableType,
    titles: {},
    imageToUpdate: null,
    newTitle: '',
    internal: props.internal,
});

const fileInput = ref(null);
const cameraInput = ref(null);
const isDragging = ref(false);
const selectedFiles = ref([]);
const uploading = ref(false);

const openFilePicker = () => {
    fileInput.value.click();
};

const openCamera = () => {
    cameraInput.value.click();
};

// Handle files added through the file picker
const handleFiles = (event) => {
    const files = Array.from(event.target.files);
    addFiles(files);
};

// Handle files added through drag-and-drop
const handleDrop = (event) => {
    const files = Array.from(event.dataTransfer.files);
    addFiles(files);
    isDragging.value = false;
};

const changeTitle = async (name, id) => {
    const newTitle = prompt('Geef deze afbeelding een nieuwe titel', name);
    if (newTitle !== null) {
        uploadImagesForm.newTitle = newTitle;

        if (props.apiMode) {
            await axios.get('sanctum/csrf-cookie');
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

// Add files to the existing list and generate previews for images
const addFiles = async (files) => {
    const compressedFiles = [];

    for (const file of files) {
        if (file.type.startsWith('image/')) {
            // Compress image
            const compressedFile = await compressImage(file);
            compressedFiles.push({
                file: compressedFile,
                name: compressedFile.name,
                type: compressedFile.type,
                previewUrl: URL.createObjectURL(compressedFile),
            });
        } else {
            // Add non-image files without compression
            compressedFiles.push({
                file,
                name: file.name,
                type: file.type,
                previewUrl: null,
            });
        }
        uploadImagesForm.titles[file.name] = file.name;
    }

    // Update selected files and upload form
    selectedFiles.value = [...selectedFiles.value, ...compressedFiles];
    uploadImagesForm.images = [...uploadImagesForm.images, ...compressedFiles.map((r) => r.file)];

    if (!props.previewBeforeUpload) {
        await uploadPhotos();
    }
};


const uploadPhotos = async () => {
    uploading.value = true;
    for (let i = 0; i < uploadImagesForm.images.length; i++) {
        const fileTitle = uploadImagesForm.titles[uploadImagesForm.images[i].name];
        if (fileTitle === undefined || fileTitle === '') {
            alert('Iedere afbeelding moet een titel hebben');
            uploading.value = false;
            return;
        }
    }

    if (props.apiMode) {
        await axios.get('sanctum/csrf-cookie');
        const data = new FormData();
        uploadImagesForm.images.forEach((file) => {
            data.append('images[]', file);
            data.append(`titles[${file.name}]`, uploadImagesForm.titles[file.name]);
        });
        data.append('imageable_id', props.imageableId);
        data.append('imageable_type', props.imageableType);
        data.append('internal', props.internal ? '1' : '0');
        const response = await axios.post('/api/images', data);
        emit('imagesUploaded', response.data);
        uploadImagesForm.reset();
        selectedFiles.value = [];
        uploading.value = false;
        return;
    }

    uploadImagesForm.post('/images',
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('imagesUploaded', JSON.parse(page.props.flash.extra));
                uploadImagesForm.reset();
                selectedFiles.value = [];
                uploading.value = false;
            }
        }
    );
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

// Clean up object URLs on component unmount
onUnmounted(() => {
    selectedFiles.value.forEach(file => {
        if (file.previewUrl) {
            URL.revokeObjectURL(file.previewUrl);
        }
    });
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
        axios.get('sanctum/csrf-cookie').then(() => {
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
