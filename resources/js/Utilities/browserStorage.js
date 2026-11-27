function quietly(action) {
    try {
        return action()
    } catch {
        return null
    }
}

/**
 * localStorage voor wat een browser alleen voor het gemak onthoudt.
 *
 * Safari in een privévenster laat localStorage bestaan en gooit bij het
 * schrijven. Wat hier staat is nooit noodzakelijk, dus dat mag mislukken
 * zonder dat iemand een foutmelding ziet: lezen geeft dan null.
 */
export const browserStorage = {
    get: (key) => quietly(() => window.localStorage?.getItem(key)) ?? null,
    set: (key, value) => quietly(() => window.localStorage?.setItem(key, value)),
    forget: (key) => quietly(() => window.localStorage?.removeItem(key)),
}

/**
 * sessionStorage met dezelfde vergevingsgezindheid: wat alleen voor dit
 * tabblad onthouden hoeft te worden, zoals waar iemand in een lijst was.
 */
export const browserSession = {
    get: (key) => quietly(() => window.sessionStorage?.getItem(key)) ?? null,
    set: (key, value) => quietly(() => window.sessionStorage?.setItem(key, value)),
}
