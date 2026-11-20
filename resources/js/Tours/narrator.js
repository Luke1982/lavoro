import { spokenSeconds } from './catalog'

/**
 * A recorded step is played from a moment before its first word to a moment
 * after its last, both inside the silence the audio command leaves between steps.
 */
const LEAD_IN = 0.06
const RUN_OUT = 0.12

/**
 * The voice of the rondleiding, and the clock the captions run on.
 *
 * Two ways to speak: the recorded chapter (one file, with the second each step
 * starts), or silence paced like somebody reading aloud. The browser's own voice
 * is never used: it sounds like a sat-nav and ruins the rondleiding. Either way,
 * every line reports how far along it is and when it is done, so the captions
 * and the step after it never have to know which one it was.
 *
 * A recording is only used when it says exactly what the storyboard says. A
 * line rewritten after the audio was made would otherwise be read out in its
 * old words under the new captions.
 */
export function createNarrator() {
    let audio = null
    let objectUrl = null
    let cues = null
    let line = null

    /**
     * Called from inside a click. iOS lets an audio element play later only if it
     * played once while somebody was touching the screen, so the one element the
     * whole rondleiding uses is created and started right here.
     */
    function unlock() {
        if (!audio && typeof Audio !== 'undefined') {
            audio = new Audio()
            audio.preload = 'auto'
        }
        if (audio) {
            audio.src = silentWav()
            audio.play().catch(() => {})
        }
    }

    /**
     * A recording is fetched whole and played from memory: jumping to the second
     * a step starts needs a server that answers range requests, and not every
     * server in front of this application does.
     */
    async function load(tourId, chapter, isRecorded) {
        cues = null
        if (!isRecorded) return
        try {
            const response = await fetch('/tours/' + tourId + '/' + chapter.id + '.json', {
                cache: 'no-cache',
                headers: { Accept: 'application/json' },
            })
            if (!response.ok) return
            const recording = await response.json()
            if (recording.version !== chapter.version || !Array.isArray(recording.steps)) return
            const byText = new Map(recording.steps.map((step) => [step.text, step]))
            if (chapter.steps.some((step) => !byText.has(step.say))) return
            const sound = await fetch('/tours/' + tourId + '/' + recording.audio, { cache: 'no-cache' })
            if (!sound.ok) return
            const blob = await sound.blob()
            if (!audio) {
                audio = new Audio()
                audio.preload = 'auto'
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl)
            objectUrl = URL.createObjectURL(blob)
            audio.src = objectUrl
            audio.load()
            cues = byText
        } catch {
            cues = null
        }
    }

    function mode(muted) {
        if (muted) return 'timer'
        if (cues && audio) return 'audio'

        return 'timer'
    }

    /**
     * Says one line. `onProgress` gets a fraction from 0 to 1, `onEnd` fires once.
     */
    function say(text, { muted = false, onProgress = () => {}, onEnd = () => {} } = {}) {
        stop()
        line = {
            text,
            onProgress,
            onEnd,
            duration: spokenSeconds(text) * 1000,
            elapsed: 0,
            startedAt: null,
            paused: false,
            ended: false,
            frame: null,
            mode: mode(muted),
        }
        start(line, 0)
    }

    function start(current, fromFraction) {
        if (current.mode === 'audio') return startAudio(current)

        return startTimer(current, fromFraction)
    }

    function finish(current) {
        if (current.ended || current !== line) return
        current.ended = true
        cancelAnimationFrame(current.frame)
        current.onProgress(1)
        current.onEnd()
    }

    function startTimer(current, fromFraction) {
        current.mode = 'timer'
        current.elapsed = fromFraction * current.duration
        current.startedAt = performance.now()
        const tick = () => {
            if (current !== line || current.ended || current.paused) return
            const fraction = Math.min(1, (current.elapsed + performance.now() - current.startedAt) / current.duration)
            current.onProgress(fraction)
            if (fraction >= 1) return finish(current)
            current.frame = requestAnimationFrame(tick)
        }
        current.frame = requestAnimationFrame(tick)
    }

    function startAudio(current) {
        const cue = cues.get(current.text)
        audio.currentTime = Math.max(0, cue.start - LEAD_IN)
        const tick = () => {
            if (current !== line || current.ended || current.paused) return
            const position = audio.currentTime
            current.onProgress(Math.max(0, Math.min(1, (position - cue.start) / (cue.end - cue.start))))
            if (position >= cue.end + RUN_OUT || audio.ended) {
                audio.pause()

                return finish(current)
            }
            current.frame = requestAnimationFrame(tick)
        }
        audio.play().then(() => {
            current.frame = requestAnimationFrame(tick)
            setTimeout(() => {
                if (current !== line || current.ended || current.paused || current.mode !== 'audio') return
                if (audio.currentTime < cue.start + 0.05) {
                    cancelAnimationFrame(current.frame)
                    audio.pause()
                    startTimer(current, 0)
                }
            }, 2000)
        }).catch(() => {
            if (current === line && !current.ended) startTimer(current, 0)
        })
    }

    function pause() {
        if (!line || line.ended || line.paused) return
        line.paused = true
        cancelAnimationFrame(line.frame)
        if (line.mode === 'audio') audio?.pause()
        if (line.mode === 'timer') line.elapsed += performance.now() - line.startedAt
    }

    function resume() {
        if (!line || line.ended || !line.paused) return
        line.paused = false
        if (line.mode === 'audio') {
            const cue = cues.get(line.text)
            const position = audio.currentTime
            const current = line
            audio.play().catch(() => startTimer(current, (position - cue.start) / (cue.end - cue.start)))
            const tick = () => {
                if (current !== line || current.ended || current.paused) return
                current.onProgress(Math.max(0, Math.min(1, (audio.currentTime - cue.start) / (cue.end - cue.start))))
                if (audio.currentTime >= cue.end + RUN_OUT || audio.ended) {
                    audio.pause()

                    return finish(current)
                }
                current.frame = requestAnimationFrame(tick)
            }
            current.frame = requestAnimationFrame(tick)

            return
        }
        startTimer(line, line.elapsed / line.duration)
    }

    /** Muting halfway through a line lets it run out in silence, at the same pace. */
    function setMuted(muted) {
        if (!line || line.ended || !muted || line.mode === 'timer') return
        const current = line
        cancelAnimationFrame(current.frame)
        const cue = cues.get(current.text)
        const fraction = (audio.currentTime - cue.start) / (cue.end - cue.start)
        audio.pause()
        if (current.paused) {
            current.mode = 'timer'
            current.elapsed = fraction * current.duration

            return
        }
        startTimer(current, Math.max(0, Math.min(1, fraction)))
    }

    function stop() {
        if (line) {
            line.ended = true
            cancelAnimationFrame(line.frame)
        }
        line = null
        audio?.pause()
    }

    return { unlock, load, say, pause, resume, setMuted, stop }
}

function silentWav() {
    const samples = 800
    const bytes = new Uint8Array(44 + samples)
    const view = new DataView(bytes.buffer)
    const text = (offset, value) => [...value].forEach((character, index) => view.setUint8(offset + index, character.charCodeAt(0)))
    text(0, 'RIFF')
    view.setUint32(4, 36 + samples, true)
    text(8, 'WAVE')
    text(12, 'fmt ')
    view.setUint32(16, 16, true)
    view.setUint16(20, 1, true)
    view.setUint16(22, 1, true)
    view.setUint32(24, 8000, true)
    view.setUint32(28, 8000, true)
    view.setUint16(32, 1, true)
    view.setUint16(34, 8, true)
    text(36, 'data')
    view.setUint32(40, samples, true)
    bytes.fill(128, 44)

    return 'data:audio/wav;base64,' + btoa(String.fromCharCode(...bytes))
}
