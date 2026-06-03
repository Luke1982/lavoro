<template>
    <div v-if="groupedItems.length" class="border-1 border-gray-200/70 rounded-lavoro-sm">
        <!-- Header -->
        <div
            class="hidden lg:flex items-center gap-3 py-3 border-b border-gray-200/70 dark:border-slate-700/60 text-xs font-semibold text-gray-400 uppercase bg-gray-50/60">
            <div class="flex-1 pl-12">Keuring</div>
            <div class="w-66 shrink-0">Huidige verloopdatum</div>
        </div>
        <!-- Groups -->
        <div v-auto-animate class="p-3">
            <div v-for="group in groupedItems" :key="group.job.id" class="relative">
                <!-- Vertical spine: bottom of parent arrow circle → centre of last child -->
                <div v-if="group.children.length"
                    class="absolute left-[13px] top-12 bottom-16 w-0.5 bg-gray-200 dark:bg-slate-700 pointer-events-none" />
                <ServiceJobRow :servicejob="group.job" :has-children="group.children.length > 0" />
                <div v-if="group.children.length">
                    <div v-for="child in group.children" :key="child.id" class="relative pl-9">
                        <!-- Arched branch: spine reinforcement (top → centre) + curved turn right -->
                        <div
                            class="absolute left-[13px] top-0 bottom-1/2 w-[23px] border-l-2 border-b-2 border-gray-200 dark:border-slate-700 rounded-bl-lg pointer-events-none" />
                        <ServiceJobRow :servicejob="child" :is-child="true" />
                    </div>
                </div>
            </div>
        </div>
    </div>
    <p v-else class="text-sm text-gray-500 mt-3">Geen keuringen gevonden.</p>
</template>

<script setup>
import { computed } from 'vue'
import ServiceJobRow from './ServiceJobRow.vue'

const props = defineProps({
    servicejobs: { type: Array, default: () => [] },
})

const groupedItems = computed(() => {
    const childrenByParent = {}
    props.servicejobs.forEach(j => {
        if (j.parent_service_job_id) {
            if (!childrenByParent[j.parent_service_job_id]) childrenByParent[j.parent_service_job_id] = []
            childrenByParent[j.parent_service_job_id].push(j)
        }
    })
    return props.servicejobs
        .filter(j => !j.parent_service_job_id)
        .map(parent => ({
            job: parent,
            children: childrenByParent[parent.id] ?? [],
        }))
})
</script>
