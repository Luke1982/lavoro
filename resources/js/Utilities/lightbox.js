/**
 * Programmatic GLightbox for viewing images in place. Replaces window.open /
 * target="_blank" image viewers: in the iOS home-screen app a same-scope
 * navigation takes over the webview with no way back, and everywhere else the
 * in-app lightbox matches how the PDF and spreadsheet viewers behave. Loaded
 * on demand — GLightbox has no business in the main bundle.
 */
export async function openImageLightbox(urls, start_index = 0) {
    const [{ default: GLightbox }] = await Promise.all([
        import('glightbox'),
        import('glightbox/dist/css/glightbox.min.css'),
    ]);

    const lightbox = GLightbox({
        elements: urls.map((href) => ({ href, type: 'image' })),
        startAt: start_index,
    });

    lightbox.on('close', () => lightbox.destroy());
    lightbox.open();
}
