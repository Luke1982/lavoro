import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { segmentsFromBands } from '@/Composables/usePlannerGaps'

// The same cases PHP runs, from the same file. There are two implementations of
// the gap maths because the planner recalculates while you drag and so has to be
// here, while the assistant answers from the server and so has to be there. That
// is a reasonable split right up until the two quietly stop agreeing about
// whether someone is free — at which point a planner gets contradicted by the
// assistant and stops trusting both. This is what makes that a red test.
//
// See tests/fixtures/day-segments.json for why the fixture stops at the segment
// arithmetic and does not try to describe events or unavailability records.

const here = dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(
    readFileSync(join(here, '../../../../tests/fixtures/day-segments.json'), 'utf8')
)

/** The fixture is snake_case so PHP reads it without translating; this side does. */
function toBands(bands) {
    return bands.map((band) => ({
        startMin: band.start_min,
        endMin: band.end_min,
        label: band.label ?? null,
    }))
}

function segmentsFromCase(testCase) {
    return segmentsFromBands({
        busy: toBands(testCase.busy),
        blocked: toBands(testCase.blocked),
        dayStartHour: testCase.work_start_hour,
        dayEndHour: testCase.work_end_hour,
        minSegmentMinutes: fixture.min_segment_minutes,
    })
}

function shapeOf(segments) {
    return segments.map((segment) => {
        const shape = {
            kind: segment.kind,
            start_min: segment.startMin,
            end_min: segment.endMin,
        }
        if (segment.label != null) shape.label = segment.label
        return shape
    })
}

describe('day segments agree with the PHP implementation', () => {
    it('reads the shared fixture', () => {
        expect(fixture.cases.length).toBeGreaterThan(10)
    })

    for (const testCase of fixture.cases) {
        it(testCase.name, () => {
            expect(shapeOf(segmentsFromCase(testCase))).toEqual(testCase.expect)
        })
    }
})
