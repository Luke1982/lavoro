/**
 * Het lijstje met wat er gevraagd wordt, in de brieftekst gehouden terwijl de
 * knoppen erboven aan- en uitgaan.
 *
 * Het lijstje staat in de editor en niet ernaast, omdat de collega het mag
 * herschrijven voordat hij verstuurt. Dat maakt terugvinden het hele probleem:
 * TipTap ontleedt de html naar zijn eigen schema en gooit attributen weg die het
 * niet kent, dus een markering op de <ul> overleeft de eerste ronde door de editor
 * niet. Daarom twee wegen: eerst de markering, en als die weg is het lijstje
 * waarvan de regels overeenkomen met wat wij ooit hebben ingevoegd.
 */

const MARKER = 'data-info-request'

const textOf = (node) => (node.textContent || '').trim().toLowerCase()

function findList(root, known) {
    const marked = root.querySelector(`ul[${MARKER}]`)

    if (marked) {
        return marked
    }

    const wanted = new Set(known.map((label) => label.trim().toLowerCase()))

    return Array.from(root.querySelectorAll('ul')).find((list) => {
        const items = Array.from(list.querySelectorAll('li'))

        return items.length > 0 && items.some((item) => wanted.has(textOf(item)))
    }) || null
}

/**
 * @param {string} html      de huidige brieftekst
 * @param {string[]} labels  wat er nu aangevinkt staat, in de volgorde van de knoppen
 * @param {string[]} known   elk label dat ooit door een knop ingevoegd kan zijn
 * @returns {string} de brieftekst met precies één actueel lijstje erin
 */
export function syncRequestedList(html, labels, known = labels) {
    const document_fragment = new DOMParser().parseFromString(
        `<div id="info-request-root">${html || ''}</div>`,
        'text/html',
    )
    const root = document_fragment.getElementById('info-request-root')
    const list = findList(root, known)

    if (!labels.length) {
        list?.remove()

        return root.innerHTML
    }

    const target = list || root.appendChild(document_fragment.createElement('ul'))
    target.setAttribute(MARKER, '')
    target.innerHTML = ''

    labels.forEach((label) => {
        const item = document_fragment.createElement('li')
        item.textContent = label
        target.appendChild(item)
    })

    return root.innerHTML
}
