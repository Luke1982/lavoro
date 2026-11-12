import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useScrollLock } from '@/Composables/useScrollLock.js'

/**
 * Zoveel moet er in één richting gescrold zijn voordat de balk reageert: een
 * duim die even trilt is geen besluit om te gaan lezen of terug te gaan.
 */
const THRESHOLD = 8

/**
 * De balk bovenin op een telefoon: weg zodra je naar beneden scrolt, terug zodra
 * je omhoog scrolt. Lezen krijgt het hele scherm, en zoeken of de meldingen
 * liggen één veeg omhoog verderop.
 *
 * Bovenaan de pagina staat hij altijd, anders gaapt daar het gat waar hij stond.
 * Wat onder de balk tegen de bovenrand plakt leest uit --app-header-offset hoe
 * hoog hij echt is, en krijgt nul zolang hij weg is.
 *
 * Een geopende foto of het afrondvenster zet de pagina even vast en springt
 * daarbij naar boven en weer terug. Dat is geen scrollen en telt dus niet mee.
 *
 * @param {import('vue').Ref<HTMLElement|null>} header
 */
export function useHeaderReveal(header) {
    const hidden = ref(false)
    const { isLocked } = useScrollLock()

    let last_y = 0
    let frame = null

    function update() {
        frame = null
        const element = header.value

        if (!element || element.getClientRects().length === 0 || isLocked.value) return

        const bottom = document.documentElement.scrollHeight - window.innerHeight
        const y = Math.min(Math.max(window.scrollY, 0), bottom)

        if (y <= element.offsetHeight) {
            hidden.value = false
            last_y = y
            return
        }

        if (Math.abs(y - last_y) < THRESHOLD) return

        hidden.value = y > last_y
        last_y = y
    }

    function onScroll() {
        frame ??= requestAnimationFrame(update)
    }

    /** Wie met het toetsenbord in de balk belandt, moet hem ook zien. */
    function reveal() {
        hidden.value = false
    }

    function publishOffset() {
        const height = hidden.value ? 0 : (header.value?.offsetHeight ?? 0)

        document.documentElement.style.setProperty('--app-header-offset', height + 'px')
    }

    watch(hidden, publishOffset)

    onMounted(() => {
        publishOffset()
        window.addEventListener('scroll', onScroll, { passive: true })
        window.addEventListener('resize', publishOffset)
    })

    onBeforeUnmount(() => {
        window.removeEventListener('scroll', onScroll)
        window.removeEventListener('resize', publishOffset)
        cancelAnimationFrame(frame)
        document.documentElement.style.removeProperty('--app-header-offset')
    })

    return { hidden, reveal }
}
