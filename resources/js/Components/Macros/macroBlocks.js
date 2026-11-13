/**
 * The kinds of block a macro is built from, as the editor shows them: their name,
 * colour, starting settings and the short line on a folded card. What a block
 * does is the server's (app/Domain/Macros/Steps); this is only how it looks.
 */

export const isAsked = value => value !== null && typeof value === 'object' && 'ask' in value

/** How an asked setting reads on a folded block, as it reads in the macro's sentences. */
const asked = prompt => `gevraagd ("${prompt}")`

const newId = () => Math.random().toString(36).slice(2, 10)

export const BLOCKS = {
    add_task: {
        label: 'Taken toevoegen',
        tone: 'border-blue-400 bg-blue-50 text-blue-950 dark:border-blue-700 dark:bg-blue-950/50 dark:text-blue-100',
        create: () => ({ service_order_task_id: null, product_id: null, quantity: 1, checks_per_unit: false, extra_check_ids: [], extra_checklist_ids: [], extra_titles: [] }),
        summary: (step, library) => [
            library.taskTitle(step.service_order_task_id) ?? 'kies een taak',
            step.product_id ? library.productName(step.product_id) : null,
            'aantal ' + (isAsked(step.quantity) ? asked(step.quantity.ask) : step.quantity),
        ].filter(Boolean).join(' · '),
    },
    question: {
        label: 'Vraag ja/nee',
        tone: 'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-700 dark:bg-amber-950/50 dark:text-amber-100',
        create: () => ({ question: '', yes: [], no: [] }),
        summary: step => step.question || 'typ de vraag',
    },
    upload_document: {
        label: 'Document uploaden',
        tone: 'border-violet-400 bg-violet-50 text-violet-950 dark:border-violet-700 dark:bg-violet-950/50 dark:text-violet-100',
        create: () => ({ prompt: 'Upload het document', document_category_id: null, internal: false }),
        summary: (step, library) => [
            library.categoryName(step.document_category_id),
            'bestand ' + asked(step.prompt),
        ].filter(Boolean).join(' · '),
    },
    add_remark: {
        label: 'Opmerking plaatsen',
        tone: 'border-slate-400 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100',
        create: () => ({ text: '', internal: false }),
        summary: step => (step.internal ? 'intern · ' : '') + (isAsked(step.text) ? asked(step.text.ask) : `"${step.text || '…'}"`),
    },
    add_service_jobs: {
        label: 'Keuringen toevoegen',
        tone: 'border-green-400 bg-green-50 text-green-950 dark:border-green-700 dark:bg-green-950/50 dark:text-green-100',
        create: () => ({ location: 'all' }),
        summary: step => (isAsked(step.location) ? 'locatie ' + asked(step.location.ask) : 'alle machines van de klant'),
    },
}

export function newStep(type) {
    return { id: newId(), type, ...BLOCKS[type].create() }
}
