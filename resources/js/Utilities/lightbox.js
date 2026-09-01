/**
 * Programmatic GLightbox for viewing images in place. Replaces window.open /
 * target="_blank" image viewers: in the iOS home-screen app a same-scope
 * navigation takes over the webview with no way back, and everywhere else the
 * in-app lightbox matches how the PDF and spreadsheet viewers behave. Loaded
 * on demand — GLightbox has no business in the main bundle.
 */
/**
 * GLightbox zet `object-fit: cover` en een `min-width` op de afbeelding. Cover
 * snijdt de randen eraf om het vak te vullen, dus een staande foto verloor zijn
 * boven- en onderkant en een smalle werd uitgerekt tot 200 pixels. Dat hoort
 * `contain` te zijn: passend maken, niets afsnijden.
 *
 * Eén keer toegevoegd, na het stylesheet van de bibliotheek zodat het wint
 * zonder !important overal.
 */
function correctImageFit() {
    if (document.getElementById('lightbox-fit')) return;

    const style = document.createElement('style');
    style.id = 'lightbox-fit';
    style.textContent = `
        .gslide-image img {
            object-fit: contain;
            min-width: 0;
            max-width: 100vw;
            max-height: 100vh;
        }
    `;
    document.head.append(style);
}

export async function openImageLightbox(urls, start_index = 0) {
    const [{ default: GLightbox }] = await Promise.all([
        import('glightbox'),
        import('glightbox/dist/css/glightbox.min.css'),
    ]);

    correctImageFit();

    const lightbox = GLightbox({
        elements: urls.map((href) => ({ href, type: 'image' })),
        startAt: start_index,
    });

    lightbox.on('close', () => lightbox.destroy());
    lightbox.open();
}
