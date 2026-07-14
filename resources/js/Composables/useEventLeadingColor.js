export const DEFAULT_EVENT_COLOR = '#3b82f6'
export const CLOSED_COLOR = '#6b7280'
export const COMPLETED_PATTERN = 'repeating-linear-gradient(-45deg, transparent, transparent 6px, rgba(107,114,128,0.07) 6px, rgba(107,114,128,0.07) 12px)'

export function useEventLeadingColor() {
    function rolesForUser(user_role_ids, user_roles) {
        if (!user_role_ids?.length || !user_roles?.length) return []
        return user_role_ids
            .map(id => user_roles.find(role => role.id === id))
            .filter(Boolean)
    }

    function firstRoleColor(user_role_ids, user_roles) {
        return rolesForUser(user_role_ids, user_roles)[0]?.color || null
    }

    function resolveLeadingColor({ eventColor, roleColor, leadingColor, isClosed = false }) {
        if (isClosed) return CLOSED_COLOR
        const base = eventColor || DEFAULT_EVENT_COLOR
        return leadingColor === 'role' ? (roleColor || base) : base
    }

    return {
        rolesForUser,
        firstRoleColor,
        resolveLeadingColor,
        DEFAULT_EVENT_COLOR,
        CLOSED_COLOR,
        COMPLETED_PATTERN,
    }
}
