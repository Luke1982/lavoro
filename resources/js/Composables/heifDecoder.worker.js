import createLibheif from 'libheif-js/libheif-wasm/libheif-bundle.mjs';

/**
 * Decodes one HEIC/HEIF photo to an ImageData, away from the page.
 *
 * libheif decodes synchronously and a 12 megapixel photo takes seconds, which on
 * the main thread would freeze the upload screen photo after photo. Its WebAssembly
 * memory also only ever grows, so the page starts one of these per photo and
 * terminates it afterwards: that is the only way to hand the memory back.
 *
 * libheif applies the rotation stored in the file itself, so the pixels arrive
 * upright and the canvas needs no EXIF handling of its own.
 *
 * The runtime can be ready before createLibheif has returned, so it is taken from
 * `this` in the callback rather than from the return value.
 */
self.onmessage = async ({ data: buffer }) => {
    try {
        const libheif = await new Promise((resolve, reject) => {
            createLibheif({
                onRuntimeInitialized() {
                    resolve(this);
                },
                onAbort: reject,
            });
        });

        const images = new libheif.HeifDecoder().decode(new Uint8Array(buffer));
        const image = images.find((candidate) => candidate.is_primary()) ?? images[0];

        if (!image) {
            throw new Error('geen afbeelding gevonden');
        }

        const pixels = new ImageData(image.get_width(), image.get_height());

        await new Promise((resolve, reject) => {
            image.display(pixels, (result) => (result ? resolve() : reject(new Error('decoderen is mislukt'))));
        });

        self.postMessage(pixels, [pixels.data.buffer]);
    } catch (error) {
        self.postMessage({ error: String(error?.message ?? error) });
    }
};
