import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { hasAnyPermission, hasPermission, initials as getInitials } from '@/Utilities/Utilities'
import menu from '@/Navigation/menu.json'

/**
 * Het menu, één keer uitgerekend voor elk oppervlak dat het toont.
 *
 * De vorm staat in menu.json en nergens anders: secties, hun accentkleur, welke
 * rechten een regel nodig heeft, welke regels onder elkaar hangen en welke een
 * teller of een stip dragen. Deze composable maakt daar een boom van die de
 * ingelogde gebruiker echt mag zien, en houdt bij wat openstaat en wat actief
 * is. Wie iets aan het menu wil veranderen, verandert de JSON.
 *
 * De open- en ingeklapte staat leeft buiten de composable, zodat de zijbalk en
 * het mobiele paneel het over hetzelfde eens zijn en niet ieder hun eigen idee
 * hebben van welke sectie openstaat.
 */
const OPEN_STATE_KEY = 'menuOpenState'
const COLLAPSED_KEY = 'desktopSidebarCollapsed'

const readStored = (key, fallback) => {
    if (typeof window === 'undefined') return fallback
    try {
        const raw = window.localStorage?.getItem(key)
        return raw === null || raw === undefined ? fallback : JSON.parse(raw)
    } catch {
        return fallback
    }
}

const store = (key, value) => {
    try {
        window.localStorage?.setItem(key, JSON.stringify(value))
    } catch {
        // Een menu dat zijn stand niet kan onthouden werkt verder gewoon.
    }
}

const open_ids = ref(new Set(readStored(OPEN_STATE_KEY, [])))

/**
 * Afmelden duurt merkbaar lang: het stopt de plaatsbepaling, zegt de pushdienst
 * op, schrijft de service worker uit en gooit alle caches weg. Buiten de
 * composable, zodat elke uitlogknop — de kaart, het smalle spoor, het mobiele
 * paneel — dezelfde stand toont zonder dat iemand hem doorgeeft.
 */
const logging_out = ref(false)

/**
 * Zes componenten gebruiken deze composable, en zonder deze grens zou elk van hen
 * bij iedere navigatie dezelfde tak opnieuw openklappen. Eén keer per pad is
 * genoeg; wie later komt, treft het al gedaan aan.
 */
let opened_for_path = null

/**
 * De vorige zijbalk bewaarde dit als '1' en '0'. Wie de balk ooit heeft
 * ingeklapt vindt hem zo niet uitgeklapt terug na een update.
 */
const collapsed = ref([true, 1].includes(readStored(COLLAPSED_KEY, false)))

export function useMenu() {
    const page = usePage()

    const authUser = computed(() => page.props.auth?.user ?? null)
    const isAdmin = computed(() => !!page.props.auth?.isAdmin)
    const isSuperAdmin = computed(() => !!page.props.auth?.isSuperAdmin)
    const initials = computed(() => (authUser.value?.name ? getInitials(authUser.value.name) : ''))
    const userRoles = computed(() => (authUser.value?.roles || []).join(', '))
    const companyName = computed(() => page.props.company?.name ?? null)
    const companyLogo = computed(() => page.props.company?.logo_url ?? null)

    /** Tellers en stippen die de server per verzoek meestuurt. */
    const badges = computed(() => page.props.nav ?? {})

    const currentPath = computed(() => {
        const url = page?.url
        const path = typeof url === 'string' ? url : (typeof window !== 'undefined' ? window.location.pathname : '')
        return path.split('?')[0]
    })

    /**
     * adminOnly en explicitPermission bestaan naast elkaar omdat ze verschillende
     * vragen stellen: de eerste laat alleen beheerders toe, de tweede juist
     * alleen wie het recht met zoveel woorden gekregen heeft — beheerder zijn is
     * daar niet genoeg, zoals bij technisch beheer.
     */
    const maySee = (item) => {
        if (item.superadminOnly) return isSuperAdmin.value
        if (item.adminOnly) return isAdmin.value
        if (item.explicitPermission) return (page.props.auth?.permissions || []).includes(item.explicitPermission)
        if (item.anyPermission) return hasAnyPermission(item.anyPermission)
        if (item.permission) return hasPermission(item.permission)
        return true
    }

    const isActive = (item) => {
        if (!item.href || item.href.startsWith('mailto:')) return false
        if (item.exact) return currentPath.value === item.href
        return currentPath.value === item.href || currentPath.value.startsWith(item.href + '/')
    }

    /**
     * Een regel zonder eigen pagina bestaat alleen voor wat eronder hangt, dus die
     * verdwijnt zodra daar niets meer van te zien is. Een regel mét pagina blijft
     * staan, ook als de gebruiker geen van de onderliggende schermen mag openen.
     *
     * De actieve regel wordt naar boven doorgegeven: een geopend onderdeel laat
     * zijn hele tak oplichten, en dat is ook wat de tak vanzelf openklapt.
     */
    const resolve = (item, depth = 0) => {
        if (!maySee(item)) return null

        const children = (item.children || [])
            .map((child) => resolve(child, depth + 1))
            .filter(Boolean)

        if (!item.href && children.length === 0) return null

        const self_active = isActive(item)
        const child_active = children.some((child) => child.active)

        return {
            ...item,
            depth,
            children,
            active: self_active || child_active,
            current: self_active && !child_active,
            indicatorValue: item.indicator ? badges.value[item.indicator] : null,
            badgeValue: item.badge ? badges.value[item.badge] : null,
        }
    }

    const resolveAll = (items, depth = 0) => (items || []).map((item) => resolve(item, depth)).filter(Boolean)

    const pinned = computed(() => resolveAll(menu.pinned))

    const sections = computed(() =>
        menu.sections
            .map((section) => ({ ...section, items: resolveAll(section.items) }))
            .filter((section) => section.items.length > 0)
    )

    const cards = computed(() => menu.cards)
    const createActions = computed(() => menu.createActions.filter(maySee))
    const search = computed(() => menu.search)
    const mobileTabs = computed(() =>
        menu.mobileTabs
            .filter(maySee)

            /**
             * De plusknop verdwijnt als er niets is dat deze persoon mag aanmaken.
             * Hij heeft geen eigen recht, want wat hij opent zijn de rechten van de
             * vier knoppen eronder; blijven die alle vier weg, dan opent hij niets.
             */
            .filter((tab) => tab.action !== 'create' || createActions.value.length > 0)
            .map((tab) => ({ ...tab, badgeValue: tab.badge ? badges.value[tab.badge] : null, active: isActive(tab) }))
    )

    /**
     * Elke regel met een eigen pagina, plat, met het pad ernaartoe erbij. Het
     * zoekvenster leest hier zijn bestemmingen uit, zodat een menu-item dat hier
     * verschijnt daar meteen te vinden is.
     */
    const destinations = computed(() => {
        const flat = []

        const walk = (items, trail) => {
            items.forEach((item) => {
                const path = [...trail, item.label]
                if (item.href && !item.href.startsWith('mailto:')) {
                    flat.push({
                        id: item.id,
                        label: item.label,
                        href: item.href,
                        icon: item.icon ?? null,
                        trail: path.slice(0, -1),
                    })
                }
                walk(item.children || [], path)
            })
        }

        walk(pinned.value, [])
        sections.value.forEach((section) => walk(section.items, [section.label]))

        return flat
    })

    const isOpen = (item) => open_ids.value.has(item.id)

    const toggle = (item) => {
        const next = new Set(open_ids.value)
        next.has(item.id) ? next.delete(item.id) : next.add(item.id)
        open_ids.value = next
        store(OPEN_STATE_KEY, [...next])
    }

    const setCollapsed = (value) => {
        collapsed.value = value
        store(COLLAPSED_KEY, value)
    }

    /**
     * Waar je bent staat open. Wat je zelf hebt opengeklapt blijft ook open, want
     * anders klapt het menu dicht op het moment dat je ergens anders heen gaat.
     */
    const openActiveTrail = () => {
        if (opened_for_path === currentPath.value) return
        opened_for_path = currentPath.value

        const next = new Set(open_ids.value)

        const walk = (items) => {
            items.forEach((item) => {
                if (item.active && item.children.length) next.add(item.id)
                walk(item.children)
            })
        }

        sections.value.forEach((section) => walk(section.items))
        open_ids.value = next
    }

    watch(currentPath, openActiveTrail, { immediate: true })

    return {
        pinned,
        sections,
        cards,
        createActions,
        search,
        mobileTabs,
        destinations,
        badges,
        collapsed,
        setCollapsed,
        isOpen,
        toggle,
        authUser,
        isAdmin,
        initials,
        userRoles,
        companyName,
        companyLogo,
        loggingOut: logging_out,
        setLoggingOut: (value) => { logging_out.value = value },
    }
}
