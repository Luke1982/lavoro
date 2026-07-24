import { computed, ref } from 'vue';

const MEGABYTE = 1024 * 1024;

/**
 * Processes picked files one at a time and posts them in small chunks.
 *
 * Nothing is ever read into memory: the browser streams each File straight off
 * disk into the request body, so a queue of 20MB documents costs no more than a
 * queue of empty ones. Callers that do need the bytes (image downscaling) pass a
 * `prepare` hook; its output replaces the file for that upload and is dropped
 * again the moment the chunk lands.
 *
 * Preparing forty files in a tight loop starves the browser of the gaps it needs
 * to paint, handle taps and reclaim memory, so every file is followed by a
 * macrotask yield. Chunking the posts keeps each request under PHP's
 * max_file_uploads — which silently drops anything beyond the twentieth file —
 * and under post_max_size, which rejects the whole request.
 *
 * maxChunkBytes defaults below PHP's own stock post_max_size of 8M rather than
 * to whatever any one server allows, so the queue is safe before anyone has
 * tuned php.ini. Raise it per caller when the target server is known.
 */
export function useUploadQueue({
    chunkSize = 5,
    maxChunkBytes = 6 * MEGABYTE,
    prepare = null,
    prepareFailureMessage = 'Voorbereiden is mislukt',
    uploadChunk,
    onDrained = null,
}) {
    const items = ref([]);
    const isActive = ref(false);

    let disposed = false;
    let uploaded_during_run = false;
    let last_id = 0;

    const total = computed(() => items.value.length);
    const completed = computed(() => items.value.filter((item) => item.status === 'done').length);
    const failed = computed(() => items.value.filter((item) => item.status === 'failed').length);
    const hasFailures = computed(() => failed.value > 0);

    const totalBytes = computed(() => items.value.reduce((sum, item) => sum + item.size, 0));
    const loadedBytes = computed(() => items.value.reduce((sum, item) => sum + item.loaded, 0));

    /**
     * Bytes are the honest measure when a queue mixes a 12MB scan with a 40KB
     * note; the file count only lines up with progress when every file is the
     * same weight. Falls back to the count when no size is known.
     *
     * The weight is the file as picked and never changes afterwards. Re-weighing
     * an item once `prepare` has shrunk it would shrink the denominator as the
     * run goes on, which leaves the bar crawling near zero and then leaping to
     * full at the end.
     */
    const percentage = computed(() => {
        if (totalBytes.value > 0) {
            return Math.min(100, Math.round((loadedBytes.value / totalBytes.value) * 100));
        }

        return total.value === 0 ? 0 : Math.round((completed.value / total.value) * 100);
    });

    const yieldToBrowser = () => new Promise((resolve) => setTimeout(resolve, 0));

    function releasePreview(item) {
        if (item.previewUrl) {
            URL.revokeObjectURL(item.previewUrl);
            item.previewUrl = null;
        }
    }

    function failureMessage(error) {
        const data = error?.response?.data;
        const first_field = data?.errors ? Object.values(data.errors)[0] : null;

        if (Array.isArray(first_field) && first_field.length > 0) {
            return first_field[0];
        }

        return data?.message || 'Uploaden is mislukt';
    }

    function add(files, { staged = false } = {}) {
        // A finished run stays on screen at full so the user gets a completed
        // state to read; the next selection is what clears it.
        if (!isActive.value) {
            items.value = items.value.filter((item) => item.status !== 'done');
        }

        const added = files.map((file) => ({
            id: ++last_id,
            file,
            name: file.name,
            title: file.name,
            size: file.size || 0,
            loaded: 0,
            status: staged ? 'staged' : 'pending',
            error: null,
            prepared: null,
            previewUrl: staged ? URL.createObjectURL(file) : null,
        }));

        items.value = [...items.value, ...added];

        return added;
    }

    async function flush(batch) {
        batch.forEach((item) => {
            item.status = 'uploading';
        });

        const reportProgress = (fraction) => {
            const clamped = Math.max(0, Math.min(1, fraction));

            batch.forEach((item) => {
                item.loaded = Math.round(item.size * clamped);
            });
        };

        try {
            await uploadChunk(batch, reportProgress);
            uploaded_during_run = true;

            batch.forEach((item) => {
                item.status = 'done';
                item.loaded = item.size;
                // Release both the prepared copy and the handle on the original so
                // a long queue never keeps every picked file alive to the end.
                item.prepared = null;
                item.file = null;
                releasePreview(item);
            });
        } catch (error) {
            const message = failureMessage(error);

            batch.forEach((item) => {
                item.status = 'failed';
                item.error = message;
                item.loaded = 0;
                item.prepared = null;
            });
        }
    }

    async function pump() {
        isActive.value = true;

        try {
            let batch = [];
            let batch_bytes = 0;

            const flushBatch = async () => {
                await flush(batch);
                batch = [];
                batch_bytes = 0;
                await yieldToBrowser();
            };

            while (!disposed) {
                const item = items.value.find((candidate) => candidate.status === 'pending');

                if (!item) {
                    break;
                }

                item.status = 'preparing';

                // What the cap has to protect is the request body, so a chunk is
                // weighed by what `prepare` actually hands over — not by the file
                // as picked, which for a downscaled photo is an order of magnitude
                // heavier and would flush chunks long before they are full.
                let prepared_bytes = 0;

                try {
                    item.prepared = prepare ? await prepare(item.file) : item.file;
                    prepared_bytes = item.prepared.size || 0;
                    item.status = 'ready';
                } catch {
                    item.status = 'failed';
                    item.error = prepareFailureMessage;
                }

                await yieldToBrowser();

                if (item.status === 'ready') {
                    // Weighed before adding rather than after: checking afterwards
                    // lets a chunk overshoot by a whole file, so the request the cap
                    // exists to protect is the one that gets rejected. A single file
                    // over the cap still goes out alone instead of stalling forever.
                    if (batch.length > 0 && batch_bytes + prepared_bytes > maxChunkBytes) {
                        await flushBatch();
                    }

                    batch.push(item);
                    batch_bytes += prepared_bytes;

                    if (batch.length >= chunkSize) {
                        await flushBatch();
                    }
                }
            }

            if (!disposed && batch.length > 0) {
                await flush(batch);
            }
        } finally {
            isActive.value = false;
        }

        if (!disposed && uploaded_during_run) {
            uploaded_during_run = false;
            onDrained?.();
        }
    }

    function enqueue(files) {
        add(files);

        if (!isActive.value) {
            pump();
        }
    }

    function stage(files) {
        add(files, { staged: true });
    }

    function start() {
        items.value.forEach((item) => {
            if (item.status === 'staged') {
                item.status = 'pending';
            }
        });

        if (!isActive.value) {
            pump();
        }
    }

    function retryFailed() {
        items.value.forEach((item) => {
            if (item.status === 'failed') {
                item.status = 'pending';
                item.error = null;
            }
        });

        if (!isActive.value) {
            pump();
        }
    }

    function remove(id) {
        const item = items.value.find((candidate) => candidate.id === id);

        if (item) {
            releasePreview(item);
        }

        items.value = items.value.filter((candidate) => candidate.id !== id);
    }

    function clearFinished() {
        items.value = items.value.filter((item) => item.status !== 'done');
    }

    function dispose() {
        disposed = true;
        items.value.forEach(releasePreview);
    }

    return {
        items,
        isActive,
        total,
        completed,
        failed,
        hasFailures,
        totalBytes,
        loadedBytes,
        percentage,
        enqueue,
        stage,
        start,
        retryFailed,
        remove,
        clearFinished,
        dispose,
    };
}
