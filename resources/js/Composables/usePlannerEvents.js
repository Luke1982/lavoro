import { ref } from "vue";
import axios from "axios";
import { formatUtcDatetime } from "@/Utilities/Utilities";
import dayjs from "@/Utilities/dayjs";

function mapEvent(ev) {
    const customer_id = ev.service_orders?.[0]?.customer_id ?? ev.customers?.[0]?.id ?? null;
    const customer = ev.service_orders?.[0]?.customer ?? ev.customers?.[0] ?? null;
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
            user_role_ids: u.pivot?.user_role_ids ?? [],
            has_diverging_times: u.pivot?.has_diverging_times ?? false,
            diverging_start: u.pivot?.diverging_start ?? null,
            diverging_end: u.pivot?.diverging_end ?? null,
            completion_status: u.pivot?.completion_status ?? "planned",
            actual_start: u.pivot?.actual_start ?? null,
            actual_end: u.pivot?.actual_end ?? null,
            travel_time_minutes: u.pivot?.travel_time_minutes ?? 0,
            has_signature: u.pivot?.has_signature ?? false,
        })),
        eventable_id: ev.service_orders?.[0]?.id ?? null,
        eventable_type: "\\App\\Models\\ServiceOrder",
        customer_id,
        customer_name: customer?.name || null,
        project_name: ev.service_orders?.[0]?.project?.title || null,
        is_preliminary: ev.is_preliminary ?? false,
        no_service_order: ev.no_service_order ?? false,
        remarks_count: ev.remarks_count ?? 0,
        images_count: ev.images_count ?? 0,
        is_closed: ev.service_orders?.[0]?.is_closed ?? false,
        is_incomplete: ev.service_orders?.[0]?.is_incomplete ?? false,
        is_invoiced: ev.service_orders?.[0]?.is_invoiced ?? false,
        from_google: ev.origin === "google",
        location_id: ev.location_id ?? null,
        // Resolved once on the backend (EventLocationResolver) so the escalation
        // order can never drift between the planner, the calendar and the export.
        location: ev.display_location ?? null,
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

export function usePlannerEvents(weekStart, dayCount, shouldSkipPoll = () => false) {
    const events = ref([]);
    const eventsLoading = ref(false);

    let fetchInFlight = false;
    let refetchQueued = false;
    let lastEventsFingerprint = null;
    let pollTimer = null;

    async function fetchEvents({ silent = false } = {}) {
        // A background poll must never swallow a week change: when the user asks
        // for fresh data mid-poll, show the overlay now and refetch once the poll
        // in flight lands, so the new week always loads and is never left stale.
        if (fetchInFlight) {
            if (!silent) {
                eventsLoading.value = true;
                refetchQueued = true;
            }
            return;
        }
        fetchInFlight = true;
        if (!silent) eventsLoading.value = true;
        try {
            const startParam = formatUtcDatetime(weekStart.value);
            const endParam = formatUtcDatetime(
                dayjs(weekStart.value).add(dayCount.value, "day").toDate()
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
            if (refetchQueued) {
                refetchQueued = false;
                fetchEvents();
            } else if (!silent) {
                eventsLoading.value = false;
            }
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

    return { events, eventsLoading, fetchEvents, startPolling, stopPolling, resetFingerprint };
}
