import { describe, it, expect } from 'vitest'
import { daySegmentsFor, eventsOnDay } from '@/Composables/usePlannerGaps'

// These guard a bug class that fails silently: when the gap maths is wrong the
// planner sees a plausible screen that reports a busy mechanic as free, and
// books over them.

const WEDNESDAY = '2026-07-15'
const SATURDAY = '2026-07-18'

const jan = { id: 1, name: 'Jan', unavailabilities: [] }
const piet = { id: 2, name: 'Piet', unavailabilities: [] }

function event(startIso, endIso, userIds = [1]) {
    return {
        start: new Date(startIso),
        end: new Date(endIso),
        executing_user_ids: userIds,
        executing_users: userIds.map(id => ({ id })),
    }
}

function segmentsFor(overrides) {
    return daySegmentsFor({
        plannableUsers: [jan],
        userId: 1,
        dayIso: WEDNESDAY,
        dayStartHour: 7,
        dayEndHour: 18,
        events: [],
        ...overrides,
    })
}

function shapeOf(segments) {
    return segments.map(s => [s.kind, s.startMin, s.endMin])
}

describe('daySegmentsFor', () => {
    it('finds room before, between and after appointments', () => {
        const events = [event(`${WEDNESDAY}T09:00`, `${WEDNESDAY}T11:00`), event(`${WEDNESDAY}T14:00`, `${WEDNESDAY}T16:00`)]
        expect(shapeOf(segmentsFor({ events }))).toEqual([
            ['free', 420, 540],
            ['free', 660, 840],
            ['free', 960, 1080],
        ])
    })

    it('treats a day with no appointments as one full free block', () => {
        expect(segmentsFor({})).toEqual([{ kind: 'free', startMin: 420, endMin: 1080, label: null }])
    })

    it('ignores slivers too short to be worth planning', () => {
        const events = [event(`${WEDNESDAY}T07:00`, `${WEDNESDAY}T17:00`), event(`${WEDNESDAY}T17:10`, `${WEDNESDAY}T18:00`)]
        expect(segmentsFor({ events })).toEqual([])
    })

    it('honours a mechanic diverging times over the appointment own clock', () => {
        const events = [{
            ...event(`${WEDNESDAY}T09:00`, `${WEDNESDAY}T17:00`),
            executing_users: [{ id: 1, has_diverging_times: true, diverging_start: '09:00', diverging_end: '11:00' }],
        }]
        expect(shapeOf(segmentsFor({ events }))).toEqual([
            ['free', 420, 540],
            ['free', 660, 1080],
        ])
    })

    describe('unavailability', () => {
        const withLunch = {
            ...jan,
            unavailabilities: [
                { type: 'recurring', day_of_week: 2, start_time: '12:00', end_time: '13:00', label: 'Lunch' },
            ],
        }

        it('is carved out of the free room and keeps its label', () => {
            const segments = segmentsFor({ plannableUsers: [withLunch] })
            expect(segments.map(s => [s.kind, s.startMin, s.endMin, s.label])).toEqual([
                ['free', 420, 720, null],
                ['blocked', 720, 780, 'Lunch'],
                ['free', 780, 1080, null],
            ])
        })

        it('blocks the whole day when the entry has no times', () => {
            const onHoliday = {
                ...jan,
                unavailabilities: [
                    { type: 'holiday', date: WEDNESDAY, end_date: WEDNESDAY, start_time: null, end_time: null, label: 'Vakantie' },
                ],
            }
            expect(segmentsFor({ plannableUsers: [onHoliday] }))
                .toEqual([{ kind: 'blocked', startMin: 420, endMin: 1080, label: 'Vakantie' }])
        })

        it('is split by an appointment booked inside it', () => {
            const events = [event(`${WEDNESDAY}T12:15`, `${WEDNESDAY}T12:45`)]
            expect(shapeOf(segmentsFor({ plannableUsers: [withLunch], events }))).toEqual([
                ['free', 420, 720],
                ['blocked', 720, 735],
                ['blocked', 765, 780],
                ['free', 780, 1080],
            ])
        })

        it('never applies across all mechanics, since it belongs to a person', () => {
            expect(segmentsFor({ plannableUsers: [withLunch], userId: null }))
                .toEqual([{ kind: 'free', startMin: 420, endMin: 1080, label: null }])
        })
    })

    describe('across all mechanics', () => {
        it('counts only time nobody has booked as free', () => {
            const events = [
                event(`${WEDNESDAY}T08:00`, `${WEDNESDAY}T12:00`, [1]),
                event(`${WEDNESDAY}T11:00`, `${WEDNESDAY}T15:00`, [2]),
            ]
            expect(shapeOf(segmentsFor({ plannableUsers: [jan, piet], userId: null, events }))).toEqual([
                ['free', 420, 480],
                ['free', 900, 1080],
            ])
        })
    })

    // An appointment running across midnight used to be attributed only to the
    // day it started on, leaving every later day looking wide open.
    describe('an appointment running across days', () => {
        const spanning = [event('2026-05-12T09:00', '2026-05-13T16:00')]
        const overDays = { events: spanning, plannableUsers: [jan], userId: 1 }

        it('leaves room before it starts on the first day', () => {
            expect(shapeOf(segmentsFor({ ...overDays, dayIso: '2026-05-12' }))).toEqual([['free', 420, 540]])
        })

        it('leaves room only after it ends on the last day', () => {
            expect(shapeOf(segmentsFor({ ...overDays, dayIso: '2026-05-13' }))).toEqual([['free', 960, 1080]])
        })

        it('leaves no room at all on a day it covers end to end', () => {
            const longer = [event('2026-05-12T09:00', '2026-05-14T16:00')]
            expect(segmentsFor({ ...overDays, events: longer, dayIso: '2026-05-13' })).toEqual([])
        })
    })

    // Mechanics do sometimes work weekends, so a Saturday offers room like any
    // other day; someone who never works one says so via their own recurring
    // unavailability instead.
    it('offers room at the weekend', () => {
        expect(segmentsFor({ dayIso: SATURDAY }))
            .toEqual([{ kind: 'free', startMin: 420, endMin: 1080, label: null }])
    })
})

describe('eventsOnDay', () => {
    it('includes an appointment that merely passes through the day', () => {
        const spanning = event('2026-05-12T09:00', '2026-05-14T16:00')
        expect(eventsOnDay([spanning], 1, '2026-05-13')).toEqual([spanning])
    })

    it('excludes other mechanics work when a mechanic is named', () => {
        const hers = event(`${WEDNESDAY}T09:00`, `${WEDNESDAY}T11:00`, [2])
        expect(eventsOnDay([hers], 1, WEDNESDAY)).toEqual([])
        expect(eventsOnDay([hers], null, WEDNESDAY)).toEqual([hers])
    })
})
