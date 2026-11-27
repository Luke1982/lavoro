import { isLeftOut } from '@/Components/Quotes/quoteColumns'

/**
 * Made-up records the rondleidingen type in and show, and never save. A brand
 * and model nobody sells, so no picker ever finds a real one to offer instead
 * of the "Nieuw …" row the step is about. The storyboards say these words, and
 * their check holds them to it.
 */
export const NEW_PRODUCT = Object.freeze({ brand: 'Polarix', model: 'PX-12 Hybride' })

/** The id a made-up record carries on the screen: never a number, so never a real one. */
const EXAMPLE_ID = 'rondleiding'

/**
 * Shows a made-up record picked in a picker, the way making it would leave the
 * form: `record` added to the picker's `options` and chosen in `form[field]`.
 * Returns how to take it away again.
 */
export function pretendPicked(options, form, field, record) {
    const previous = form[field]
    options.value = [...options.value, { ...record, id: EXAMPLE_ID }]
    form[field] = EXAMPLE_ID

    return () => {
        form[field] = previous
        options.value = options.value.filter(option => option.id !== EXAMPLE_ID)
    }
}

/**
 * A task as the werkbon shows one, for a werkbon that has none: two checks from a
 * checklist and a loose one. Its ids are negative, so the task list keeps it
 * read-only and no tick can reach the server.
 */
export function exampleTaskInstance(service_order_id) {
    const check = (id, title, checklist_title, is_required) => ({
        id, service_order_task_instance_id: -1, title, checklist_title, is_required, is_extra: false, is_done: false,
        unit_index: 0, asset_id: null, sort_order: -id, checked_at: null, checked_by: null,
    })

    return {
        id: -1, service_order_id, service_order_task_id: null, product_id: null, quantity: 1, checks_per_unit: false,
        title: 'Airco onderhouden (voorbeeld)', description: 'Binnen- en buitendeel reinigen en controleren',
        is_complete: false, completed_at: null, completed_by: null, signed_by: null, signature_base64: null, signed_at: null,
        is_cancelled: false, cancellation_reason: null, serial_slots: [], service_order_task: null, user_roles: [],
        materials: [], freeform_materials: [], product: null, productables: [], assets: [],
        checks: [
            check(-1, 'Filters gereinigd', 'Onderhoud', true),
            check(-2, 'Lekdichtheid getest', 'Onderhoud', true),
            check(-3, 'Foto van de buitenunit', null, false),
        ],
    }
}

/**
 * The example task further along, as the buttons behind a task change with it:
 * voltooid with every check ticked, and then also signed for by the customer.
 */
export function exampleTaskCompleted(task) {
    const now = new Date().toISOString()

    return { ...task, is_complete: true, completed_at: now, checks: task.checks.map(check => ({ ...check, is_done: true, checked_at: now })) }
}

export function exampleTaskSigned(task) {
    return { ...exampleTaskCompleted(task), signed_by: 'J. de Vries (voorbeeld)', signed_at: new Date().toISOString() }
}

/**
 * A macro for when there is none yet, laid out the way the server sends one to
 * the werkbon and to the list: a task that asks how many, then a question with
 * a lane for yes and one for no. Its id is negative, so it can be opened and
 * answered but never run, opened for editing or saved.
 */
export function exampleMacro() {
    return {
        id: -1,
        title: 'Airco plaatsen (voorbeeld)',
        description: 'Voorbeeld uit de rondleiding',
        readable: [
            '1. Taak toevoegen: Airco plaatsen, aantal gevraagd ("Hoeveel binnendelen?").',
            '2. Vraag: "Is er een offerte?"',
            '   Als ja:',
            '   2.1. Document uploaden: gevraagd ("Upload de getekende offerte").',
            '   Als nee:',
            '   2.1. Opmerking plaatsen: "Offerte nog opvragen bij de klant".',
            '3. Keuringen toevoegen: alle machines.',
        ],
        steps: [
            { id: 'voorbeeld-taak', type: 'add_task', asks: { quantity: { prompt: 'Hoeveel binnendelen?', kind: 'number' } } },
            {
                id: 'voorbeeld-vraag',
                type: 'question',
                question: 'Is er een offerte?',
                asks: { answer: { prompt: 'Is er een offerte?', kind: 'yes_no' } },
                yes: [{ id: 'voorbeeld-document', type: 'upload_document', asks: { file: { prompt: 'Upload de getekende offerte', kind: 'file' } } }],
                no: [{ id: 'voorbeeld-opmerking', type: 'add_remark', asks: {} }],
            },
            { id: 'voorbeeld-keuringen', type: 'add_service_jobs', asks: {} },
        ],
    }
}

/**
 * A quote's lines for a quote that has none yet, laid out the way the server
 * sends them: a heading, an airco with the materials that came along with it,
 * the hours to fit it, an amount that is not fixed yet and an option to add.
 * Their ids are negative, so a line can be changed on the screen and nothing
 * reaches the server. With them come their totals, counted from the lines the
 * way the server counts them: what is left out apart, VAT once over the sum,
 * and every line at the one rate the example uses.
 */
export function exampleQuoteLines() {
    const RATE = 21
    const line = (id, fields) => ({
        id, parent_id: null, kind: 'free', details: null, location_note: null, unit: 'stuk', cost_price: null,
        vat_percent: RATE, discount_percent: null, is_optional: false, is_chosen: null, is_provisional: false,
        choice_group: null, image: null, children: [], updated_at: 'voorbeeld',
        ...fields,
        net: fields.quantity * fields.unit_price,
    })

    const lines = [
        line(-1, { kind: 'text', description: 'Woonkamer', details: '<p>Een airco boven de bank, met de buitenunit aan de achtergevel.</p>', quantity: 0, unit: null, unit_price: 0 }),
        line(-2, {
            kind: 'product', description: 'Airco binnendeel 2,5 kW', details: 'Stil en zuinig, met wifi-bediening',
            location_note: 'woonkamer', quantity: 1, unit_price: 1250, cost_price: 800,
            children: [
                line(-3, { kind: 'material', parent_id: -2, description: 'Muurbeugel', quantity: 1, unit_price: 45, cost_price: 20 }),
                line(-4, { kind: 'material', parent_id: -2, description: 'Koelleiding', quantity: 4, unit: 'meter', unit_price: 22.5, cost_price: 10 }),
            ],
        }),
        line(-5, { description: 'Montage', quantity: 6, unit: 'uur', unit_price: 65, cost_price: 35 }),
        line(-6, { description: 'Condensafvoer aanleggen', quantity: 1, unit_price: 150, cost_price: 100, is_provisional: true }),
        line(-7, { kind: 'material', description: 'Condenspomp', quantity: 1, unit_price: 120, cost_price: 70, is_optional: true, is_chosen: false }),
    ]

    const sum = (rows, amount) => Math.round(rows.reduce((total, row) => total + amount(row), 0) * 100) / 100
    const counted = lines.filter(row => !isLeftOut(row)).flatMap(row => [row, ...row.children])
    const net = sum(counted, row => row.net)
    const cost = sum(counted, row => row.quantity * (row.cost_price ?? 0))
    const vat = Math.round(net * RATE) / 100

    return {
        lines,
        totals: {
            net, vat: [{ rate: RATE, net, vat }], vat_total: vat, gross: Math.round((net + vat) * 100) / 100,
            optional_net: sum(lines.filter(isLeftOut), row => row.net),
            provisional_net: sum(counted.filter(row => row.is_provisional), row => row.net),
            cost, margin: Math.round((net - cost) * 100) / 100,
        },
    }
}

/**
 * Where the monteurs stand on the planner's maps while the rondleiding shows
 * them, for a company where nobody shares a location: made-up spots around
 * Utrecht, each a few minutes old, and every name marked as an example.
 */
const EXAMPLE_LOCATIONS = [
    { lat: 52.0907, lng: 5.1214, heading: 80 },
    { lat: 52.0649, lng: 5.0938, heading: null },
    { lat: 52.1093, lng: 5.1810, heading: 210 },
]

export function examplePings(users) {
    const recorded_at = new Date(Date.now() - 4 * 60 * 1000).toISOString()

    return Object.fromEntries(users.slice(0, EXAMPLE_LOCATIONS.length).map((user, index) => [user.id, {
        ...EXAMPLE_LOCATIONS[index],
        recorded_at,
        user: { id: user.id, name: user.name + ' (voorbeeld)', avatar: user.avatar ?? null },
    }]))
}

/**
 * A route put in a monteur's day, laid out the way the planner gets one from the
 * server. Its link opens a real route in Google Maps along made-up stops, and it
 * can never be taken out of the planning: there is nothing to take out.
 */
export function exampleDayRoute(user, date) {
    const stops = ['Vredenburg 40, Utrecht', 'Kanaalweg 17, Utrecht', 'Rijnlaan 12, Utrecht']
    const link = 'https://www.google.com/maps/dir/?api=1'
        + '&origin=' + encodeURIComponent('Utrecht Centraal')
        + '&destination=' + encodeURIComponent('Utrecht Centraal')
        + '&waypoints=' + encodeURIComponent(stops.join('|'))
        + '&travelmode=driving'

    return {
        id: EXAMPLE_ID,
        user_id: user.id,
        user_name: user.name,
        date,
        day_label: date,
        stops: stops.map((address) => ({ address })),
        finish: '15:30',
        links: [link],
        summary: stops.length + ' adressen, terug om 15:30 (voorbeeld)',
        can_remove: true,
        is_example: true,
    }
}
