import dayjs from "@/Utilities/dayjs";
import { unavailabilityBandsFor } from "@/Composables/useUnavailability";
import { MINUTES_PER_DAY, effectiveMinutesFor, subtractRegions } from "@/Utilities/plannerOverlaps";

// Slivers below this are noise rather than plannable room.
const MIN_SEGMENT_MINUTES = 15;

function mergeBands(bands) {
    const sorted = [...bands]
        .filter((band) => band.endMin > band.startMin)
        .sort((a, b) => a.startMin - b.startMin);
    const merged = [];
    for (const band of sorted) {
        const last = merged[merged.length - 1];
        if (last && band.startMin <= last.endMin) {
            last.endMin = Math.max(last.endMin, band.endMin);
            if (!last.label) last.label = band.label;
        } else {
            merged.push({ ...band });
        }
    }
    return merged;
}

function spansMultipleDays(ev) {
    return !dayjs(ev.start).isSame(dayjs(ev.end), "day");
}

/**
 * The slice of `dayIso` an event occupies for one user, in minutes from
 * midnight. An event running across days occupies only the hours it actually
 * covers on this day; diverging times are per-day clock times and so cannot
 * describe a span, which is why they only apply to single-day events.
 */
export function eventMinutesFor(ev, userId, dayIso) {
    if (!spansMultipleDays(ev)) {
        return effectiveMinutesFor(ev, userId, 0);
    }
    const dayStart = dayjs(dayIso).startOf("day");
    return {
        startMin: Math.max(0, dayjs(ev.start).diff(dayStart, "minute")),
        endMin: Math.min(MINUTES_PER_DAY, dayjs(ev.end).diff(dayStart, "minute")),
    };
}

/**
 * Every event overlapping `dayIso`, not merely those starting on it, so a
 * multi-day event still occupies the days it runs through.
 */
export function eventsOnDay(events, userId, dayIso) {
    const dayStart = dayjs(dayIso).startOf("day").toDate();
    const dayEnd = dayjs(dayIso).add(1, "day").startOf("day").toDate();
    return events.filter((ev) => {
        if (userId !== null && !ev.executing_user_ids.includes(userId)) return false;
        return ev.start < dayEnd && ev.end > dayStart;
    });
}

/**
 * Splits one mechanic's workday into what is bookable and what is not.
 * Use daySegmentsAcrossUsers for the room the team as a whole has.
 */
export function daySegmentsFor({
    events,
    plannableUsers,
    userId,
    dayIso,
    dayStartHour,
    dayEndHour,
    minSegmentMinutes = MIN_SEGMENT_MINUTES,
}) {
    const busy = mergeBands(
        eventsOnDay(events, userId, dayIso).map((ev) => eventMinutesFor(ev, userId, dayIso))
    );
    const blocked = mergeBands(unavailabilityBandsFor(plannableUsers, userId, dayIso));

    const workday = { startMin: dayStartHour * 60, endMin: dayEndHour * 60 };
    const segments = [];

    for (const open of subtractRegions(workday, busy)) {
        const overlapping = blocked
            .map((band) => ({
                startMin: Math.max(band.startMin, open.startMin),
                endMin: Math.min(band.endMin, open.endMin),
                label: band.label,
            }))
            .filter((band) => band.endMin > band.startMin);

        for (const band of overlapping) {
            segments.push({ kind: "blocked", ...band });
        }
        for (const free of subtractRegions(open, overlapping)) {
            segments.push({ kind: "free", ...free, label: null });
        }
    }

    return segments
        .filter((segment) => segment.endMin - segment.startMin >= minSegmentMinutes)
        .sort((a, b) => a.startMin - b.startMin);
}

function sameUsers(a, b) {
    return a.length === b.length && a.every((id, i) => id === b[i]);
}

/**
 * The day's room across the whole team, as stretches of "these mechanics are
 * free". Room that only some of them have is still room, so the day is cut at
 * every point somebody's availability changes rather than only where nobody at
 * all is booked.
 *
 * Each segment carries the mechanics free for the whole of it, so a caller can
 * name them. Stretches nobody is free for are left out entirely.
 */
export function daySegmentsAcrossUsers({
    events,
    plannableUsers,
    dayIso,
    dayStartHour,
    dayEndHour,
    minSegmentMinutes = MIN_SEGMENT_MINUTES,
}) {
    const freeByUser = plannableUsers.map((user) => ({
        id: user.id,
        free: daySegmentsFor({
            events,
            plannableUsers,
            userId: user.id,
            dayIso,
            dayStartHour,
            dayEndHour,
            minSegmentMinutes: 0,
        }).filter((segment) => segment.kind === "free"),
    }));

    const bounds = [
        ...new Set(freeByUser.flatMap((u) => u.free.flatMap((s) => [s.startMin, s.endMin]))),
    ].sort((a, b) => a - b);

    const merged = [];
    for (let i = 0; i < bounds.length - 1; i++) {
        const startMin = bounds[i];
        const endMin = bounds[i + 1];
        const userIds = freeByUser
            .filter((u) => u.free.some((s) => s.startMin <= startMin && s.endMin >= endMin))
            .map((u) => u.id);
        if (!userIds.length) continue;

        const previous = merged[merged.length - 1];
        if (previous?.endMin === startMin && sameUsers(previous.userIds, userIds)) {
            previous.endMin = endMin;
            continue;
        }
        merged.push({ kind: "free", startMin, endMin, label: null, userIds });
    }

    return merged.filter((segment) => segment.endMin - segment.startMin >= minSegmentMinutes);
}
