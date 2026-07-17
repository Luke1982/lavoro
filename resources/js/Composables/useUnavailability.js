import { ref } from "vue";
import dayjs from "@/Utilities/dayjs";
import { MINUTES_PER_DAY, minutesFromTimeString } from "@/Utilities/plannerOverlaps";

// All boundaries here are absolute minutes from midnight, so callers with their
// own viewport offset (the desktop grid starts at dayStartHour) convert once at
// the edge instead of every helper carrying the offset around.

export function unavailabilityMatchesDay(unav, dayIso) {
    if (unav.type === "holiday") {
        const end = unav.end_date ?? unav.date;
        return dayIso >= unav.date && dayIso <= end;
    }
    if (unav.type === "recurring") {
        // dayjs: 0=Sun,1=Mon..6=Sat → convert to 0=Mon..6=Sun
        const dow = (dayjs(dayIso).day() + 6) % 7;
        if (dow !== unav.day_of_week) return false;
        if (unav.repeat === "biweekly" && unav.reference_date) {
            const weeksDiff = Math.abs(
                dayjs(unav.reference_date)
                    .startOf("isoWeek")
                    .diff(dayjs(dayIso).startOf("isoWeek"), "week")
            );
            return weeksDiff % 2 === 0;
        }
        return true;
    }
    return false;
}

export function unavailabilityBandsFor(plannableUsers, userId, dayIso) {
    const user = plannableUsers.find((u) => u.id === userId);
    if (!user) return [];
    return (user.unavailabilities ?? [])
        .filter((unav) => unavailabilityMatchesDay(unav, dayIso))
        .map((unav) => ({
            // A band with no times is an all-day block; treating it as the whole
            // day keeps every consumer on one overlap test.
            startMin: unav.start_time === null ? 0 : minutesFromTimeString(unav.start_time),
            endMin: unav.start_time === null ? MINUTES_PER_DAY : minutesFromTimeString(unav.end_time),
            label: unav.label,
        }));
}

export function isBlockedAt(plannableUsers, userId, dayIso, absStartMin, absEndMin) {
    return unavailabilityBandsFor(plannableUsers, userId, dayIso).some(
        (band) => absStartMin < band.endMin && absEndMin > band.startMin
    );
}

export function blockedUsersAt(plannableUsers, userId, dayIso, absStartMin, absEndMin) {
    const user = plannableUsers.find((u) => u.id === userId);
    if (!user) return [];
    return unavailabilityBandsFor(plannableUsers, userId, dayIso)
        .filter((band) => absStartMin < band.endMin && absEndMin > band.startMin)
        .map((band) => ({ name: user.name, label: band.label }));
}

/**
 * Holds the pending action while the user answers the override warning, so a
 * planner can drop work onto blocked time deliberately rather than by accident.
 */
export function useUnavailabilityOverride() {
    const dialog = ref({ open: false, users: [] });
    let pendingAction = null;

    function request(affectedUsers, actionFn) {
        dialog.value = { open: true, users: affectedUsers };
        pendingAction = actionFn;
    }

    function confirm() {
        dialog.value = { open: false, users: [] };
        const fn = pendingAction;
        pendingAction = null;
        fn?.();
    }

    function cancel() {
        dialog.value = { open: false, users: [] };
        pendingAction = null;
    }

    return { dialog, request, confirm, cancel };
}
