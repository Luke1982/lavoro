import { computed, ref, toValue } from 'vue'

/** Zover moet de vinger zijn gekomen voordat een veeg telt. */
const COMMIT_AT = 64

/** Of zoveel sneller, over minstens zoveel pixels: een korte haal met vaart telt net zo goed. */
const FLICK_SPEED = 0.5
const FLICK_MIN = 12

/** Wie minder beweegt dan dit, tikte gewoon: die klik gaat door. */
const TAP_SLOP = 4

/** En zoveel beweging voordat de veeg zich vastlegt op zijwaarts of op en neer. */
const AXIS_LOCK = 8

/**
 * Iets zijwaarts vegen met de vinger: de menubalk opzij, een afspraak verder.
 *
 * Zodra de beweging zijwaarts blijkt zit het ding aan de vinger vast en loopt
 * het precies mee, alleen over de breedte. Op en neer blijft van de browser,
 * zodat de pagina gewoon scrolt; geef het element daarom touch-pan-y. En
 * select-none als er tekst in staat: met een muis selecteert een veeg die
 * tekst, en de volgende veeg sleept dan de selectie in plaats van het ding.
 *
 * Onder de vinger staat de verplaatsing in de stijl en de overgang uit: een
 * animatie die achter je hand aan hobbelt voelt als vertraging. Bij het loslaten
 * valt de stijl weg, zodat een overgang op het element het terug laat veren.
 *
 * @param {import('vue').MaybeRefOrGetter<HTMLElement|null>} element
 * @param {(direction: 'left'|'right') => void} onSwipe
 */
export function useSwipe(element, onSwipe) {
    const dragging = ref(false)
    const offset = ref(0)

    let pointer_id = null
    let start_x = 0
    let start_y = 0
    let anchor_x = 0
    let last_x = 0
    let last_time = 0
    let speed = 0
    let axis = null
    let just_dragged = false

    /**
     * Elke aanraking moet hier langskomen, ook een tik op een knop: pas bij het
     * loslaten daarvan vervalt het tegenhouden van de klik na een veeg. Een
     * vinger die geveegd heeft levert zelf geen klik op die dat doet. Staan er
     * knoppen in die pointerdown tegenhouden, bind dit dan in de capture-fase,
     * anders verdwijnt de eerste tik op zo'n knop na een veeg.
     */
    function onPointerDown(event) {
        if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return

        pointer_id = event.pointerId
        start_x = last_x = event.clientX
        start_y = event.clientY
        last_time = event.timeStamp
        speed = 0
        axis = null
        offset.value = 0
    }

    function onPointerMove(event) {
        if (pointer_id !== event.pointerId) return

        const dx = event.clientX - start_x
        const dy = event.clientY - start_y

        /**
         * Vanaf hier meelopen, en niet vanaf waar de vinger neerkwam: anders springt
         * het ding de eerste acht pixels in één keer bij, en juist dat sprongetje
         * verraadt dat er iets tussen jou en het ding in zit.
         */
        if (axis === null) {
            if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) return

            axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y'

            if (axis === 'x') {
                anchor_x = event.clientX
                dragging.value = true
                toValue(element)?.setPointerCapture(event.pointerId)
            }

            return
        }

        if (axis !== 'x') return

        const elapsed = event.timeStamp - last_time

        if (elapsed > 0) speed = (event.clientX - last_x) / elapsed

        last_x = event.clientX
        last_time = event.timeStamp
        offset.value = event.clientX - anchor_x
    }

    /** Eerst de veeg melden, dan pas loslaten: zo valt een wissel samen met het wegvallen van de stijl. */
    function onPointerUp(event) {
        if (pointer_id !== event.pointerId) return

        const moved = Math.abs(offset.value)
        const flicked = moved > FLICK_MIN && Math.abs(speed) > FLICK_SPEED && Math.sign(speed) === Math.sign(offset.value)

        if (axis === 'x' && (moved >= COMMIT_AT || flicked)) onSwipe(offset.value < 0 ? 'left' : 'right')

        just_dragged = moved > TAP_SLOP

        dragging.value = false
        offset.value = 0
        pointer_id = null
        axis = null
    }

    /** Wie iets verschoven heeft, bedoelde geen tik op de knop eronder. */
    function onClickCapture(event) {
        if (!just_dragged) return

        just_dragged = false
        event.preventDefault()
        event.stopPropagation()
    }

    const dragStyle = computed(() => (dragging.value ? { transform: 'translateX(' + offset.value + 'px)' } : {}))

    return { dragging, offset, dragStyle, onPointerDown, onPointerMove, onPointerUp, onClickCapture }
}
