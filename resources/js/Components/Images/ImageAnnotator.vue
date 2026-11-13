<template>
    <Teleport to="body">
        <div ref="dialog" tabindex="-1" role="dialog" aria-modal="true" :aria-label="`${image.name} bewerken`"
            class="fixed inset-0 z-[100] flex flex-col bg-slate-950 text-white outline-none select-none">
            <div class="flex items-center justify-between gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
                <button type="button" @click="requestClose"
                    class="rounded-full px-3 py-1.5 text-sm font-medium text-slate-300 hover:bg-white/10 cursor-pointer">
                    Annuleren
                </button>
                <p class="min-w-0 truncate text-sm text-slate-400">{{ image.name }}</p>
                <button type="button" @click="save" :disabled="!loaded || saving"
                    class="rounded-full bg-lavoro-blue px-5 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                    {{ saving ? 'Bewaren…' : 'Bewaar' }}
                </button>
            </div>

            <div ref="viewport" class="relative min-h-0 flex-1 touch-none">
                <div ref="stageContainer" class="absolute inset-0" />
                <p v-if="!loaded" :role="loadError ? 'alert' : null"
                    class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-slate-400">
                    {{ loadError || 'Foto laden…' }}
                </p>
                <p v-if="saveError" role="alert"
                    class="absolute inset-x-4 top-2 rounded-lavoro-sm bg-red-600 px-3 py-2 text-sm text-white shadow-xl">
                    {{ saveError }}
                </p>
            </div>

            <div class="relative flex justify-center px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <div class="pointer-events-none absolute inset-x-4 bottom-full flex flex-col items-center gap-2 pb-1">
                    <form v-if="editingText" @submit.prevent="finishText()"
                        class="pointer-events-auto flex w-full max-w-md gap-2 rounded-full bg-slate-900/90 p-1.5 shadow-xl">
                        <input ref="textInput" v-model="textDraft" type="text" maxlength="120" placeholder="Typ je tekst"
                            class="min-w-0 flex-1 rounded-full border-0 bg-white px-4 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-2 focus:ring-lavoro-blue">
                        <button type="submit"
                            class="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20 cursor-pointer">
                            Klaar
                        </button>
                    </form>

                    <div v-if="showColours" class="pointer-events-auto flex items-center gap-2 rounded-full bg-white px-3 py-2 shadow-xl">
                        <button v-for="option in COLOURS" :key="option.value" type="button" @click="pickColour(option.value)"
                            :title="option.name" :style="{ backgroundColor: option.value }" :class="[
                                'size-7 rounded-full ring-1 ring-slate-300 cursor-pointer',
                                option.value === colour ? 'outline-2 outline-offset-2 outline-slate-900' : '',
                            ]" />
                    </div>
                </div>

                <div class="flex items-center gap-0.5 rounded-full bg-white p-1.5 text-slate-600 shadow-xl">
                    <button v-for="option in TOOLS" :key="option.key" type="button" @click="toggleTool(option.key)"
                        :title="option.label" :aria-pressed="tool === option.key" :class="[
                            'inline-flex h-9 items-center justify-center gap-1.5 rounded-full text-xs font-semibold cursor-pointer transition-colors',
                            tool === option.key ? 'bg-slate-900 px-3 text-white' : 'w-9 hover:bg-slate-100',
                        ]">
                        <component :is="option.icon" class="size-4" />
                        <span v-if="tool === option.key" class="hidden sm:inline">{{ option.label }}</span>
                    </button>

                    <span class="mx-1 h-6 w-px bg-slate-200" />

                    <button v-if="tool === 'crop'" type="button" @click="resetCrop" title="Hele foto" :class="ICON_BUTTON">
                        <RotateCcwIcon class="size-4" />
                    </button>
                    <button v-else type="button" @click="showColours = !showColours" title="Kleur" :class="ICON_BUTTON">
                        <span class="size-4 rounded-full ring-2 ring-white outline-[1.5px] outline-slate-900"
                            :style="{ backgroundColor: colour }" />
                    </button>
                    <button type="button" @click="undo" :disabled="!canUndo" title="Ongedaan maken" :class="ICON_BUTTON">
                        <Undo2Icon class="size-4" />
                    </button>
                    <button v-if="selectedId" type="button" @click="removeSelected" title="Verwijderen"
                        :class="[ICON_BUTTON, 'text-red-600']">
                        <Trash2Icon class="size-4" />
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import axios from 'axios';
import Konva from 'konva';
import { ArrowUpRightIcon, CropIcon, HighlighterIcon, RotateCcwIcon, Trash2Icon, TypeIcon, Undo2Icon } from '@lucide/vue';
import { useScrollLock } from '@/Composables/useScrollLock.js';
import { imageFileUrl } from '@/Utilities/imageFiles.js';
import { requestFailureMessage } from '@/Utilities/requestFailure.js';
import { COLOURS, DARK, buildShapeNode, labelHeight, moveShape, round } from '@/Components/Images/annotationShapes.js';

/**
 * Draws on a photo without touching it. It opens the photo as it was taken with the
 * stored drawing on top, so an arrow from last week can still be moved or removed,
 * and hands both the drawing and the photo with it burned in to `persist`. It stays
 * open until that has worked, so a failed save loses nothing.
 */
const props = defineProps({
    /** Needs id, name and path. */
    image: {
        type: Object,
        required: true,
    },
    imageableType: {
        type: String,
        required: true,
    },
    imageableId: {
        type: Number,
        required: true,
    },
    /** Receives { file, annotations } and returns a promise that settles with the request. */
    persist: {
        type: Function,
        required: true,
    },
});

const emit = defineEmits(['close']);

const TOOLS = [
    { key: 'draw', label: 'Markeer', icon: HighlighterIcon },
    { key: 'arrow', label: 'Pijl', icon: ArrowUpRightIcon },
    { key: 'text', label: 'Tekst', icon: TypeIcon },
    { key: 'crop', label: 'Bijsnijden', icon: CropIcon },
];
const ICON_BUTTON = 'inline-flex size-9 items-center justify-center rounded-full hover:bg-slate-100 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed';
const VIEW_PADDING = 16;
const MIN_CROP = 24;
const MAX_EXPORT_SIDE = 1920;
const EXPORT_QUALITY = 0.85;

const dialog = ref(null);
const viewport = ref(null);
const stageContainer = ref(null);
const textInput = ref(null);

const loaded = ref(false);
const loadError = ref('');
const saving = ref(false);
const saveError = ref('');
const tool = ref(null);
const colour = ref(COLOURS[0].value);
const showColours = ref(false);
const selectedId = ref(null);
const editingTextId = ref(null);
const textDraft = ref('');
const history = shallowRef([]);

const editingText = computed(() => editingTextId.value !== null);
const canUndo = computed(() => history.value.length > 0);

/*
 * The drawing itself is plain data, redrawn by hand: Vue never needs to watch it,
 * and Konva reading thousands of points through a reactive proxy is slow.
 */
let shapes = [];
let crop = null;
let cropDraft = null;
let live = null;
let liveNode = null;
let activePointer = null;
let textSnapshot = null;
let dragSnapshot = null;

let photo = null;
let natural = { width: 0, height: 0 };
let fontFamily = 'sans-serif';
let stage = null;
let content = null;
let drawing = null;
let selectionBox = null;
let cropFrame = null;
let cropShade = null;
let cropHandles = null;
let resizeObserver = null;
let disposed = false;
let lastId = 0;

const { lock, unlock } = useScrollLock();

function newId() {
    lastId += 1;

    return 's' + Date.now().toString(36) + lastId;
}

function fullImage() {
    return { x: 0, y: 0, width: natural.width, height: natural.height };
}

function viewRegion() {
    return tool.value === 'crop' || !crop ? fullImage() : crop;
}

/** A hundredth of what is on screen, so a stroke looks the same however far the photo is cropped. */
function unitForView() {
    const region = viewRegion();

    return round(Math.max(region.width, region.height) / 100);
}

function snapshot() {
    return structuredClone({ shapes, crop });
}

function remember(state = snapshot()) {
    history.value = [...history.value, state];
}

function shapeById(id) {
    return shapes.find((shape) => shape.id === id) ?? null;
}

function render() {
    if (!drawing) return;

    drawing.destroyChildren();
    liveNode = null;

    const movable = tool.value === null;

    for (const shape of shapes) {
        const node = buildShapeNode(shape, fontFamily);
        node.listening(movable || (tool.value === 'text' && shape.type === 'text'));

        if (movable) {
            node.draggable(true);
            node.on('dragstart', () => startDrag(shape));
            node.on('dragmove', placeSelectionBox);
            node.on('dragend', () => endDrag(shape, node));
        }

        drawing.add(node);
    }

    placeSelectionBox();
    stage.batchDraw();
}

/**
 * Nothing may be redrawn while a shape is being dragged, or its node is destroyed
 * under the pointer; a label being typed elsewhere is therefore finished quietly and
 * drawn again on the drop.
 */
function startDrag(shape) {
    if (editingTextId.value !== shape.id) {
        finishText({ redraw: false });
    }

    dragSnapshot = snapshot();
    selectedId.value = shape.id;
}

function endDrag(shape, node) {
    const origin = shape.type === 'text' ? { x: shape.x, y: shape.y } : { x: 0, y: 0 };
    moveShape(shape, node.x() - origin.x, node.y() - origin.y);
    remember(dragSnapshot);
    dragSnapshot = null;
    render();
}

function placeSelectionBox() {
    const node = selectedId.value ? drawing.findOne('#' + selectedId.value) : null;

    if (!node) {
        selectionBox.visible(false);

        return;
    }

    const box = node.getClientRect({ relativeTo: content });
    const margin = 6 / content.scaleX();
    selectionBox.setAttrs({
        visible: true,
        x: box.x - margin,
        y: box.y - margin,
        width: box.width + margin * 2,
        height: box.height + margin * 2,
    });
}

/** Finishing the label being typed can drop it, when it was left empty. */
function select(id) {
    finishText();

    const shape = shapeById(id);
    selectedId.value = shape?.id ?? null;

    if (shape?.type === 'text') {
        editText(shape);
    }

    placeSelectionBox();
    stage.batchDraw();
}

function deselect() {
    finishText();
    selectedId.value = null;
    placeSelectionBox();
    stage.batchDraw();
}

function editText(shape, before = snapshot()) {
    textSnapshot = before;
    editingTextId.value = shape.id;
    selectedId.value = shape.id;
    textDraft.value = shape.text;
    nextTick(() => textInput.value?.focus());
}

watch(textDraft, (text) => {
    const shape = shapeById(editingTextId.value);

    if (shape && shape.text !== text) {
        shape.text = text;
        render();
    }
});

/** A label left empty is dropped, and typing that changed nothing leaves no step to undo. */
function finishText({ redraw = true } = {}) {
    if (editingTextId.value === null) return;

    const shape = shapeById(editingTextId.value);
    const before = textSnapshot;
    editingTextId.value = null;
    textSnapshot = null;

    if (shape) {
        shape.text = shape.text.trim();
    }

    if (shape && shape.text === '') {
        shapes = shapes.filter((other) => other !== shape);
        selectedId.value = selectedId.value === shape.id ? null : selectedId.value;
    }

    if (JSON.stringify(before) !== JSON.stringify(snapshot())) {
        remember(before);
    }

    if (redraw) {
        render();
    }
}

function pointerInImage() {
    const point = content.getRelativePointerPosition();
    const region = viewRegion();

    return {
        x: Math.min(Math.max(point.x, region.x), region.x + region.width),
        y: Math.min(Math.max(point.y, region.y), region.y + region.height),
    };
}

/**
 * A tap is cancelled so the browser does not follow it with an emulated mousedown,
 * which would take the focus from the label being typed and close the keyboard
 * straight after it opened. A real mouse keeps its mousedown: Konva drags on it.
 */
function onPointerDown(event) {
    if (event.evt.pointerType !== 'mouse') {
        event.evt.preventDefault();
    }

    if (!loaded.value || live || !event.evt.isPrimary) return;
    if (tool.value !== 'draw' && tool.value !== 'arrow') return;

    showColours.value = false;
    activePointer = event.evt.pointerId;

    const point = pointerInImage();
    live = {
        id: newId(),
        type: tool.value,
        colour: colour.value,
        size: unitForView(),
        points: [point.x, point.y, point.x, point.y],
    };
    drawLive();
}

/**
 * Only the stroke being drawn is redrawn while the pointer moves; rebuilding every
 * shape on each move stutters once a photo holds a few long strokes. Konva gets a
 * fresh array each time, or it keeps drawing the curve it smoothed before.
 */
function drawLive() {
    if (!liveNode) {
        liveNode = buildShapeNode(live, fontFamily).listening(false);
        drawing.add(liveNode);
    }

    liveNode.getChildren().forEach((stroke) => stroke.points([...live.points]));
    stage.batchDraw();
}

/** Follows the pointer outside the photo too, so a stroke that overshoots the edge keeps going. */
function onPointerMove(event) {
    if (!live || event.pointerId !== activePointer) return;

    stage.setPointersPositions(event);
    const point = pointerInImage();

    if (live.type === 'arrow') {
        live.points = [live.points[0], live.points[1], point.x, point.y];
    } else {
        const [last_x, last_y] = live.points.slice(-2);

        if (Math.hypot(point.x - last_x, point.y - last_y) < 2 / content.scaleX()) return;

        live.points.push(point.x, point.y);
    }

    drawLive();
}

/** A tap with the pen leaves a dot; an arrow too short to show its head is dropped. */
function onPointerUp(event) {
    if (!live || event.pointerId !== activePointer) return;

    const finished = live;
    live = null;
    activePointer = null;

    const [start_x, start_y] = finished.points;
    const [end_x, end_y] = finished.points.slice(-2);
    const too_short = finished.type === 'arrow' && Math.hypot(end_x - start_x, end_y - start_y) < finished.size * 3;

    if (!too_short) {
        remember();
        finished.points = finished.points.map(round);
        shapes.push(finished);
    }

    render();
}

function onPointerCancel(event) {
    if (!live || event.pointerId !== activePointer) return;

    live = null;
    activePointer = null;
    render();
}

function placeText() {
    finishText();

    const before = snapshot();
    const point = pointerInImage();
    const size = unitForView();
    const shape = {
        id: newId(),
        type: 'text',
        colour: colour.value,
        size,
        text: '',
        x: round(point.x),
        y: round(point.y - labelHeight(size) / 2),
    };

    shapes.push(shape);
    editText(shape, before);
    render();
}

/** With the text tool only labels answer, so a tap either edits one or places a new one. */
function onStageClick(event) {
    if (!loaded.value) return;

    const node = event.target.findAncestor('.shape', true);

    if (tool.value === 'text' && node) {
        select(node.id());
    } else if (tool.value === 'text') {
        placeText();
    } else if (tool.value === null && node && node.id() !== selectedId.value) {
        select(node.id());
    } else if (tool.value === null) {
        deselect();
    }
}

function toggleTool(key) {
    finishText({ redraw: false });
    showColours.value = false;

    if (tool.value === 'crop') {
        commitCrop();
    }

    tool.value = tool.value === key ? null : key;
    selectedId.value = null;
    cropDraft = tool.value === 'crop' ? { ...(crop ?? fullImage()) } : null;

    fit();
    render();
}

/** Picking a colour also recolours whatever is selected. */
function pickColour(option) {
    colour.value = option;
    showColours.value = false;

    const shape = shapeById(selectedId.value);

    if (!shape || shape.colour === option) return;

    if (!editingText.value) {
        remember();
    }

    shape.colour = option;
    render();
}

function removeSelected() {
    const id = selectedId.value;

    if (!id) return;

    remember(textSnapshot ?? snapshot());
    editingTextId.value = null;
    textSnapshot = null;
    shapes = shapes.filter((shape) => shape.id !== id);
    selectedId.value = null;
    render();
}

function undo() {
    finishText({ redraw: false });

    const previous = history.value.at(-1);

    if (!previous) return;

    history.value = history.value.slice(0, -1);
    shapes = previous.shapes;
    crop = previous.crop;
    selectedId.value = null;

    if (tool.value === 'crop') {
        cropDraft = { ...(crop ?? fullImage()) };
    }

    fit();
    render();
}

function sameRegion(first, second) {
    return ['x', 'y', 'width', 'height'].every((key) => Math.abs(first[key] - second[key]) < 1);
}

/** A frame around the whole photo is no crop at all. */
function commitCrop() {
    const x = Math.max(0, Math.round(cropDraft.x));
    const y = Math.max(0, Math.round(cropDraft.y));
    const rounded = {
        x,
        y,
        width: Math.min(Math.round(cropDraft.width), natural.width - x),
        height: Math.min(Math.round(cropDraft.height), natural.height - y),
    };
    const next = sameRegion(rounded, fullImage()) ? null : rounded;

    cropDraft = null;

    if (sameRegion(next ?? fullImage(), crop ?? fullImage())) return;

    remember();
    crop = next;
}

function resetCrop() {
    cropDraft = fullImage();
    layoutCrop();
    stage.batchDraw();
}

function layoutCrop() {
    const cropping = cropDraft !== null;

    cropFrame.visible(cropping);
    cropShade.visible(cropping);
    cropHandles.visible(cropping);
    cropHandles.nodes(cropping ? [cropFrame] : []);

    if (cropping) {
        cropFrame.setAttrs({ ...cropDraft, scaleX: 1, scaleY: 1 });
        cropHandles.forceUpdate();
    }
}

/** The photo's edges on screen, which the crop frame may not cross. */
function photoBounds() {
    const transform = content.getAbsoluteTransform();
    const top_left = transform.point({ x: 0, y: 0 });
    const bottom_right = transform.point({ x: natural.width, y: natural.height });

    return { left: top_left.x, top: top_left.y, right: bottom_right.x, bottom: bottom_right.y };
}

/** The transformer resizes by scaling; the frame is kept at scale 1 with its real size. */
function readCropFrame() {
    cropDraft = {
        x: cropFrame.x(),
        y: cropFrame.y(),
        width: cropFrame.width() * cropFrame.scaleX(),
        height: cropFrame.height() * cropFrame.scaleY(),
    };
    cropFrame.setAttrs({ ...cropDraft, scaleX: 1, scaleY: 1 });
}

function fit() {
    if (!loaded.value) return;

    const width = viewport.value.clientWidth;
    const height = viewport.value.clientHeight;
    const region = viewRegion();
    const scale = Math.max(0.01, Math.min(
        (width - VIEW_PADDING * 2) / region.width,
        (height - VIEW_PADDING * 2) / region.height,
    ));

    stage.size({ width, height });
    content.scale({ x: scale, y: scale });
    content.position({
        x: (width - region.width * scale) / 2 - region.x * scale,
        y: (height - region.height * scale) / 2 - region.y * scale,
    });

    layoutCrop();
    placeSelectionBox();
    stage.batchDraw();
}

function buildCropControls() {
    cropShade = new Konva.Shape({
        listening: false,
        visible: false,
        fill: 'rgba(2, 6, 23, 0.6)',
        sceneFunc: (context, shape) => {
            const frame = cropDraft ?? fullImage();
            const right = frame.x + frame.width;
            const bottom = frame.y + frame.height;

            context.beginPath();
            context.rect(0, 0, natural.width, frame.y);
            context.rect(0, bottom, natural.width, natural.height - bottom);
            context.rect(0, frame.y, frame.x, frame.height);
            context.rect(right, frame.y, natural.width - right, frame.height);
            context.fillShape(shape);
        },
    });

    cropFrame = new Konva.Rect({
        visible: false,
        stroke: '#FFFFFF',
        strokeWidth: 2,
        strokeScaleEnabled: false,
        fill: 'rgba(0, 0, 0, 0.001)',
        draggable: true,
        dragBoundFunc: (position) => {
            const bounds = photoBounds();
            const scale = content.scaleX();

            return {
                x: Math.min(Math.max(position.x, bounds.left), bounds.right - cropFrame.width() * scale),
                y: Math.min(Math.max(position.y, bounds.top), bounds.bottom - cropFrame.height() * scale),
            };
        },
    });
    cropFrame.on('dragmove transform', () => {
        readCropFrame();
        stage.batchDraw();
    });

    cropHandles = new Konva.Transformer({
        visible: false,
        rotateEnabled: false,
        flipEnabled: false,
        keepRatio: false,
        ignoreStroke: true,
        borderEnabled: false,
        anchorSize: window.matchMedia('(pointer: coarse)').matches ? 24 : 14,
        anchorCornerRadius: 12,
        anchorStroke: '#FFFFFF',
        anchorFill: DARK,
        boundBoxFunc: (old_box, new_box) => {
            const bounds = photoBounds();
            const inside = new_box.x >= bounds.left - 0.5
                && new_box.y >= bounds.top - 0.5
                && new_box.x + new_box.width <= bounds.right + 0.5
                && new_box.y + new_box.height <= bounds.bottom + 0.5;

            return inside && new_box.width >= MIN_CROP && new_box.height >= MIN_CROP ? new_box : old_box;
        },
    });
}

/**
 * Everything drawn lives in `content`, in the photo's own pixels; fitting the photo
 * to the screen only scales and moves that group.
 */
function buildStage() {
    stage = new Konva.Stage({ container: stageContainer.value, width: 1, height: 1 });

    const layer = new Konva.Layer();
    stage.add(layer);

    content = new Konva.Group({
        clipFunc: (context) => {
            const region = viewRegion();
            context.rect(region.x, region.y, region.width, region.height);
        },
    });
    drawing = new Konva.Group();
    selectionBox = new Konva.Rect({
        visible: false,
        listening: false,
        stroke: '#FFFFFF',
        strokeWidth: 1.5,
        strokeScaleEnabled: false,
        dash: [6, 4],
        cornerRadius: 4,
    });
    buildCropControls();

    content.add(new Konva.Image({ image: photo, width: natural.width, height: natural.height }));
    content.add(drawing, cropShade, cropFrame, selectionBox);
    layer.add(content, cropHandles);

    stage.on('pointerdown', onPointerDown);
    stage.on('pointerclick', onStageClick);
}

function loadPhoto() {
    return new Promise((resolve, reject) => {
        const element = new window.Image();
        element.onload = () => resolve(element);
        element.onerror = () => reject(new Error('het bestand is niet te lezen'));
        element.src = imageFileUrl(props.image, { original: true });
    });
}

async function loadAnnotations() {
    const { data } = await axios.get(`/images/${props.image.id}/annotations`, {
        params: { imageable_type: props.imageableType, imageable_id: props.imageableId },
    });

    return data.annotations;
}

/** A drawing made on a photo of another size cannot be placed on this one. */
function restore(annotations) {
    if (annotations?.width !== natural.width || annotations?.height !== natural.height) return;

    shapes = annotations.shapes ?? [];
    crop = annotations.crop ?? null;
}

/**
 * The crop at the photo's own resolution, but no larger than MAX_EXPORT_SIDE: a big
 * imported photo would otherwise outgrow the upload limit.
 */
async function exportFile() {
    const region = crop ?? fullImage();
    const pixel_ratio = Math.min(1, MAX_EXPORT_SIDE / Math.max(region.width, region.height));
    const scene = new Konva.Group({ x: -region.x, y: -region.y });

    scene.add(new Konva.Rect({ width: natural.width, height: natural.height, fill: '#FFFFFF' }));
    scene.add(new Konva.Image({ image: photo, width: natural.width, height: natural.height }));
    shapes.forEach((shape) => scene.add(buildShapeNode(shape, fontFamily)));

    const canvas = scene.toCanvas({ x: 0, y: 0, width: region.width, height: region.height, pixelRatio: pixel_ratio });

    try {
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(
                (result) => (result ? resolve(result) : reject(new Error('de bewerkte foto kon niet worden gemaakt'))),
                'image/jpeg',
                EXPORT_QUALITY,
            );
        });

        return new File([blob], 'foto.jpg', { type: 'image/jpeg' });
    } finally {
        scene.destroy();
        canvas.width = 0;
        canvas.height = 0;
    }
}

async function save() {
    if (!loaded.value || saving.value) return;

    finishText();

    if (tool.value === 'crop') {
        toggleTool('crop');
    }

    saving.value = true;
    saveError.value = '';

    try {
        const file = await exportFile();
        await props.persist({
            file,
            annotations: { version: 1, width: natural.width, height: natural.height, crop, shapes },
        });
        emit('close');
    } catch (error) {
        saveError.value = requestFailureMessage(error, 'Bewaren is mislukt');
    } finally {
        saving.value = false;
    }
}

function hasChanges() {
    return canUndo.value || (cropDraft !== null && !sameRegion(cropDraft, crop ?? fullImage()));
}

function requestClose() {
    finishText();

    if (hasChanges() && !confirm('Je wijzigingen aan deze foto gaan verloren. Toch sluiten?')) return;

    emit('close');
}

/**
 * Listens before anything else on the page: a dialog underneath, such as the
 * feedback window in the planner, would otherwise close on the same Escape.
 */
function onKeydown(event) {
    if (event.key === 'Escape' && (editingText.value || selectedId.value)) {
        event.preventDefault();
        deselect();

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        requestClose();

        return;
    }

    if (event.target instanceof HTMLInputElement) return;

    if ((event.key === 'Delete' || event.key === 'Backspace') && selectedId.value) {
        event.preventDefault();
        removeSelected();
    }

    if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undo();
    }
}

onMounted(async () => {
    lock();
    dialog.value.focus();
    window.addEventListener('keydown', onKeydown, { capture: true });
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);

    let annotations = null;

    try {
        [photo, annotations] = await Promise.all([loadPhoto(), loadAnnotations()]);
    } catch (error) {
        loadError.value = requestFailureMessage(error, 'De foto kon niet worden geladen');

        return;
    }

    if (disposed) return;

    natural = { width: photo.naturalWidth, height: photo.naturalHeight };
    fontFamily = getComputedStyle(dialog.value).fontFamily;
    restore(annotations);
    tool.value = shapes.length === 0 && crop === null ? 'draw' : null;

    buildStage();
    loaded.value = true;
    fit();
    render();

    resizeObserver = new ResizeObserver(fit);
    resizeObserver.observe(viewport.value);
});

onBeforeUnmount(() => {
    disposed = true;
    unlock();
    window.removeEventListener('keydown', onKeydown, { capture: true });
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);
    resizeObserver?.disconnect();
    stage?.destroy();
});
</script>
