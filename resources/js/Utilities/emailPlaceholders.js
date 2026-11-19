/**
 * How a placeholder sits in the HTML of a standard e-mail: its token as text,
 * in a span the editor recognises and shows as a grey block. The server fills
 * the token in; the span only matters to the editor.
 */
export const PLACEHOLDER_CLASS = 'standard-email-placeholder'

export function placeholderHtml(token) {
    return `<span class="${PLACEHOLDER_CLASS}" data-id="${token}">${token}</span>`
}
