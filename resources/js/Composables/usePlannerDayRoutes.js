import { onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import dayjs from '@/Utilities/dayjs';
import { formatLocalDateAsISO } from '@/Utilities/Utilities';

const REFRESH_MS = 60_000;

/**
 * The routes put in people's days, for the days on screen. They are put in from
 * a map, often in another window while the planner stays open, so they are
 * fetched again every minute as well as with every other week or view.
 */
export function usePlannerDayRoutes(weekStart, dayCount) {
    const page = usePage();
    const routes = ref([]);

    const fetchRoutes = async () => {
        const params = {
            start: formatLocalDateAsISO(weekStart.value),
            end: formatLocalDateAsISO(dayjs(weekStart.value).add(dayCount.value - 1, 'day').toDate()),
        };
        const response = await axios.get('/api/dayroutes', { params }).catch(() => null);
        if (response) {
            routes.value = response.data;
        }
    };

    const routeFor = (userId, dayIso) => routes.value
        .find((route) => route.user_id === userId && route.date === dayIso) ?? null;

    const removeRoute = async (route) => {
        if (!confirm(`Route van ${route.user_name} voor ${route.day_label} uit de planning halen?`)) {
            return;
        }
        try {
            await axios.get('/sanctum/csrf-cookie');
            await axios.delete(`/api/dayroutes/${route.id}`);
            routes.value = routes.value.filter(({ id }) => id !== route.id);
        } catch (exception) {
            page.props.flash.error = exception.response?.status === 403
                ? 'Je mag deze route niet uit de planning halen.'
                : 'De route kon niet uit de planning worden gehaald. Probeer het opnieuw.';
        }
    };

    let timer = null;
    onMounted(() => {
        fetchRoutes();
        timer = setInterval(() => {
            if (!document.hidden) {
                fetchRoutes();
            }
        }, REFRESH_MS);
    });
    onUnmounted(() => clearInterval(timer));
    watch([weekStart, dayCount], fetchRoutes);

    return { routeFor, removeRoute };
}
