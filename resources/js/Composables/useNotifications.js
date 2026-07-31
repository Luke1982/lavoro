import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'

/**
 * Het belletje: wat er te lezen valt, wat er nog ongelezen van is, en het
 * doorstrepen daarvan.
 *
 * De teller komt bij het laden van elke pagina mee als eigenschap, zodat het
 * cijfer meteen goed staat zonder eerst iets op te halen. Zodra er iets geopend
 * of doorgestreept wordt, neemt het antwoord van de server het over: die weet het
 * beter dan een pagina die al even openstaat.
 */
const items = ref([])
const loading = ref(false)
const loaded_filter = ref(null)
const server_unread = ref(null)

const FILTERS = {
    alles: {},
    ongelezen: { unread: 1 },
    belangrijk: { important: 1 },
}

export function useNotifications() {
    const page = usePage()

    const unreadCount = computed(() => server_unread.value ?? page.props.nav?.unread_notifications ?? 0)

    const filter = ref('alles')

    async function load(name = filter.value) {
        filter.value = name
        loading.value = true

        try {
            const { data } = await axios.get('/usernotifications/feed', { params: FILTERS[name] ?? {} })
            items.value = data.notifications.data
            server_unread.value = data.unread_count
            loaded_filter.value = name
        } catch (error) {
            console.error('Kon meldingen niet ophalen:', error)
        } finally {
            loading.value = false
        }
    }

    /**
     * Openen laadt alleen als er nog niets staat of als er op een ander tabblad
     * gekeken wordt: opnieuw ophalen wat er al staat laat de lijst knipperen.
     */
    async function open() {
        if (items.value.length === 0 || loaded_filter.value !== filter.value) {
            await load()
        }
    }

    async function acknowledge(notification) {
        const read = notification.read_at !== null
        const url = '/usernotifications/' + notification.id + '/read'

        try {
            const { data } = read ? await axios.delete(url) : await axios.patch(url)
            const index = items.value.findIndex((item) => item.id === notification.id)
            if (index !== -1) items.value[index] = data.notification
            server_unread.value = data.unread_count
        } catch (error) {
            console.error('Kon melding niet bijwerken:', error)
        }
    }

    async function acknowledgeAll() {
        try {
            const { data } = await axios.post('/usernotifications/read-all')
            items.value = items.value.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() }))
            server_unread.value = data.unread_count
        } catch (error) {
            console.error('Kon meldingen niet markeren:', error)
        }
    }

    /**
     * Aanklikken doet allebei: de melding is gezien én je gaat erheen. Openen
     * zonder wegstrepen zou betekenen dat je hem daarna nog een keer moet
     * opruimen.
     */
    async function follow(notification) {
        if (!notification.read_at) await acknowledge(notification)
        if (notification.url) router.visit(notification.url)
    }

    return { items, loading, filter, unreadCount, load, open, acknowledge, acknowledgeAll, follow }
}
