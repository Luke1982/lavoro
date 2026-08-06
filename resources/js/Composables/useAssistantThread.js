import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * The conversation itself, held above whatever is showing it.
 *
 * The panel used to own all of this, which was fine while it was welded into a
 * dialog that never unmounted. It is not fine now: the dialog throws its content
 * away when it closes, so a thread would die every time somebody pressed escape
 * to look at the screen behind it. And a sidebar showing the same assistant
 * should be the same conversation, not a second one that knows nothing.
 *
 * So there is one thread per browser tab, and the panel is a view on it.
 */

/** crypto.randomUUID is not there over plain http on a phone; this is the fallback. */
export function newConversationId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
    })
}

/** Which chapter is showing, by the index the chapter component counts in. */
export const CHAT = 0
export const HISTORY = 1
export const PROMPTS = 2

const thread = {
    chapter: ref(CHAT),
    question: ref(''),
    exchanges: ref([]),
    asking: ref(false),

    /**
     * The turns these belong to. One id for as long as the conversation lasts, so
     * the stored turns can be read back together instead of as loose questions.
     */
    conversation: ref(newConversationId()),

    pending_images: ref([]),
    /** Files waiting to go with the next question, as { name, data }. */
    pending_files: ref([]),
    attachment_error: ref(''),
    photos_sent: ref(0),
    looked_at_photos: ref(false),
    asking_about_photos: ref(false),

    reporting: ref(false),
    reported: ref(''),
    asking_reason: ref(false),
    report_reason: ref(''),

    /**
     * Questions asked before, newest first, for stepping back through with the
     * arrows the way a shell does. Loaded when the box first opens rather than per
     * question: it is only ever read to fill the input.
     */
    asked_before: ref([]),
    recalled: ref(-1),
    /** What was being typed before stepping back through those earlier questions. */
    draft: ref(''),

    earlier: ref([]),
    loading_history: ref(false),
    history_error: ref(''),

    prompts: ref([]),
    all_prompts: ref([]),
    editing: ref(null),
    prompt_error: ref(''),
}

/** Puts the thread down and starts a fresh one. */
export function startOver() {
    thread.exchanges.value = []
    thread.question.value = ''
    thread.draft.value = ''
    thread.recalled.value = -1
    thread.conversation.value = newConversationId()
    thread.pending_images.value = []
    thread.pending_files.value = []
    thread.attachment_error.value = ''
    thread.photos_sent.value = 0
    thread.looked_at_photos.value = false
    thread.reported.value = ''
    thread.report_reason.value = ''
    thread.asking_reason.value = false
    thread.editing.value = null
    thread.chapter.value = CHAT
}

/**
 * The conversation belongs to the page it was held on. Carrying it to the next
 * one would leave "deze werkbon" pointing at the previous screen while the
 * assistant is told it is on this one.
 *
 * Listened for here rather than in the panel, because the panel is not always
 * mounted — with the box closed, a navigation would otherwise go unnoticed and
 * the old thread would reappear on the new page.
 */
router.on('navigate', () => {
    startOver()
    thread.asking_about_photos.value = false
})

export function useAssistantThread() {
    return thread
}
