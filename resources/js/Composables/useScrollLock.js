import { ref } from 'vue';

let lockCount = 0;
let lockedScrollY = 0;
let pendingScrollY = null;

const isLocked = ref(false);

export function useScrollLock() {
    // Some libraries (e.g. GLightbox) reset the page's scroll position as a
    // side effect of their own opening logic, before we get a chance to read
    // it. Call this earlier than lock() - e.g. on the triggering click, in
    // the capture phase - to grab the real value first.
    const captureScroll = () => {
        pendingScrollY = window.scrollY;
    };

    const lock = () => {
        if (lockCount === 0) {
            lockedScrollY = pendingScrollY !== null ? pendingScrollY : window.scrollY;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${lockedScrollY}px`;
            document.body.style.width = '100%';
            isLocked.value = true;
        }
        pendingScrollY = null;
        lockCount++;
    };

    const unlock = () => {
        if (lockCount === 0) return;
        lockCount--;
        if (lockCount === 0) {
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
            window.scrollTo(0, lockedScrollY);
            isLocked.value = false;
        }
    };

    return { isLocked, captureScroll, lock, unlock };
}
