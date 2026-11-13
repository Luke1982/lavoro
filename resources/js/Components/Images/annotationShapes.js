import Konva from 'konva';

/**
 * What a drawing on a photo looks like. Shapes are plain data in the photo's own
 * pixels, so the same description draws on screen and into the saved file.
 *
 * Every shape carries a size: one hundredth of the photo, or of the crop, as it was
 * on screen when it was drawn. Strokes, arrowheads and labels scale with it.
 */
export const DARK = '#081020';

export const COLOURS = [
    { value: '#C6FF00', name: 'Geel' },
    { value: '#EF4444', name: 'Rood' },
    { value: '#F59E0B', name: 'Oranje' },
    { value: '#2563FF', name: 'Blauw' },
    { value: '#FFFFFF', name: 'Wit' },
    { value: DARK, name: 'Zwart' },
];

const STROKE = 0.8;
const OUTLINE = 1.4;
const ARROWHEAD = 3.2;
const FONT = 2.2;
const LABEL_PADDING = 0.55;

/** A tenth of a pixel is finer than anyone draws, and keeps the stored drawing short. */
export function round(value) {
    return Math.round(value * 10) / 10;
}

function isDark(hex) {
    const value = parseInt(hex.slice(1), 16);
    const red = (value >> 16) & 255;
    const green = (value >> 8) & 255;
    const blue = value & 255;

    return 0.299 * red + 0.587 * green + 0.114 * blue < 140;
}

/** A dark rim around a light colour and a light one around a dark colour, so a stroke shows on any photo. */
function outlineFor(colour) {
    return isDark(colour) ? '#FFFFFF' : DARK;
}

export function labelHeight(size) {
    return size * FONT * (1 + LABEL_PADDING * 2);
}

function strokeNodes(shape, Kind, extra = {}) {
    const common = { points: shape.points, lineCap: 'round', lineJoin: 'round', ...extra };
    const rim = outlineFor(shape.colour);

    return [
        new Kind({ ...common, stroke: rim, fill: rim, strokeWidth: shape.size * OUTLINE }),
        new Kind({ ...common, stroke: shape.colour, fill: shape.colour, strokeWidth: shape.size * STROKE }),
    ];
}

function labelNodes(shape, font_family) {
    const font_size = shape.size * FONT;
    const text = new Konva.Text({
        x: font_size * 0.35,
        text: shape.text || ' ',
        fontSize: font_size,
        fontFamily: font_family,
        fontStyle: '600',
        fill: outlineFor(shape.colour),
        padding: font_size * LABEL_PADDING,
    });
    const pill = new Konva.Rect({
        width: text.width() + font_size * 0.7,
        height: text.height(),
        cornerRadius: text.height() / 2,
        fill: shape.colour,
        stroke: outlineFor(shape.colour),
        strokeWidth: shape.size * 0.25,
        shadowColor: DARK,
        shadowOpacity: 0.25,
        shadowBlur: shape.size,
        shadowOffsetY: shape.size * 0.4,
    });

    return [pill, text];
}

/**
 * One shape as a Konva group with the shape's id. A label sits at its own x and y;
 * strokes and arrows carry their position in their points.
 */
export function buildShapeNode(shape, font_family) {
    const group = new Konva.Group({ id: shape.id, name: 'shape' });

    if (shape.type === 'draw') {
        group.add(...strokeNodes(shape, Konva.Line, { tension: 0.35 }));
    }

    if (shape.type === 'arrow') {
        group.add(...strokeNodes(shape, Konva.Arrow, { pointerLength: shape.size * ARROWHEAD, pointerWidth: shape.size * ARROWHEAD }));
    }

    if (shape.type === 'text') {
        group.position({ x: shape.x, y: shape.y });
        group.add(...labelNodes(shape, font_family));
    }

    return group;
}

/** Moves a shape's stored position along by what it was dragged. */
export function moveShape(shape, dx, dy) {
    if (shape.type === 'text') {
        shape.x = round(shape.x + dx);
        shape.y = round(shape.y + dy);

        return;
    }

    shape.points = shape.points.map((value, index) => round(value + (index % 2 === 0 ? dx : dy)));
}
