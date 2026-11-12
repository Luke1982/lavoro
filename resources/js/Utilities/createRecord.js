import axios from 'axios'

/**
 * Makes a record through its own endpoint without leaving the page, and hands
 * back what the server made. For whatever is missing from a form that is still
 * half filled in.
 *
 * A refusal about a field comes back in `errors`, one message per field, the
 * shape form.errors has. A refusal that is about no field at all comes back as
 * `message`.
 *
 * @param {string} url
 * @param {object} data
 * @returns {Promise<{record: object|null, errors: Object<string, string>, message: string}>}
 */
export async function createRecord(url, data) {
    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data: record } = await axios.post(url, data)

        return { record, errors: {}, message: '' }
    } catch (error) {
        const { status, data: body } = error.response ?? {}

        if (status === 422 && body?.errors) {
            const errors = Object.fromEntries(
                Object.entries(body.errors).map(([field, messages]) => [field, messages[0]])
            )

            return { record: null, errors, message: '' }
        }

        const message = status === 403
            ? 'Je hebt geen rechten om dit aan te maken.'
            : 'Aanmaken is niet gelukt. Probeer het opnieuw.'

        return { record: null, errors: {}, message }
    }
}
