import heif_worker_url from './heifDecoder.worker.js?worker&url';

const PASSTHROUGH_TYPES = ['image/jpeg', 'image/png', 'image/gif'];
const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

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

    /**
     * Older Safari rejects the imageOrientation option outright; the element path
     * applies EXIF rotation on its own. Taking that heavier path is said out loud
     * once, because doing so silently is exactly the memory behaviour this
     * composable exists to avoid. Only once it has worked: a HEIC photo that
     * neither path can read took no heavier path, so it warns about nothing.
     */
    async function decodeNatively(file) {
        if (typeof createImageBitmap !== 'function') {
            return decodeViaElement(file);
        }

        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

            return {
                source: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                release: () => bitmap.close(),
            };
        } catch (error) {
            const decoded = await decodeViaElement(file);

            if (!fallback_warned) {
                fallback_warned = true;
                console.warn('createImageBitmap niet bruikbaar, terugval op de zwaardere decode:', error);
            }

            return decoded;
        }
    }

    /**
     * Read from the file's own ftyp box rather than its name or type, which a
     * gallery picker does not always pass on.
     */
    async function isHeif(file) {
        const box = String.fromCharCode(...new Uint8Array(await file.slice(4, 12).arrayBuffer()));

        return box.startsWith('ftyp') && HEIF_BRANDS.includes(box.slice(4));
    }

    /**
     * During development Vite serves the worker from its own port, and a browser
     * starts a worker only from the page's origin; a one-line module importing it
     * gets around that. A build serves both from one place and needs no detour.
     */
    async function decodeInHeifWorker(buffer) {
        const url = new URL(heif_worker_url, location.href);
        const bootstrap = url.origin === location.origin
            ? null
            : URL.createObjectURL(new Blob(['import ' + JSON.stringify(url.href) + ';'], { type: 'text/javascript' }));
        let worker = null;

        try {
            worker = new Worker(bootstrap ?? url, { type: 'module' });

            return await new Promise((resolve, reject) => {
                worker.onmessage = ({ data }) => {
                    if (data.error) {
                        reject(new Error('deze HEIC-foto is niet te lezen (' + data.error + ')'));
                    } else {
                        resolve(data);
                    }
                };
                worker.onerror = () => reject(new Error('de HEIC-lezer kon niet worden geladen'));
                worker.postMessage(buffer, [buffer]);
            });
        } finally {
            worker?.terminate();

            if (bootstrap) {
                URL.revokeObjectURL(bootstrap);
            }
        }
    }

    /**
     * Chrome, Firefox and the Android app cannot read HEIC, the format Samsung and
     * iPhone cameras save in when set to "high efficiency". libheif can, in a
     * worker of its own (see heifDecoder.worker.js) that is fetched the first time
     * a HEIC photo is picked and comes from the browser cache after that.
     */
    async function decodeViaLibheif(file) {
        const pixels = await decodeInHeifWorker(await file.arrayBuffer());
        const canvas = document.createElement('canvas');

        canvas.width = pixels.width;
        canvas.height = pixels.height;
        canvas.getContext('2d').putImageData(pixels, 0, 0);

        return {
            source: canvas,
            width: canvas.width,
            height: canvas.height,
            release: () => {
                canvas.width = 0;
                canvas.height = 0;
            },
        };
    }

    async function decode(file) {
        try {
            return await decodeNatively(file);
        } catch (error) {
            if (await isHeif(file)) {
                return decodeViaLibheif(file);
            }

            throw new Error('dit bestand is niet als foto te lezen (' + (file.type || 'onbekend type') + ')', { cause: error });
        }
    }

    /**
     * Its own errors give the reason in lower case, to follow a heading such as
     * the upload queue's "Comprimeren is mislukt: ".
     */
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
                    (result) => (result ? resolve(result) : reject(new Error('de verkleinde foto kon niet worden opgeslagen'))),
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
