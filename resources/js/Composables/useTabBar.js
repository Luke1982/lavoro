import { ref, watch } from 'vue'

/**
 * De menubalk onderaan op een telefoon, gedeeld met alles wat erboven zweeft.
 *
 * Een knop die vast onderin staat moet weten waar de balk is: staat hij er,
 * dan hoort de knop erboven; is hij opzij geveegd, dan mag de knop de vrije
 * ruimte innemen, en aan de kant waar de balk heen ging ligt het lipje dat hem
 * terughaalt. De balk zelf vult deze stand in, de rest leest hem alleen.
 *
 * De richting wordt onthouden, ook na een verversing: iemand die de balk opzij
 * schuift om de onderste rij van een lijst te kunnen zien, wil hem niet bij
 * elke pagina terug hebben.
 */
const TUCK_KEY = 'lavoro_tabbar_tucked'

function readTuck() {
    try {
        const side = localStorage.getItem(TUCK_KEY)

        return side === 'left' || side === 'right' ? side : null
    } catch {
        return null
    }
}

const tucked = ref(readTuck())

/**
 * Hoeveel pixels de balk boven zijn onderlijn uitsteekt, de grote knop die
 * erboven uitsteekt meegerekend. De onderlijn is max(0.75rem, de veilige marge
 * van het toestel); nul waar er geen balk is, zoals op een computer.
 */
const clearance = ref(0)

watch(tucked, (side) => {
    try {
        if (side) localStorage.setItem(TUCK_KEY, side)
        else localStorage.removeItem(TUCK_KEY)
    } catch {
        /* een browser die niets wil onthouden hoeft niets te onthouden */
    }
})

export function useTabBar() {
    return { tucked, clearance }
}
