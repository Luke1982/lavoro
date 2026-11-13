/**
 * The address of a photo's file. Drawing on a photo keeps its id but gives it a new
 * file, so the file's name goes along: the browser would otherwise keep showing the
 * copy it already has. `original` is the photo as it was taken, without the drawing.
 */
export function imageFileUrl(image, { original = false } = {}) {
    const file_name = (image.path ?? '').split('/').pop();

    return `/files/images/${image.id}${original ? '/original' : ''}?v=${encodeURIComponent(file_name)}`;
}
