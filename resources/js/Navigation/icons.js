import {
    Bell,
    Box,
    Building2,
    CalendarDays,
    ChevronDown,
    ChevronRight,
    ChevronsLeft,
    CircleHelp,
    Cog,
    ExternalLink,
    FileText,
    FolderKanban,
    Home,
    LogOut,
    Mail,
    Menu,
    Plus,
    Search,
    Settings,
    TriangleAlert,
    User,
    UserCog,
    Users,
    Wrench,
} from '@lucide/vue'

/**
 * The icons the application's own chrome uses, under the names menu.json refers
 * to them by. Imported one at a time so the bundle carries exactly these and not
 * the other five thousand.
 *
 * Deliberately not an extension of ICON_MAP in Utilities: that one is the set a
 * user picks from for a machine or a product, so adding a menu icon there would
 * widen a picker that has nothing to do with the menu. Its fallback is a box,
 * which is a fair guess for a machine and nonsense for a menu row.
 */
export const NAV_ICONS = {
    Bell,
    Box,
    Building2,
    CalendarDays,
    ChevronDown,
    ChevronRight,
    ChevronsLeft,
    CircleHelp,
    Cog,
    ExternalLink,
    FileText,
    FolderKanban,
    Home,
    LogOut,
    Mail,
    Menu,
    Plus,
    Search,
    Settings,
    TriangleAlert,
    User,
    UserCog,
    Users,
    Wrench,
}

/**
 * Null rather than a stand-in icon for an unknown name: a menu row missing its
 * icon is a typo in the data, and a plausible-looking substitute would hide it.
 *
 * @param {string|undefined} name
 * @returns {object|null}
 */
export function navIcon(name) {
    return name ? NAV_ICONS[name] ?? null : null
}
