/**
 * iOS home-screen web-apps have no download manager: navigating to a
 * Content-Disposition: attachment response strands the user on a blank
 * screen without browser chrome. There, downloads go through the native
 * share sheet ("Bewaar in Bestanden") instead of an anchor click.
 * `navigator.standalone` only exists on iOS and is true exactly there;
 * Android (browser, PWA and the Capacitor app) keeps the plain anchor.
 */
export const isIosStandalone = () => window.navigator.standalone === true;

function anchorDownload(href, filename) {
    const link = document.createElement('a');

    link.href = href;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

/**
 * True once the share sheet has been shown — dismissing it counts too,
 * because at that point there is nothing left to fall back to.
 */
async function shareFile(blob, filename) {
    const file = new File([blob], filename, { type: blob.type || 'application/octet-stream' });

    if (!navigator.canShare || !navigator.canShare({ files: [file] })) return false;

    try {
        await navigator.share({ files: [file] });
    } catch (error) {
        if (error.name !== 'AbortError') return false;
    }

    return true;
}

export async function downloadBlob(blob, filename) {
    if (isIosStandalone() && await shareFile(blob, filename)) return;

    const object_url = URL.createObjectURL(blob);

    anchorDownload(object_url, filename);
    URL.revokeObjectURL(object_url);
}

async function fetchAndShare(url, filename) {
    try {
        const response = await fetch(url);

        if (!response.ok) return false;

        return await shareFile(await response.blob(), filename);
    } catch {
        return false;
    }
}

/**
 * Outside iOS standalone this is a plain anchor download. Inside it, the bytes
 * are fetched and handed to the share sheet; when that fails (old iOS version,
 * network error) fallback_url — meant for an inline view such as /preview —
 * opens in the in-app browser, which does have a close button.
 */
export async function downloadFile(url, filename, fallback_url = null) {
    if (!isIosStandalone()) {
        anchorDownload(url, filename);

        return;
    }

    if (await fetchAndShare(url, filename)) return;

    window.open(fallback_url ?? url, '_blank', 'noopener');
}

/**
 * For URLs that render inline (PDF streams, previews): a real new tab in a
 * browser, but the share sheet in the iOS home-screen app — a new tab does
 * not exist there, and target="_blank" on a same-scope URL takes over the
 * webview with no way back.
 */
export async function openInNewTab(url, filename) {
    if (isIosStandalone() && await fetchAndShare(url, filename)) return;

    window.open(url, '_blank', 'noopener');
}
