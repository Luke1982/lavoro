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
