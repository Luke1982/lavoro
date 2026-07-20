const dictionary = {
    'Insert a new row before': 'Rij invoegen boven',
    'Insert a new row after': 'Rij invoegen onder',
    'Delete selected rows': 'Geselecteerde rijen verwijderen',
    'Insert a new column before': 'Kolom invoegen links',
    'Insert a new column after': 'Kolom invoegen rechts',
    'Delete selected columns': 'Geselecteerde kolommen verwijderen',
    'Rename this column': 'Deze kolom hernoemen',
    'Column name': 'Kolomnaam',
    'Order ascending': 'Oplopend sorteren',
    'Order descending': 'Aflopend sorteren',
    'Comments': 'Opmerkingen',
    'Add comments': 'Opmerking toevoegen',
    'Edit comments': 'Opmerking bewerken',
    'Clear comments': 'Opmerkingen wissen',
    'Copy': 'Kopiëren',
    'Paste': 'Plakken',
    'Save as': 'Opslaan als',
    'About': 'Over',
    'Search': 'Zoeken',
    'Merge the selected cells': 'Geselecteerde cellen samenvoegen',
    'No cells selected': 'Geen cellen geselecteerd',
    'No records found': 'Geen resultaten gevonden',
    'entries': 'regels',
    'Show ': 'Toon ',
    'Showing page {0} of {1} entries': 'Pagina {0} van {1} regels',
    'Toggle Fullscreen': 'Volledig scherm aan/uit',
    'Are you sure to delete the selected rows?': 'Weet je zeker dat je de geselecteerde rijen wilt verwijderen?',
    'Are you sure to delete the selected columns?': 'Weet je zeker dat je de geselecteerde kolommen wilt verwijderen?',
    'The merged cells will retain the value of the top-left cell only. Are you sure?': 'Bij samengevoegde cellen blijft alleen de waarde linksboven behouden. Weet je het zeker?',
    'This action will clear your search results. Are you sure?': 'Deze actie wist je zoekresultaten. Weet je het zeker?',
    'This action will destroy any existing merged cells. Are you sure?': 'Deze actie heft bestaande samengevoegde cellen op. Weet je het zeker?',
    'There is a conflict with another merged cell': 'Er is een conflict met een andere samengevoegde cel',
    'close': 'sluiten',
    'Menu': 'Menu',
}

/**
 * Only icon items render a title attribute in jsuites; select-type items
 * (lettertype, tekstgrootte, uitlijning, randen) ignore the tooltip.
 */
const tooltips_by_icon = {
    undo: 'Ongedaan maken',
    redo: 'Opnieuw uitvoeren',
    save: 'Downloaden als CSV',
    format_bold: 'Vet',
    format_color_text: 'Tekstkleur',
    format_color_fill: 'Achtergrondkleur',
    web: 'Geselecteerde cellen samenvoegen',
    fullscreen: 'Volledig scherm aan/uit',
}

export function spreadsheetDictionaryNl() {
    return dictionary
}

export function translateToolbarNl(default_toolbar) {
    default_toolbar.items.forEach((item) => {
        if (tooltips_by_icon[item.content]) {
            item.tooltip = tooltips_by_icon[item.content]
        }
    })

    return default_toolbar
}
