import { ref } from "vue";
import axios from "axios";
import { formatUtcDatetime } from "@/Utilities/Utilities";
import dayjs from "@/Utilities/dayjs";

function mapEvent(ev) {
    const customer_id = ev.service_orders?.[0]?.customer_id ?? null;
    const customer = ev.service_orders?.[0]?.customer ?? null;
    return {
        id: ev.id,
        title: ev.event_type?.name || ev.name || "Afspraak",
        name: ev.name,
        description: ev.description,
        status: ev.status,
        color: ev.event_type?.color || "#3b82f6",
        event_type_id: ev.event_type?.id,
        start: new Date(ev.start),
        end: new Date(ev.end),
        executing_user_ids: (ev.executing_users || []).map((u) => u.id),
        executing_users: (ev.executing_users || []).map((u) => ({
            id: u.id,
            name: u.name,
            breaktime: u.pivot?.breaktime ?? 0,
        })),
        eventable_id: ev.service_orders?.[0]?.id ?? null,
        eventable_type: "\\App\\Models\\ServiceOrder",
        customer_id,
        customer_name: customer?.name || null,
        project_name: ev.service_orders?.[0]?.project?.title || null,
        is_preliminary: ev.is_preliminary ?? false,
        from_google: ev.origin === "google",
        location: ev.location || null,
        task_titles: (ev.service_orders?.[0]?.task_instances || [])
            .map((ti) => ti.service_order_task?.title)
            .filter(Boolean),
        task_instances: (ev.service_orders?.[0]?.task_instances || []).map((ti) => ({
            id: ti.id,
            title: ti.title || ti.service_order_task?.title || null,
            quantity: ti.quantity ?? 1,
            product: ti.product
                ? {
                      id: ti.product.id,
                      name: [ti.product.brand?.name, ti.product.model].filter(Boolean).join(' '),
                      specific_attributes: ti.product.specific_attributes || [],
                  }
                : null,
        })),
    };
}

/**
 * @param {import('vue').Ref} weekStart - reactive ref holding the week's Monday date
 * @param {() => boolean} shouldSkipPoll - return true to suppress a silent poll tick
 */
export function usePlannerEvents(weekStart, shouldSkipPoll = () => false) {
    const events = ref([]);

    let fetchInFlight = false;
    let lastEventsFingerprint = null;
    let pollTimer = null;

    async function fetchEvents({ silent = false } = {}) {
        if (fetchInFlight) return;
        fetchInFlight = true;
        try {
            const startParam = formatUtcDatetime(weekStart.value);
            const endParam = formatUtcDatetime(
                dayjs(weekStart.value).add(7, "day").toDate()
            );
            const response = await axios.get(
                `/api/events?start=${encodeURIComponent(
                    startParam
                )}&end=${encodeURIComponent(endParam)}`
            );
            if (response.status !== 200) return;
            const fingerprint = JSON.stringify(response.data);
            if (silent && fingerprint === lastEventsFingerprint) return;
            lastEventsFingerprint = fingerprint;
            events.value = response.data.map(mapEvent);
        } catch (e) {
            if (!silent) console.error("Failed to fetch events for planner", e);
        } finally {
            fetchInFlight = false;
        }
    }

    function pollEvents() {
        if (fetchInFlight) return;
        if (document.hidden) return;
        if (shouldSkipPoll()) return;
        fetchEvents({ silent: true });
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollEvents, 5_000);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function resetFingerprint() {
        lastEventsFingerprint = null;
    }

    return { events, fetchEvents, startPolling, stopPolling, resetFingerprint };
}
