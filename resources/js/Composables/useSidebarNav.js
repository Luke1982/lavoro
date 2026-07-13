import { ref, computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { hasPermission, initials as getInitials } from '@/Utilities/Utilities'
import {
    HomeIcon,
    UsersIcon,
    UserIcon,
    CubeIcon,
    Square3Stack3DIcon,
    FingerPrintIcon,
    TagIcon,
    LinkIcon,
    PuzzlePieceIcon,
    ExclamationCircleIcon,
    DocumentTextIcon,
    Bars4Icon,
    CheckIcon,
    Squares2X2Icon,
    SwatchIcon,
    FolderIcon,
    ScaleIcon,
    TruckIcon,
    CalendarIcon,
    AdjustmentsHorizontalIcon,
    WrenchScrewdriverIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'
import { ClipboardList as ClipboardListIcon } from '@lucide/vue'

export const SUPPORT_MAILTO = 'mailto:info@majorlabel.nl'

export const BOTTOM_LINK_CLASS = 'group flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors duration-150'

/**
 * Sidebar row colour classes for a given active / expanded state.
 * @param {boolean} active
 * @param {boolean} open
 * @returns {string}
 */
export const rowStateClass = (active, open = false) =>
    active
        ? 'bg-sidebar-active text-white shadow-sm'
        : open
            ? 'bg-sidebar-active/10 text-sidebar-text hover:bg-sidebar-active/15'
            : 'text-sidebar-muted hover:bg-sidebar-hover hover:text-sidebar-text'

const readOpenState = () => {
    if (typeof window === 'undefined') return {}
    try {
        return JSON.parse(localStorage.getItem('navOpenState') || '{}')
    } catch {
        return {}
    }
}

/**
 * Navigation state, permissions and helpers shared by every sidebar surface
 * (desktop rail, mobile drawer and the mobile top bar title).
 */
export function useSidebarNav() {
    const page = usePage()

    const authUser = computed(() => page.props.auth?.user)
    const isAdmin = computed(() => !!page.props.auth?.isAdmin)
    const initials = computed(() => (authUser.value?.name ? getInitials(authUser.value.name) : ''))
    const userRoles = computed(() => (authUser.value?.roles || []).join(', '))
    const companyLogo = computed(() => page.props.company?.logo_url || null)
    const companyName = computed(() => page.props.company?.name || null)

    const canSeeUsers = computed(() => isAdmin.value || hasPermission('user.read'))
    const canSeeUserRoles = computed(() => isAdmin.value || hasPermission('userrole.read'))
    const canManageStandardEmails = computed(() => isAdmin.value || hasPermission('standardemail.manage'))
    const canManageStandardAttachments = computed(() => isAdmin.value || hasPermission('standardattachment.manage'))
    const isTechnischBeheer = computed(() => (page.props.auth?.permissions || []).includes('technical.management'))

    const currentPath = computed(() => {
        const url = page?.url
        if (url && typeof url === 'string') return url
        return typeof window !== 'undefined' ? window.location.pathname : ''
    })

    const isCompanyCurrent = computed(() => currentPath.value === '/companies')

    const navigation = ref([
        { name: 'Dashboard', href: '/', icon: HomeIcon, current: true },
        {
            name: 'Klanten',
            href: '/customers',
            icon: UsersIcon,
            current: false,
            requiresPermission: 'customer.read',
            children: [
                { name: 'Contacten', href: '/contacts', icon: UserIcon, current: false, requiresPermission: 'contact.read' },
            ],
            open: false,
        },
        {
            name: 'Producten',
            href: '/products',
            icon: CubeIcon,
            current: false,
            requiresPermission: 'product.read',
            children: [
                { name: 'Product types', href: '/producttypes', icon: Square3Stack3DIcon, current: false, requiresPermission: 'producttype.read' },
                { name: 'Merken', href: '/brands', icon: FingerPrintIcon, current: false, requiresPermission: 'brand.read' },
                { name: 'Kenmerken', href: '/productattributes', icon: TagIcon, current: false, requiresPermission: 'productattribute.read' },
                { name: 'Relatietypes', href: '/productrelations', icon: LinkIcon, current: false, requiresPermission: 'productrelation.read' },
            ],
            open: false,
        },
        { name: 'Machines', href: '/assets', icon: PuzzlePieceIcon, current: false, requiresPermission: 'asset.read' },
        { name: 'Storingen', href: '/tickets', icon: ExclamationCircleIcon, current: false, requiresPermission: 'ticket.see_all' },
        {
            name: 'Werkbonnen',
            href: '/serviceorders',
            icon: DocumentTextIcon,
            current: false,
            requiresAnyPermission: ['serviceorder.read', 'serviceorder.read_own'],
            children: [
                { name: 'Fases', href: '/serviceorderstages', icon: Bars4Icon, current: false, requiresPermission: 'serviceorderstage.read' },
                { name: 'Taken', href: '/serviceordertasks', icon: ClipboardListIcon, current: false, requiresPermission: 'serviceordertask.read' },
            ],
            open: false,
        },
        {
            name: 'Keurpunten',
            href: '/servicechecks',
            icon: CheckIcon,
            current: false,
            requiresPermission: 'servicecheck.read',
            children: [
                { name: 'Groepen', href: '/servicecheckgroups', icon: Squares2X2Icon, current: false, requiresPermission: 'servicecheckgroup.read' },
            ],
            open: false,
        },
        {
            name: 'Materialen',
            href: '/materials',
            icon: SwatchIcon,
            current: false,
            requiresPermission: 'material.read',
            children: [
                { name: 'Categorieën', href: '/materialcategories', icon: FolderIcon, current: false, requiresPermission: 'materialcategory.read' },
                { name: 'Gebruikseenheden', href: '/materialusageunits', icon: ScaleIcon, current: false, requiresPermission: 'materialusageunit.read' },
            ],
            open: false,
        },
        {
            name: 'Leveranciers',
            href: '/suppliers',
            icon: TruckIcon,
            current: false,
            requiresPermission: 'supplier.read',
        },
        {
            name: 'Planner',
            href: '/planner',
            icon: CalendarIcon,
            current: false,
            requiresPermission: 'event.read',
            children: [
                { name: 'Afspraaktypes', href: '/eventtypes', icon: AdjustmentsHorizontalIcon, current: false, requiresPermission: 'eventtype.read' },
                { name: 'Gebruikersrollen', href: '/userroles', icon: AdjustmentsHorizontalIcon, current: false, requiresPermission: canSeeUserRoles },
            ],
            open: false,
        },
        { name: 'Extra velden', href: '/customfields', icon: WrenchScrewdriverIcon, current: false, requiresPermission: 'customfield.read' },
        { name: 'Projecten', href: '/projects', icon: ClipboardDocumentListIcon, current: false, requiresPermission: 'project.read' },
    ])

    const lists = [
        { id: 1, name: 'Aankomende keuringen en storingen', href: '/upcomingactivities', initial: 'A', requiresPermission: 'activitylist.read' },
    ]

    const canSeeNavItem = (item) => {
        if (item?.adminOnly) return isAdmin.value
        if (item?.requiresAnyPermission) return item.requiresAnyPermission.some(hasPermission)
        if (!item?.requiresPermission) return true
        return hasPermission(item.requiresPermission)
    }

    const filteredNavigation = computed(() => navigation.value.filter(canSeeNavItem))

    const visibleChildren = (item) => {
        if (!item?.children) return []
        return item.children.filter(canSeeNavItem)
    }

    const filteredLists = computed(() =>
        lists.filter(canSeeNavItem).map((list) => ({
            ...list,
            current: currentPath.value === list.href || currentPath.value.startsWith(list.href + '/'),
        }))
    )

    const persistOpenState = () => {
        if (typeof window === 'undefined') return
        const state = {}
        navigation.value.forEach((item) => {
            if (item.children) state[item.name] = !!item.open
        })
        localStorage.setItem('navOpenState', JSON.stringify(state))
    }

    /**
     * Toggle a section open/closed and remember the choice.
     * @param {{open:boolean}} item
     */
    const toggleSection = (item) => {
        item.open = !item.open
        persistOpenState()
    }

    const initializeNavState = () => {
        const path = currentPath.value
        const saved = readOpenState()
        navigation.value.forEach((item) => {
            item.current = false
            if (item.children) {
                item.open = Object.prototype.hasOwnProperty.call(saved, item.name) ? !!saved[item.name] : false
                item.children.forEach((c) => {
                    c.current = path === c.href || path.startsWith(c.href + '/')
                })
                const anyChildCurrent = item.children.some((c) => c.current)
                if (anyChildCurrent) {
                    item.open = true
                    item.current = true
                }
            } else if (path === item.href || path.startsWith(item.href + '/')) {
                item.current = true
            }
        })
    }

    /**
     * Set the active top-level navigation item.
     * @param {{name:string}} item
     */
    const updateCurrent = (item) => {
        navigation.value.forEach((navItem) => {
            navItem.current = navItem.name === item.name
        })
    }

    const currentTopTitle = computed(() => {
        const path = currentPath.value.split('?')[0]

        for (const item of navigation.value) {
            if (item.children) {
                for (const child of item.children) {
                    if (path === child.href || path.startsWith(child.href + '/')) {
                        return child.name
                    }
                }
            }
        }

        for (const item of navigation.value) {
            if (item.href === '/') continue
            if (path === item.href || path.startsWith(item.href + '/')) {
                return item.name
            }
        }

        for (const list of lists) {
            if (path === list.href || path.startsWith(list.href + '/')) {
                return list.name
            }
        }

        return 'Dashboard'
    })

    initializeNavState()
    watch(() => page?.url, () => initializeNavState())

    return {
        authUser,
        isAdmin,
        initials,
        userRoles,
        companyLogo,
        companyName,
        canSeeUsers,
        canSeeUserRoles,
        canManageStandardEmails,
        canManageStandardAttachments,
        isTechnischBeheer,
        currentPath,
        isCompanyCurrent,
        navigation,
        filteredNavigation,
        filteredLists,
        visibleChildren,
        toggleSection,
        updateCurrent,
        currentTopTitle,
        rowStateClass,
        bottomLinkClass: BOTTOM_LINK_CLASS,
        supportMailto: SUPPORT_MAILTO,
    }
}
