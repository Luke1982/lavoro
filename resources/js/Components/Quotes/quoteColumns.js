/**
 * De kolommen van een offerteregel, op één plek, zodat de kopjes boven de regels,
 * de regels zelf en de materialen eronder precies onder elkaar staan.
 */
export const COLUMNS = {
    quantity: 'w-20',
    unit: 'w-24',
    price: 'w-28',
    vat: 'w-20',
    total: 'w-28',
    actions: 'w-8',
}

/** Wat een soort regel heet en hoe hij eruitziet: het label op de regel en het icoon als er geen foto is. */
export const KINDS = {
    product: { label: 'Product', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
    material: { label: 'Materiaal', badge: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' },
    free: { label: 'Dienst', badge: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' },
    text: { label: 'Tekst', badge: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300' },
    optional: { label: 'Optie', badge: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300' },
    alternative: { label: 'Alternatief', badge: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300' },
}

/** Een stelpost: een bedrag dat achteraf op de werkelijke kosten wordt verrekend. */
export const PROVISIONAL_BADGE = 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'

/** Of de klant deze regel nog kan kiezen: een optie, of een van een paar alternatieven. */
export const isChoosable = (line) => Boolean(line.is_optional || line.choice_group)

/** Een optie of alternatief dat nu niet meetelt. */
export const isLeftOut = (line) => isChoosable(line) && line.is_chosen !== true

/** Een veld dat er rustig uitziet tot je eroverheen gaat of erin typt. */
export const GHOST_FIELD = 'w-full bg-transparent border-0 rounded-md px-1.5 py-1 -mx-1.5 ring-0 hover:ring-1 hover:ring-gray-200 dark:hover:ring-slate-600 focus:ring-2 focus:ring-indigo-600 placeholder:text-gray-400'

/** Een veld voor een bedrag of aantal: altijd een rand, zoals in een formulier, en even hoog als het eurobedragveld ernaast. */
export const BOXED_FIELD = 'h-9 w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 bg-white dark:bg-slate-900 text-sm px-2 py-1.5 focus:ring-2 focus:ring-indigo-600'

/** Een regel in een ⋮-menu; rood voor wat iets weghaalt. */
export const menuItem = (active, danger = false) => [
    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm cursor-pointer',
    danger ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-slate-200',
    active ? 'bg-gray-50 dark:bg-slate-700' : '',
]

/** De opties en alternatieven die nu meetellen in het totaal: waarmee de klant, of wie het akkoord vastlegt, begint. */
export const countedOptions = (lines) => lines.filter(line => isChoosable(line) && line.is_chosen === true).map(line => line.id)

/** Een getalveld zonder de pijltjes van de browser, voor velden die rustig moeten ogen. */
export const NO_SPIN = '[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none'

/** De eenheden om uit te kiezen, met die van de regel zelf erbij als die er niet meer tussen staat. */
export const unitChoices = (units, current) => [...new Set([...units, current].filter(Boolean))]
