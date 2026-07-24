const PASSTHROUGH_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

let fallback_warned = false;

/**
 * Downscales images without holding a full-size bitmap longer than needed.
 *
 * The decoded pixels of a phone photo cost width * height * 4 bytes regardless of
 * how small the file is, so every decode is released explicitly rather than left
 * to the garbage collector — iOS Safari discards the whole tab when it runs out.
 */
export function useImageCompression() {
    function outputType(file) {
        return PASSTHROUGH_TYPES.includes(file.type) ? file.type : 'image/jpeg';
    }

    function outputName(file, type) {
        return type === file.type ? file.name : file.name.replace(/\.[^.]+$/, '') + '.jpg';
    }

    function scaleToFit(width, height, max_width, max_height) {
        const ratio = Math.min(max_width / width, max_height / height, 1);

        return {
            width: Math.max(1, Math.round(width * ratio)),
            height: Math.max(1, Math.round(height * ratio)),
        };
    }

    async function decodeViaElement(file) {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.src = url;

        try {
            await (image.decode
                ? image.decode()
                : new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = reject;
                }));
        } catch (error) {
            URL.revokeObjectURL(url);
            throw error;
        }

        return {
            source: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            release: () => {
                URL.revokeObjectURL(url);
                image.src = '';
            },
        };
    }

    async function decode(file) {
        if (typeof createImageBitmap === 'function') {
            try {
                const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

                return {
                    source: bitmap,
                    width: bitmap.width,
                    height: bitmap.height,
                    release: () => bitmap.close(),
                };
            } catch (error) {
                // Older Safari rejects the imageOrientation option outright; the
                // element path below applies EXIF rotation on its own. Said out
                // loud once, because silently taking the heavier path is exactly
                // the memory behaviour this composable exists to avoid.
                if (!fallback_warned) {
                    fallback_warned = true;
                    console.warn('createImageBitmap niet bruikbaar, terugval op de zwaardere decode:', error);
                }
            }
        }

        return decodeViaElement(file);
    }

    async function compressImage(file, max_width = 1000, max_height = 1000, quality = 0.8) {
        const decoded = await decode(file);
        const canvas = document.createElement('canvas');

        try {
            const { width, height } = scaleToFit(decoded.width, decoded.height, max_width, max_height);

            canvas.width = width;
            canvas.height = height;
            canvas.getContext('2d').drawImage(decoded.source, 0, 0, width, height);

            const type = outputType(file);
            const blob = await new Promise((resolve, reject) => {
                canvas.toBlob(
                    (result) => (result ? resolve(result) : reject(new Error('Comprimeren is mislukt'))),
                    type,
                    quality
                );
            });

            return new File([blob], outputName(file, type), { type });
        } finally {
            decoded.release();
            canvas.width = 0;
            canvas.height = 0;
        }
    }

    return { compressImage };
}
