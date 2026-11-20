/**
 * Een veld bewaren op het moment dat je het verlaat, alleen als het echt anders
 * is dan wat de server heeft. Een leeg veld gaat als null: "niet ingevuld" en
 * niet een lege tekst.
 *
 * Vergeleken als tekst, zodat 6 uit de server en "6" uit een invoerveld als
 * hetzelfde tellen; een lijst of een groep keuzes als JSON.
 *
 * @param form  het useForm-formulier met de velden
 * @param url   waar het heen gaat
 * @param saved wat de server nu heeft, om tegen te vergelijken
 */
export function useSaveField(form, url, saved) {
    const text = (value) => (value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value ?? ''))

    return (name) => {
        if (text(form[name]) === text(saved()[name])) return
        form.transform(data => ({ [name]: data[name] === '' ? null : data[name] }))
            .patch(url(), { preserveScroll: true, preserveState: true })
    }
}
