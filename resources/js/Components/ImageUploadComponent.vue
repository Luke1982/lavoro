<template>
    <div class="w-full mx-auto">
        <ul v-if="props.existing.length > 0" class="flex flex-wrap gap-3 mb-4">
            <li v-for="image in props.existing" :key="image.id"
                class="w-full md:w-full lg:w-[calc(50%-7px)] relative flex cursor-pointer rounded-md overflow-hidden">
                <img :src="`/storage/${image.path}`" :alt="image.path" class="object-contain"
                    @click="openEditor(image)">
                <div class="absolute bottom-0 w-full bg-gradient-to-t from-black to-transparent text-center text-white pb-4 pt-8"
                    @click="changeTitle(image.name, image.id)">
                    {{ image.name }}
                </div>
                <a :href="`/storage/${image.path}`"
                    class="glightbox absolute top-2 left-2 text-black font-bold bg-white rounded-full p-2">
                    <MagnifyingGlassIcon class="h-5 w-5" />
                </a>
                <button @click.stop="deleteImage(image.id)"
                    class="absolute top-2 right-2 text-red-500 font-bold bg-white rounded-full p-2"
                    title="Verwijder deze afbeelding">
                    <TrashIcon class="h-5 w-5" />
                </button>
            </li>
        </ul>

        <div @click="openFilePicker" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop" :class="[
                'flex flex-col items-center justify-center w-full h-48 bg-white border-2 border-dashed rounded-lg cursor-pointer',
                isDragging ? 'bg-gray-200 border-gray-400' : 'bg-white border-gray-300'
            ]">
            <p class="text-gray-500">Sleep afbeeldingen hiernaartoe</p>
            <p class="text-gray-500">of</p>
            <button class="text-blue-500 underline">Kies een foto</button>
            <input ref="fileInput" type="file" class="hidden" @change="handleFiles" multiple />
        </div>

        <div class="mt-4" v-if="selectedFiles.length > 0">
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
        <button v-if="selectedFiles.length > 0" :disabled="uploading"
            :class="[uploading ? 'bg-slate-500' : 'bg-indigo-500', 'w-full text-white rounded-md p-3 mt-3 font-bold']"
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
import ImageEditor from 'tui-image-editor';
import 'tui-image-editor/dist/tui-image-editor.min.css';
import { TrashIcon } from '@heroicons/vue/24/solid';
import GLightbox from 'glightbox';
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

let lightbox = null;
onMounted(() => {
    lightbox = GLightbox({
        selector: '.glightbox',
        touchNavigation: true,
        loop: true,
        zoomable: true,
    });
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
});

const currentImage = ref({ id: null, path: null });

const uploadImagesForm = useForm({
    images: [],
    imageable_id: props.imageableId,
    imageable_type: props.imageableType,
    titles: {},
    imageToUpdate: null,
    newTitle: '',
});

const fileInput = ref(null);
const isDragging = ref(false);
const selectedFiles = ref([]);
const uploading = ref(false);

// Open the file picker when the dropzone is clicked
const openFilePicker = () => {
    fileInput.value.click();
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

const changeTitle = (name, id) => {
    const newTitle = prompt('Geef deze afbeelding een nieuwe titel', name);
    if (newTitle !== null) {
        uploadImagesForm.newTitle = newTitle;

        uploadImagesForm.post(`/images/${id}`, {
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
};


const uploadPhotos = () => {
    uploading.value = true;
    for (let i = 0; i < uploadImagesForm.images.length; i++) {
        const fileTitle = uploadImagesForm.titles[uploadImagesForm.images[i].name];
        if (fileTitle === undefined || fileTitle === '') {
            alert('Iedere afbeelding moet een titel hebben');
            uploading.value = false;
            return;
        }
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

const deleteImage = (id) => {
    uploadImagesForm.delete(`/images/${id}`, {
        preserveScroll: true,
    });
    emit('imageDeleted', id);
}

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

    uploadImagesForm.post(`/images/${currentImage.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            uploadImagesForm.imageToUpdate = null;
            emit('imageUpdated', JSON.parse(page.props.flash.updated_image));
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

function compressImage(file, maxWidth = 1000, maxHeight = 1000, quality = 0.8) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (event) => {
            const img = new Image();
            img.src = event.target.result;

            img.onload = () => {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                // Calculate new dimensions
                let width = img.width;
                let height = img.height;

                if (width > maxWidth || height > maxHeight) {
                    if (width > height) {
                        height = (maxHeight / width) * height;
                        width = maxWidth;
                    } else {
                        width = (maxWidth / height) * width;
                        height = maxHeight;
                    }
                }

                canvas.width = width;
                canvas.height = height;

                // Draw the resized image onto the canvas
                ctx.drawImage(img, 0, 0, width, height);

                // Convert the canvas to a Blob
                canvas.toBlob((blob) => {
                    resolve(new File([blob], file.name, { type: file.type }));
                }, file.type, quality);
            };

            img.onerror = (err) => reject(err);
        };

        reader.onerror = (err) => reject(err);
        reader.readAsDataURL(file);
    });
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
