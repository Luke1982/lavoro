/**
 * Puts a reason after a heading when there is one: "Uploaden is mislukt: geen
 * verbinding met de server".
 */
export function withReason(heading, reason) {
    return reason ? heading + ': ' + reason : heading;
}

/**
 * What to tell someone whose request failed: the first validation message or the
 * server's own message when it sent one, otherwise what kind of failure it was.
 */
export function requestFailureMessage(error, heading) {
    const data = error?.response?.data;
    const first_field = data?.errors ? Object.values(data.errors)[0] : null;

    if (Array.isArray(first_field) && first_field.length > 0) {
        return first_field[0];
    }

    if (data?.message) {
        return data.message;
    }

    if (error?.response) {
        return heading + ' (HTTP ' + error.response.status + ')';
    }

    return withReason(heading, error?.code === 'ERR_NETWORK' ? 'geen verbinding met de server' : error?.message);
}
