const CANCELLED_STATUS = 'Geannuleerd'

/**
 * Resolves the time range a card actually occupies in a lane, honouring the
 * per-user diverging times that override the event's own start/end.
 */
export function effectiveMinutesFor(event, userId, dayStartHour) {
    const diverging = event.executing_users?.find(u => u.id === userId && u.has_diverging_times) ?? null

    return {
        startMin: diverging?.diverging_start
            ? minutesFromTimeString(diverging.diverging_start, dayStartHour)
            : minutesFromDate(event.start, dayStartHour),
        endMin: diverging?.diverging_end
            ? minutesFromTimeString(diverging.diverging_end, dayStartHour)
            : minutesFromDate(event.end, dayStartHour),
    }
}

export function isCancelledForUser(event, userId) {
    return event.executing_users?.find(u => u.id === userId)?.completion_status === CANCELLED_STATUS
}

function minutesFromDate(date, dayStartHour) {
    return date.getHours() * 60 + date.getMinutes() - dayStartHour * 60
}

function minutesFromTimeString(time, dayStartHour) {
    const [h, m] = time.slice(0, 5).split(':').map(Number)
    return h * 60 + m - dayStartHour * 60
}

export function formatDurationLabel(minutes) {
    const h = Math.floor(minutes / 60)
    const m = Math.round(minutes % 60)
    if (!h) return `${m}m`
    if (!m) return `${h}u`
    return `${h}u ${m}m`
}

/**
 * Blends leading colours into a single tint, weighting every colour equally.
 */
export function blendColors(colors) {
    const unique = [...new Set(colors)]
    return unique.reduce((blend, color, i) => {
        if (i === 0) return color
        return `color-mix(in srgb, ${blend} ${Math.round((i / (i + 1)) * 100)}%, ${color})`
    })
}

function subtractRegions(range, regions) {
    let pieces = [range]
    for (const region of regions) {
        const remaining = []
        for (const piece of pieces) {
            if (region.endMin <= piece.startMin || region.startMin >= piece.endMin) {
                remaining.push(piece)
                continue
            }
            if (region.startMin > piece.startMin) {
                remaining.push({ startMin: piece.startMin, endMin: region.startMin })
            }
            if (region.endMin < piece.endMin) {
                remaining.push({ startMin: region.endMin, endMin: piece.endMin })
            }
        }
        pieces = remaining
    }
    return pieces
}

/**
 * Merges every stretch of the lane covered by two or more cards into maximal
 * contested regions.
 */
function contestedRegions(laneEvents) {
    const bounds = [...new Set(laneEvents.flatMap(ev => [ev.startMin, ev.endMin]))].sort((a, b) => a - b)
    const regions = []

    for (let i = 0; i < bounds.length - 1; i++) {
        const startMin = bounds[i]
        const endMin = bounds[i + 1]
        const covering = laneEvents.filter(ev => ev.startMin < endMin && ev.endMin > startMin)
        if (covering.length < 2) continue

        const previous = regions.at(-1)
        if (previous?.endMin === startMin) {
            previous.endMin = endMin
            previous.covering = [...new Set([...previous.covering, ...covering])]
            continue
        }
        regions.push({ startMin, endMin, covering })
    }

    return regions
}

function largestCoincidentGroup(laneEvents) {
    const groups = new Map()
    for (const ev of laneEvents) {
        const key = `${ev.startMin}-${ev.endMin}`
        groups.set(key, (groups.get(key) ?? 0) + 1)
    }
    return Math.max(...groups.values())
}

/**
 * Works out how one lane renders: which cards clip back to their exclusive
 * stretch, and which contested regions need a hatched band.
 *
 * A card clips when subtracting the contested regions leaves exactly one
 * contiguous stretch. Cards left with nothing (identical times) or split in
 * two (one event containing another) keep their true box instead, and the band
 * is drawn over them.
 *
 * `clips` only holds entries for cards the overlap actually moved: a range to
 * clip to, or null to keep the true box. Untouched cards are absent.
 *
 * A stretch shorter than `minClipMinutes` is too narrow to stay readable or
 * grabbable, so those cards keep their true box as well and the band is drawn
 * over them.
 */
export function computeLaneOverlaps(laneEvents, minClipMinutes = 0) {
    const clips = new Map()
    const regions = contestedRegions(laneEvents)

    for (const ev of laneEvents) {
        const pieces = subtractRegions({ startMin: ev.startMin, endMin: ev.endMin }, regions)
        const untouched = pieces.length === 1 && pieces[0].startMin === ev.startMin && pieces[0].endMin === ev.endMin
        if (untouched) continue

        const clippable = pieces.length === 1 && (pieces[0].endMin - pieces[0].startMin) >= minClipMinutes
        clips.set(ev.id, clippable ? pieces[0] : null)
    }

    const bands = regions.map(region => {
        const stackCount = largestCoincidentGroup(region.covering)
        return {
            id: region.covering.map(ev => ev.id).join('-'),
            startMin: region.startMin,
            endMin: region.endMin,
            color: blendColors(region.covering.map(ev => ev.color)),
            durationMin: region.endMin - region.startMin,
            stackCount: stackCount > 1 ? stackCount : 0,
            coversCards: region.covering.some(ev => clips.get(ev.id) === null),
        }
    })

    return { clips, bands }
}
