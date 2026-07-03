<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue';

const props = defineProps({
    users: { type: Array, required: true },
    grants: { type: Array, required: true },
});

const selected_user_id = ref(props.users[0]?.id ?? null);
const add_viewer_id = ref(null);

const selected_user = computed(() => props.users.find((u) => u.id === selected_user_id.value));

const viewers_for_selected = computed(() =>
    props.grants
        .filter((g) => g.owner_user_id === selected_user_id.value)
        .map((g) => ({ grant_id: g.id, user: g.viewer_user }))
);

const grant_count_by_user = computed(() => {
    const map = {};
    for (const g of props.grants) {
        map[g.owner_user_id] = (map[g.owner_user_id] ?? 0) + 1;
    }
    return map;
});

const available_to_add = computed(() => {
    const granted_ids = new Set(viewers_for_selected.value.map((v) => v.user.id));
    return props.users.filter((u) => u.id !== selected_user_id.value && !granted_ids.has(u.id));
});

const add_viewer_options = computed(() =>
    available_to_add.value.map((u) => ({ value: u.id, title: u.name, shortTitle: u.name }))
);

watch(add_viewer_id, (new_value) => {
    if (new_value === null || new_value === undefined) return;
    router.post(
        '/admin/calendar-grants',
        {
            owner_user_id: selected_user_id.value,
            viewer_user_id: new_value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                add_viewer_id.value = null;
            },
        },
    );
});

function removeViewer(grant_id) {
    if (!confirm('Toegang intrekken?')) return;
    router.delete(`/admin/calendar-grants/${grant_id}`, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="flex flex-col gap-6 p-6 lg:flex-row">
        <div class="lg:w-1/3">
            <h2 class="mb-2 text-lg font-medium dark:text-white">Gebruikers</h2>
            <ul class="rounded border bg-white dark:bg-lavoro-dark dark:border-gray-700">
                <li
                    v-for="user in users"
                    :key="user.id"
                    class="flex cursor-pointer items-center justify-between border-b px-3 py-2 last:border-b-0 dark:border-gray-700 dark:text-white"
                    :class="{ 'bg-blue-50 dark:bg-gray-800': user.id === selected_user_id }"
                    @click="selected_user_id = user.id"
                >
                    <span>{{ user.name }}</span>
                    <span
                        v-if="grant_count_by_user[user.id]"
                        class="rounded bg-gray-200 px-2 text-xs dark:bg-gray-700 dark:text-gray-200"
                    >
                        {{ grant_count_by_user[user.id] }}
                    </span>
                </li>
            </ul>
        </div>

        <div class="flex-1">
            <h2 class="mb-2 text-lg font-medium dark:text-white">
                Wie kan de agenda van {{ selected_user?.name }} zien?
            </h2>

            <ul class="mb-4 rounded border bg-white dark:bg-lavoro-dark dark:border-gray-700">
                <li
                    v-if="viewers_for_selected.length === 0"
                    class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400"
                >
                    Niemand
                </li>
                <li
                    v-for="viewer in viewers_for_selected"
                    :key="viewer.grant_id"
                    class="cursor-pointer border-b px-3 py-2 last:border-b-0 hover:bg-red-50 dark:border-gray-700 dark:text-white dark:hover:bg-red-900/30"
                    title="Klik om toegang in te trekken"
                    @click="removeViewer(viewer.grant_id)"
                >
                    {{ viewer.user.name }}
                </li>
            </ul>

            <div>
                <h3 class="mb-1 text-sm font-medium dark:text-white">Voeg gebruiker toe</h3>
                <SelectMenuComponent
                    v-model="add_viewer_id"
                    :options="add_viewer_options"
                    label="Kies gebruiker"
                />
            </div>
        </div>
    </div>
</template>
