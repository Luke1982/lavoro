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
 * Splits the workday into what is bookable and what is not.
 *
 * A null userId means "across every mechanic": busy is the union of everyone's
 * events and nothing is blocked, since unavailability belongs to a person and
 * says nothing about the team.
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
    const blocked =
        userId === null ? [] : mergeBands(unavailabilityBandsFor(plannableUsers, userId, dayIso));

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
