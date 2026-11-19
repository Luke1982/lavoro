import activiteiten from './storyboards/activiteiten.json'
import contract from './storyboards/contract.json'
import contracten from './storyboards/contracten.json'
import kaart from './storyboards/kaart.json'
import machines from './storyboards/machines.json'
import planner from './storyboards/planner.json'
import project from './storyboards/project.json'
import projecten from './storyboards/projecten.json'
import rooster from './storyboards/rooster.json'
import storing from './storyboards/storing.json'
import storingen from './storyboards/storingen.json'

/**
 * Every rondleiding there is. The storyboards are plain JSON so the server can
 * read the same files: the assistant offers chapters from them and the audio
 * command speaks their lines, and neither may drift from what the screen plays.
 */
export const TOURS = Object.fromEntries([planner, rooster, machines, activiteiten, storingen, storing, projecten, project, contracten, contract, kaart].map((tour) => [tour.id, tour]))

export function tourById(id) {
    return TOURS[id] ?? null
}

/**
 * Whether somebody meets what a chapter or a step asks for. `has` answers for one
 * permission name, so the screen can hand in the same check it uses everywhere.
 * `props` are what the page offers, for what is there only where the company set
 * it up, such as a route planner that needs a routing server.
 */
export function meets(requires, { has, modules = [], device, props = {} }) {
    if (!requires) return true
    if ((requires.props ?? []).some((name) => !props[name])) return false
    if (requires.device && requires.device !== device) return false
    if ((requires.permissions ?? []).some((name) => !has(name))) return false
    if (requires.anyPermissions?.length && !requires.anyPermissions.some((name) => has(name))) return false
    if ((requires.modules ?? []).some((name) => !modules.includes(name))) return false

    return true
}

/**
 * The chapters of a tour this person may see on this device, each with only the
 * steps that apply to them. A chapter left without steps is not offered at all.
 */
export function chaptersFor(tourId, audience) {
    const tour = tourById(tourId)
    if (!tour || !meets(tour.requires, audience)) return []

    return tour.chapters
        .filter((chapter) => meets(chapter.requires, audience))
        .map((chapter) => ({
            ...chapter,
            steps: chapter.steps.filter((step) => meets(step.requires, audience)),
        }))
        .filter((chapter) => chapter.steps.length > 0)
}

export function wordCount(text) {
    return (text ?? '').trim().split(/\s+/).filter(Boolean).length
}

/** How long a line takes to say, in seconds, at an unhurried Dutch pace. */
export function spokenSeconds(text) {
    return Math.max(3.5, wordCount(text) / 2.4)
}

export function chapterSeconds(chapter) {
    return chapter.steps.reduce((total, step) => total + spokenSeconds(step.say) + 1.5, 0)
}

export function durationLabel(seconds) {
    if (seconds < 75) return '± 1 min'

    return '± ' + Math.round(seconds / 60) + ' min'
}
