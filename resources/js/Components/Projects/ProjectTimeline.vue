<template>
    <div>
        <div v-if="loading" class="flex items-center gap-2 text-xs text-gray-500 dark:text-slate-500 py-6 justify-center">
            <svg class="animate-spin size-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
            </svg>
            Tijdlijn laden…
        </div>

        <div v-else-if="!hasContent" class="text-xs text-gray-500 dark:text-slate-500 py-4">
            Nog niets om op de tijdlijn te tonen.
        </div>

        <div v-else>
            <div class="flex items-center gap-3 mb-3 flex-wrap">
                <div class="flex rounded-md border border-gray-300 dark:border-slate-700 overflow-hidden text-xs">
                    <button type="button" class="px-2.5 py-1 transition-colors"
                        :class="viewMode === 'month' ? 'bg-lavoro-blue text-white' : 'hover:bg-gray-50 dark:hover:bg-slate-800'"
                        @click="viewMode = 'month'">
                        Maanden
                    </button>
                    <button type="button" class="px-2.5 py-1 border-l border-gray-300 dark:border-slate-700 transition-colors"
                        :class="viewMode === 'week' ? 'bg-lavoro-blue text-white' : 'hover:bg-gray-50 dark:hover:bg-slate-800'"
                        @click="viewMode = 'week'">
                        Weken
                    </button>
                </div>
                <!--
                    Native anchor-link scroll: the browser scrolls the nearest scrollable
                    ancestor (the overflow-x-auto container below) to bring #tl-today into view,
                    with no scrollTo/scrollBy JS involved — see scroll-margin-left on the target
                    and scroll-behavior: smooth on the container in <style>.
                -->
                <a href="#tl-today"
                    class="rounded-md border border-gray-300 dark:border-slate-700 px-2.5 py-1 text-xs hover:bg-gray-50 dark:hover:bg-slate-800">
                    Vandaag
                </a>
                <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-slate-400" title="Horizontaal zoomen — tijdsperiode">
                    <MagnifyingGlassIcon class="size-3.5 shrink-0" />
                    <input type="range" :min="minZoomColPx" max="2000" step="1" v-model.number="zoomColPx" class="accent-lavoro-blue" />
                </label>
            </div>

            <!--
                Sidebar and canvas are two genuinely separate elements side by side — the sidebar
                is NOT a descendant of .tl-scroller, so there's no sticky/scroll trickery needed
                for it to stay put; it simply isn't part of the scrolling content. Row heights are
                computed once (soPacked.laneCount * LANE_HEIGHT etc.) and applied identically to
                both the sidebar block and its matching canvas lane, so they always line up.
            -->
            <div class="flex gap-2">
                <label class="tl-vslider-wrap shrink-0" title="Verticaal zoomen — detail per vak">
                    <input type="range" min="48" max="140" step="1" v-model.number="LANE_HEIGHT" class="tl-vslider accent-lavoro-blue" />
                </label>

                <!-- Fixed sidebar -->
                <div class="shrink-0 tl-sidebar" :style="{ width: LEFT_COL_PX + 'px' }">
                    <div style="height: 1.5rem" />

                    <template v-if="milestones.length">
                        <div class="pr-3" :style="{ height: (msPacked.laneCount * MILESTONE_LANE_HEIGHT) + 'px' }">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-slate-300">
                                <FlagIcon class="size-4 text-green-600" />
                                Mijlpalen
                            </div>
                        </div>
                        <div class="tl-sidebar-gap tl-sidebar-divider" />
                    </template>

                    <template v-if="serviceOrders.length">
                        <div class="pr-3" :style="{ height: (soPacked.laneCount * LANE_HEIGHT) + 'px' }">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-slate-300">
                                <ClipboardDocumentListIcon class="size-4 text-blue-600" />
                                Werkbonnen
                                <span class="inline-flex items-center justify-center text-[10px] font-semibold bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 rounded-full px-1.5 py-px">
                                    {{ serviceOrders.length }}
                                </span>
                            </div>
                            <div class="mt-1.5 space-y-1">
                                <div v-for="so in sortedServiceOrders" :key="'lbl-so-' + so.id"
                                    class="text-[11px] text-gray-500 dark:text-slate-500 truncate">
                                    #{{ so.id }}<template v-if="so.description"> {{ so.description }}</template>
                                </div>
                            </div>
                        </div>
                        <div class="tl-sidebar-gap tl-sidebar-divider" />
                    </template>

                    <template v-if="sortedEvents.length">
                        <div class="pr-3" :style="{ height: (evPacked.laneCount * LANE_HEIGHT) + 'px' }">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-slate-300">
                                <CalendarIcon class="size-4 text-purple-600" />
                                Afspraken
                                <span class="inline-flex items-center justify-center text-[10px] font-semibold bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 rounded-full px-1.5 py-px">
                                    {{ sortedEvents.length }}
                                </span>
                            </div>
                            <div class="mt-1.5 space-y-1">
                                <div v-for="ev in sortedEvents" :key="'lbl-ev-' + ev.id"
                                    class="text-[11px] text-gray-500 dark:text-slate-500 truncate">
                                    {{ eventLabel(ev) }}
                                </div>
                            </div>
                        </div>
                        <div class="tl-sidebar-gap tl-sidebar-divider" />
                    </template>

                    <template v-if="sortedTickets.length">
                        <div class="pr-3" :style="{ height: (ticketPacked.laneCount * LANE_HEIGHT) + 'px' }">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-slate-300">
                                <ExclamationTriangleIcon class="size-4 text-orange-600" />
                                Storingen
                                <span class="inline-flex items-center justify-center text-[10px] font-semibold bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 rounded-full px-1.5 py-px">
                                    {{ sortedTickets.length }}
                                </span>
                            </div>
                            <div class="mt-1.5 space-y-1">
                                <div v-for="t in sortedTickets" :key="'lbl-t-' + t.id"
                                    class="text-[11px] text-gray-500 dark:text-slate-500 truncate">
                                    {{ t.subject || 'Storing' }}
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Scrollable canvas: overflow-x auto is the only thing driving horizontal
                     scroll here — free native scrolling, no snapping, user controls it entirely. -->
                <div class="overflow-x-auto tl-scroller min-w-0 flex-1" ref="scrollerRef">
                    <div class="relative tl-zoomable" :style="{ minWidth: containerMinWidthPx + 'px' }">
                        <!-- Gridline / today overlay: sits behind everything, spans header + all groups -->
                        <div class="absolute inset-0 z-0 pointer-events-none">
                            <!-- Every column gets a visible cell (alternating shade), including empty
                                 ones at the edges — an empty month/week should read as "nothing
                                 here", not as an unexplained blank void past where data ends. -->
                            <div v-for="(tick, i) in axisTicks" :key="'colbg-' + tick.key"
                                class="absolute top-0 bottom-0"
                                :class="i % 2 === 1 ? 'bg-gray-50 dark:bg-slate-800/30' : ''"
                                :style="{ left: tick.pct + '%', width: colWidthPct + '%' }" />
                            <div v-for="tick in axisTicks.filter(t => t.pct > 0)" :key="'grid-' + tick.key"
                                class="absolute top-0 bottom-0 border-l border-gray-100 dark:border-slate-800/70"
                                :style="{ left: tick.pct + '%' }" />
                            <div v-for="mark in projectBoundMarks" :key="'bound-' + mark.key" class="absolute top-0 bottom-0"
                                :style="{ left: pct(mark.date) + '%' }">
                                <div class="absolute top-0 bottom-0 left-0 w-px border-l border-dashed border-gray-300 dark:border-slate-600 -translate-x-1/2" />
                                <span
                                    class="absolute left-0 -translate-x-1/2 -translate-y-1/2 bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 text-[9px] font-semibold px-1.5 py-0.5 rounded-full whitespace-nowrap z-10"
                                    style="top: 20px">
                                    {{ mark.label }} {{ shortDate(mark.date) }}
                                </span>
                            </div>
                            <div v-if="todayPct !== null" id="tl-today" class="absolute top-0 bottom-0" :style="{ left: todayPct + '%' }">
                                <div class="absolute top-0 bottom-0 left-0 w-px bg-lavoro-blue/70 -translate-x-1/2" />
                                <span
                                    class="absolute left-0 -translate-x-1/2 -translate-y-1/2 bg-lavoro-blue text-white text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap shadow z-20"
                                    style="top: 20px">
                                    {{ todayLabel }}
                                </span>
                                <span
                                    class="absolute bottom-0 left-0 -translate-x-1/2 translate-y-1/2 size-2.5 rounded-full bg-lavoro-blue shadow" />
                            </div>
                        </div>

                        <div class="relative z-10">
                            <!--
                                Month/week header: plain flex row of fixed-px columns (each exactly
                                zoomColPx wide — true regardless of month/week mode since
                                containerMinWidthPx = axisTicks.length * zoomColPx). The sticky span
                                is a direct, non-absolutely-positioned flex descendant — the same
                                pattern already proven to work for the sidebar — so it actually
                                sticks within its own column as you scroll past it.
                            -->
                            <div class="flex pb-1 h-6">
                                <div v-for="tick in axisTicks" :key="tick.key" class="shrink-0 relative"
                                    :style="{ width: zoomColPx + 'px' }">
                                    <!-- display:inline-block, not block — verified that block breaks
                                         position:sticky's stuck-offset tracking entirely in this
                                         browser, while inline-block works correctly. -->
                                    <span class="sticky inline-block text-sm font-semibold text-gray-600 dark:text-slate-300 pl-1.5 whitespace-nowrap"
                                        style="left: 0">
                                        {{ tick.label }}
                                    </span>
                                </div>
                            </div>

                            <template v-if="milestones.length">
                                <div class="relative" :style="{ height: msPacked.laneCount * MILESTONE_LANE_HEIGHT + 'px' }">
                                    <button v-for="(ms, idx) in milestones" :key="'ms-' + ms.id" type="button"
                                        class="absolute flex flex-col items-center w-28 -translate-x-1/2 appearance-none bg-transparent border-0 p-0 cursor-pointer group"
                                        :style="{ left: pct(ms.projected_date || ms.actual_date) + '%', top: msPacked.lanes[idx] * MILESTONE_LANE_HEIGHT + 'px' }"
                                        @click="toggleSelect('milestone', ms)">
                                        <span class="block size-3 rotate-45 border border-white dark:border-slate-800 shadow-sm group-hover:brightness-110"
                                            :class="milestoneColor(ms)" />
                                        <span class="block w-full truncate text-center mt-1 text-[10px] font-semibold text-gray-700 dark:text-slate-300">
                                            {{ ms.title }}
                                        </span>
                                        <span class="block w-full truncate text-center text-[9px] text-gray-400">
                                            {{ shortDate(ms.projected_date || ms.actual_date) }}
                                        </span>
                                    </button>
                                </div>
                                <div class="tl-sidebar-gap" />
                            </template>

                            <template v-if="serviceOrders.length">
                                <div class="relative" :style="{ height: soPacked.laneCount * LANE_HEIGHT + 'px' }">
                                    <button v-for="(chip, idx) in soChips" :key="'so-' + chip.so.id" type="button"
                                        class="absolute rounded-md border px-2 py-1 text-left cursor-pointer hover:brightness-95 shadow-sm tl-chip"
                                        :style="soChipStyle(chip, soPacked.lanes[idx])"
                                        v-tooltip="{ html: true, content: soTooltipHtml(chip) }"
                                        @click="toggleSelect('serviceorder', chip.so)">
                                        <!--
                                            sticky + inline-block content wrapper — verified that
                                            overflow:hidden on the button (removed above) and a
                                            container-query (cqw) width both silently break
                                            position:sticky in this browser; a plain px max-width
                                            does not, so that's what's used here. This keeps the
                                            chip's title/date/avatars in view while the chip itself
                                            is only partially scrolled into the viewport.
                                        -->
                                        <span class="sticky inline-block tl-chip-content">
                                            <span class="tl-chip-clamp">
                                                <div class="tl-chip-title flex items-center gap-1 text-[11px] font-semibold text-gray-800 dark:text-slate-100">
                                                    <span class="truncate min-w-0">
                                                        #{{ chip.so.id }}<template v-if="chip.so.description"> {{ chip.so.description }}</template>
                                                    </span>
                                                    <span class="shrink-0 text-gray-400 hover:text-lavoro-blue"
                                                        v-tooltip="'Open werkbon'"
                                                        @click.stop="router.visit(`/serviceorders/${chip.so.id}`)">
                                                        <ArrowTopRightOnSquareIcon class="size-3" />
                                                    </span>
                                                </div>
                                                <div class="tl-chip-daterange text-[10px] text-gray-500 dark:text-slate-400 truncate">
                                                    {{ soDateRangeLabel(chip.span) }}
                                                </div>
                                                <!--
                                                    Both blocks always render; @container queries
                                                    in <style> (keyed to the button's own rendered
                                                    size) decide which one paints. No JS reads/
                                                    branches on zoom level here.
                                                -->
                                                <div v-if="chip.so.executing_users.length || completedTasksFor(chip.so).length"
                                                    class="tl-compact-only flex items-center gap-1 mt-0.5 overflow-hidden">
                                                    <span v-for="u in chip.so.executing_users.slice(0, 3)" :key="u.id"
                                                        class="text-[8px] font-semibold bg-white/80 dark:bg-slate-900/60 rounded-full size-3.5 flex items-center justify-center shrink-0"
                                                        v-tooltip="u.name">
                                                        {{ initials(u.name) }}
                                                    </span>
                                                    <span v-for="ti in completedTasksFor(chip.so)" :key="'ti-' + ti.id"
                                                        class="size-1.5 rounded-full bg-white/90 shrink-0" v-tooltip="taskTooltip(ti)" />
                                                </div>
                                                <div class="tl-detail-only">
                                                    <div v-if="chip.so.stage" class="text-[9px] text-gray-500 dark:text-slate-400 truncate mt-0.5">
                                                        {{ chip.so.stage.name }}
                                                    </div>
                                                    <div v-if="chip.so.executing_users.length" class="flex flex-wrap gap-1 mt-0.5 overflow-hidden">
                                                        <span v-for="u in chip.so.executing_users" :key="u.id"
                                                            class="text-[9px] bg-white/80 dark:bg-slate-900/60 rounded px-1 py-px truncate">
                                                            {{ u.name }}
                                                        </span>
                                                    </div>
                                                    <div v-if="chip.so.task_instances.length" class="text-[9px] text-gray-500 dark:text-slate-400 mt-0.5 truncate">
                                                        {{ completedTasksFor(chip.so).length }}/{{ chip.so.task_instances.length }} taken voltooid
                                                    </div>
                                                </div>
                                            </span>
                                        </span>
                                    </button>
                                </div>
                                <div class="tl-sidebar-gap" />
                            </template>

                            <template v-if="sortedEvents.length">
                                <div class="relative" :style="{ height: evPacked.laneCount * LANE_HEIGHT + 'px' }">
                                    <button v-for="(chip, idx) in evChips" :key="'ev-' + chip.ev.id" type="button"
                                        class="absolute rounded-md border px-2 py-1 text-left cursor-pointer hover:brightness-95 shadow-sm tl-chip"
                                        :style="evChipStyle(chip, evPacked.lanes[idx])"
                                        v-tooltip="{ html: true, content: evTooltipHtml(chip) }"
                                        @click="toggleSelect('event', chip.ev)">
                                        <span class="sticky inline-block tl-chip-content">
                                            <span class="tl-chip-clamp">
                                                <div class="tl-chip-title text-[11px] font-semibold truncate text-gray-800 dark:text-slate-100">
                                                    {{ eventLabel(chip.ev) }}
                                                </div>
                                                <div class="tl-chip-daterange text-[10px] text-gray-500 dark:text-slate-400 truncate">
                                                    {{ eventDateRangeLabel(chip.ev) }}
                                                </div>
                                                <div v-if="chip.ev.executing_users.length" class="tl-compact-only flex items-center gap-1 mt-0.5 overflow-hidden">
                                                    <span v-for="u in chip.ev.executing_users.slice(0, 3)" :key="u.id"
                                                        class="text-[8px] font-semibold bg-white/80 dark:bg-slate-900/60 rounded-full size-3.5 flex items-center justify-center shrink-0"
                                                        v-tooltip="u.name">
                                                        {{ initials(u.name) }}
                                                    </span>
                                                </div>
                                                <div v-if="chip.ev.executing_users.length" class="tl-detail-only flex flex-wrap gap-1 mt-0.5 overflow-hidden">
                                                    <span v-for="u in chip.ev.executing_users" :key="u.id"
                                                        class="text-[9px] bg-white/80 dark:bg-slate-900/60 rounded px-1 py-px truncate">
                                                        {{ u.name }}
                                                    </span>
                                                </div>
                                            </span>
                                        </span>
                                    </button>
                                </div>
                                <div class="tl-sidebar-gap" />
                            </template>

                            <template v-if="sortedTickets.length">
                                <div class="relative" :style="{ height: ticketPacked.laneCount * LANE_HEIGHT + 'px' }">
                                    <button v-for="(chip, idx) in ticketChips" :key="'t-' + chip.t.id" type="button"
                                        class="absolute rounded-md border px-2 py-1 text-left cursor-pointer hover:brightness-95 shadow-sm tl-chip"
                                        :style="ticketChipStyle(chip, ticketPacked.lanes[idx])"
                                        v-tooltip="{ html: true, content: ticketTooltipHtml(chip) }"
                                        @click="toggleSelect('ticket', chip.t)">
                                        <span class="sticky inline-block tl-chip-content">
                                            <span class="tl-chip-clamp">
                                                <div class="tl-chip-title flex items-center gap-1 text-[11px] font-semibold text-gray-800 dark:text-slate-100">
                                                    <span class="truncate min-w-0">{{ chip.t.subject || 'Storing' }}</span>
                                                    <span class="shrink-0 text-gray-400 hover:text-lavoro-blue"
                                                        v-tooltip="'Open storing'"
                                                        @click.stop="router.visit(`/tickets/${chip.t.id}`)">
                                                        <ArrowTopRightOnSquareIcon class="size-3" />
                                                    </span>
                                                </div>
                                                <div class="tl-chip-daterange text-[10px] text-gray-500 dark:text-slate-400 truncate">
                                                    {{ ticketDateRangeLabel(chip.t) }}
                                                </div>
                                                <div class="tl-detail-only text-[9px] text-gray-500 dark:text-slate-400 mt-0.5 truncate">
                                                    {{ chip.t.status }} · Prioriteit: {{ chip.t.priority }}
                                                </div>
                                            </span>
                                        </span>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <DrawerComponent v-model="drawerOpen" max-width-class="max-w-md" :title="drawerTitle">
            <div v-if="selected.type === 'milestone'" class="p-4 sm:p-6 space-y-3 text-sm">
                <p v-if="selected.item.description" class="text-gray-600 dark:text-slate-400">{{ selected.item.description }}</p>
                <div v-if="selected.item.assigned_user" class="flex items-center gap-1.5 text-gray-700 dark:text-slate-300">
                    <UserIcon class="size-4 text-gray-400" />
                    {{ selected.item.assigned_user.name }}
                </div>
                <div v-if="selected.item.projected_date" class="text-xs text-gray-500 dark:text-slate-500">
                    Gepland: {{ nlDate(selected.item.projected_date) }}
                </div>
                <div v-if="selected.item.actual_date" class="text-xs text-green-600 dark:text-green-400">
                    Afgerond: {{ nlDate(selected.item.actual_date) }}
                </div>
            </div>

            <div v-else-if="selected.type === 'serviceorder'" class="p-4 sm:p-6 space-y-4 text-sm">
                <div v-if="selected.item.stage" class="text-xs text-gray-500 dark:text-slate-500">
                    Status: {{ selected.item.stage.name }}
                </div>
                <div v-if="selected.item.executing_users.length" class="space-y-1">
                    <div class="text-xs font-semibold text-gray-500 dark:text-slate-500">Uitvoerenden</div>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="u in selected.item.executing_users" :key="u.id"
                            class="inline-flex items-center gap-1 text-xs bg-gray-100 dark:bg-slate-800 rounded px-2 py-0.5">
                            {{ u.name }}
                        </span>
                    </div>
                </div>
                <div v-if="selected.item.events.length" class="space-y-1">
                    <div class="text-xs font-semibold text-gray-500 dark:text-slate-500">Afspraken</div>
                    <div v-for="ev in selected.item.events" :key="ev.id" class="text-xs text-gray-700 dark:text-slate-300">
                        {{ eventLabel(ev) }} — {{ nlDate(ev.start) }} {{ nlTime(ev.start) }}
                    </div>
                </div>
                <div v-if="selected.item.task_instances.length" class="space-y-1">
                    <div class="text-xs font-semibold text-gray-500 dark:text-slate-500">Taken</div>
                    <div v-for="ti in selected.item.task_instances" :key="ti.id"
                        class="flex items-center gap-1.5 text-xs text-gray-700 dark:text-slate-300">
                        <CheckIcon v-if="ti.is_complete" class="size-3.5 text-green-600 shrink-0" />
                        <ClockIcon v-else class="size-3.5 text-gray-400 shrink-0" />
                        <span :class="{ 'line-through text-gray-400': ti.is_cancelled }">{{ ti.title }}</span>
                        <span v-if="ti.completed_at" class="text-gray-400">
                            · {{ nlDate(ti.completed_at) }}<template v-if="ti.completed_by"> door {{ ti.completed_by.name }}</template>
                        </span>
                    </div>
                </div>
                <Link :href="`/serviceorders/${selected.item.id}`"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90">
                    Open werkbon
                </Link>
            </div>

            <div v-else-if="selected.type === 'event'" class="p-4 sm:p-6 space-y-3 text-sm">
                <p v-if="selected.item.description" class="text-gray-600 dark:text-slate-400">{{ selected.item.description }}</p>
                <div class="text-xs text-gray-500 dark:text-slate-500">
                    {{ nlDate(selected.item.start) }} · {{ nlTime(selected.item.start) }} – {{ nlTime(selected.item.end) }}
                </div>
                <div v-if="selected.item.location" class="text-xs text-gray-500 dark:text-slate-500">
                    {{ selected.item.location }}
                </div>
                <div v-if="selected.item.executing_users.length" class="flex flex-wrap gap-1.5">
                    <span v-for="u in selected.item.executing_users" :key="u.id"
                        class="inline-flex items-center gap-1 text-xs bg-gray-100 dark:bg-slate-800 rounded px-2 py-0.5">
                        {{ u.name }}
                    </span>
                </div>
            </div>

            <div v-else-if="selected.type === 'ticket'" class="p-4 sm:p-6 space-y-3 text-sm">
                <div class="text-xs text-gray-500 dark:text-slate-500">
                    Status: {{ selected.item.status }} · Prioriteit: {{ selected.item.priority }}
                </div>
                <div class="text-xs text-gray-500 dark:text-slate-500">
                    Gemeld: {{ nlDate(selected.item.created_at) }}
                </div>
                <div v-if="selected.item.closed_on" class="text-xs text-green-600 dark:text-green-400">
                    Gesloten: {{ nlDate(selected.item.closed_on) }}
                </div>
                <Link :href="`/tickets/${selected.item.id}`"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-lavoro-blue text-white text-xs font-semibold rounded hover:opacity-90">
                    Open storing
                </Link>
            </div>
        </DrawerComponent>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import {
    FlagIcon, ClipboardDocumentListIcon, CalendarIcon, UserIcon, CheckIcon, ClockIcon,
    ExclamationTriangleIcon, MagnifyingGlassIcon, ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/24/outline'
import { nlDate, nlTime, initials } from '@/Utilities/Utilities'

const LEFT_COL_PX = 224
const MILESTONE_LANE_HEIGHT = 46
const MILESTONE_MIN_GAP_DAYS = 10

// Last-used zoom level is remembered per browser session (sessionStorage), horizontal and
// vertical tracked separately, shared across every project timeline you open this session.
const ZOOM_H_STORAGE_KEY = 'lavoro-timeline-zoom-h'
const ZOOM_V_STORAGE_KEY = 'lavoro-timeline-zoom-v'

function readStoredZoom(key, fallback) {
    const n = Number(sessionStorage.getItem(key))
    return Number.isFinite(n) && n > 0 ? n : fallback
}

const viewMode = ref('month') // 'month' | 'week'
const zoomColPx = ref(readStoredZoom(ZOOM_H_STORAGE_KEY, 200)) // px per axis column — horizontal slider's v-model (time axis only)
const LANE_HEIGHT = ref(readStoredZoom(ZOOM_V_STORAGE_KEY, 64)) // px per lane row — vertical slider's v-model (row height only, independent of horizontal zoom)

watch(zoomColPx, (v) => sessionStorage.setItem(ZOOM_H_STORAGE_KEY, String(v)))
watch(LANE_HEIGHT, (v) => sessionStorage.setItem(ZOOM_V_STORAGE_KEY, String(v)))

// Measuring the scroller's own rendered width has no CSS equivalent (needed to compute the
// smallest useful horizontal zoom — the point where content exactly fills the wrapper and
// zooming out further would just waste space rather than reveal more).
const scrollerRef = ref(null)
const wrapperWidthPx = ref(0)
let resizeObserver = null

// scrollerRef only exists once loading finishes and the v-else branch mounts (a plain onMounted
// here would run too early and observe nothing) — watch the ref itself and attach once it's set.
watch(scrollerRef, (el) => {
    resizeObserver?.disconnect()
    if (el) {
        resizeObserver = new ResizeObserver((entries) => {
            wrapperWidthPx.value = entries[0].contentRect.width
        })
        resizeObserver.observe(el)
    }
})

onUnmounted(() => {
    resizeObserver?.disconnect()
})

const props = defineProps({
    projectId: { type: [Number, String], required: true },
    projectStartDate: { type: String, default: null },
    projectEndDate: { type: String, default: null },
    // The project page's own reactive copy of its milestones (already kept fresh by Inertia on
    // every edit). This component fetches its own richer snapshot via axios (it needs service
    // orders/events/tickets too, which aren't on the page props), so that snapshot goes stale
    // the moment something changes elsewhere on the page — e.g. marking a milestone complete in
    // the sidebar doesn't touch this component's own `milestones` ref at all. Watching a summary
    // of this prop below is what triggers a re-fetch so the timeline doesn't need a page reload.
    projectMilestones: { type: Array, default: () => [] },
})

const loading = ref(true)
const milestones = ref([])
const serviceOrders = ref([])

async function fetchTimeline() {
    const { data } = await axios.get(`/projects/${props.projectId}/timeline`)
    milestones.value = data.milestones
    serviceOrders.value = data.service_orders
}

// Cheap fingerprint of what actually affects rendering (completion/date), not object identity.
const milestonesFingerprint = computed(() =>
    props.projectMilestones.map((m) => `${m.id}:${m.actual_date}:${m.projected_date}`).join('|')
)

watch(milestonesFingerprint, async () => {
    if (loading.value) return
    await fetchTimeline()
})

onMounted(async () => {
    try {
        await fetchTimeline()
    } finally {
        loading.value = false
    }
})

const hasContent = computed(() =>
    milestones.value.length > 0 || serviceOrders.value.length > 0
)

const sortedEvents = computed(() => {
    const byId = new Map()
    for (const so of serviceOrders.value) {
        for (const ev of so.events) {
            if (!byId.has(ev.id)) byId.set(ev.id, ev)
        }
    }
    return Array.from(byId.values()).sort((a, b) => new Date(a.start) - new Date(b.start))
})

const sortedTickets = computed(() => {
    const byId = new Map()
    for (const so of serviceOrders.value) {
        for (const t of so.tickets) {
            if (!byId.has(t.id)) byId.set(t.id, t)
        }
    }
    return Array.from(byId.values()).sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
})

const sortedServiceOrders = computed(() =>
    serviceOrders.value.slice().sort((a, b) => {
        const da = soSpan(a)?.start ?? a.created_at
        const db = soSpan(b)?.start ?? b.created_at
        return new Date(da) - new Date(db)
    })
)

// actual_start_time/actual_end_time are TIME-only DB columns (no date component) and are not
// paired with a date field anywhere in the app, so they can't be placed on a calendar axis —
// the span is driven entirely by linked events.
function soSpan(so) {
    const starts = so.events.map(e => e.start)
    const ends = so.events.map(e => e.end)
    if (!starts.length || !ends.length) return null
    return {
        start: starts.reduce((min, d) => (new Date(d) < new Date(min) ? d : min)),
        end: ends.reduce((max, d) => (new Date(d) > new Date(max) ? d : max)),
    }
}

function completedTasksFor(so) {
    return so.task_instances.filter(ti => ti.is_complete && ti.completed_at)
}

function taskTooltip(ti) {
    const who = ti.completed_by ? ` door ${ti.completed_by.name}` : ''
    return `${ti.title} — voltooid${who}, ${nlDate(ti.completed_at)}`
}

// --- Time span & positioning ---

const allDates = computed(() => {
    // Today is always included so the "now" marker is always on the chart, even for a
    // project whose date range has already ended or hasn't started yet.
    const dates = [new Date()]
    if (props.projectStartDate) dates.push(props.projectStartDate)
    if (props.projectEndDate) dates.push(props.projectEndDate)
    for (const ms of milestones.value) {
        if (ms.projected_date) dates.push(ms.projected_date)
        if (ms.actual_date) dates.push(ms.actual_date)
    }
    for (const so of serviceOrders.value) {
        if (so.created_at) dates.push(so.created_at)
        for (const ev of so.events) {
            dates.push(ev.start)
            dates.push(ev.end)
        }
        for (const t of so.tickets) {
            if (t.created_at) dates.push(t.created_at)
            if (t.closed_on) dates.push(t.closed_on)
        }
    }
    // A single unparseable date (e.g. a TIME-only value with no date component) must never
    // poison Math.min/Math.max for the whole chart — drop anything that doesn't parse.
    return dates.filter(Boolean).map(d => new Date(d)).filter(d => !isNaN(d.getTime()))
})

const spanStart = computed(() => {
    if (!allDates.value.length) return new Date()
    const min = new Date(Math.min(...allDates.value.map(d => d.getTime())))
    min.setDate(min.getDate() - 2)
    return min
})

const spanEnd = computed(() => {
    if (!allDates.value.length) return new Date()
    const max = new Date(Math.max(...allDates.value.map(d => d.getTime())))
    max.setDate(max.getDate() + 2)
    return max
})

// Equal-width columns, either one per calendar month or one per week depending on viewMode —
// a short partial unit at the edge of the range still renders at full column width. The full
// project data span (spanStart..spanEnd) is never clipped by the mode; switching to weeks just
// subdivides the same span into more, narrower columns (the container grows to fit, so nothing
// is hidden — you scroll/page to see more of it).
function monthKey(d) {
    return d.getFullYear() * 12 + d.getMonth()
}

function startOfWeek(d) {
    const date = new Date(d)
    const day = (date.getDay() + 6) % 7 // Monday = 0
    date.setDate(date.getDate() - day)
    date.setHours(0, 0, 0, 0)
    return date
}

// Standard ISO 8601 week number (weeks start Monday, week 1 contains the year's first Thursday).
function isoWeekNumber(d) {
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
    const dayNum = (date.getUTCDay() + 6) % 7
    date.setUTCDate(date.getUTCDate() - dayNum + 3)
    const firstThursday = new Date(Date.UTC(date.getUTCFullYear(), 0, 4))
    const firstThursdayDayNum = (firstThursday.getUTCDay() + 6) % 7
    firstThursday.setUTCDate(firstThursday.getUTCDate() - firstThursdayDayNum + 3)
    return 1 + Math.round((date - firstThursday) / (7 * 86400000))
}

const spanStartMonthIdx = computed(() => monthKey(spanStart.value))
const spanEndMonthIdx = computed(() => monthKey(spanEnd.value))
const monthCount = computed(() => spanEndMonthIdx.value - spanStartMonthIdx.value + 1)

const weekStartBase = computed(() => startOfWeek(spanStart.value))
const weekCount = computed(() => Math.max(1, Math.ceil((spanEnd.value - weekStartBase.value) / (7 * 86400000))))

const unitCount = computed(() => (viewMode.value === 'week' ? weekCount.value : monthCount.value))
const colWidthPct = computed(() => 100 / unitCount.value)

function pct(date) {
    if (!date) return 0
    const d = new Date(date)
    let raw
    if (viewMode.value === 'week') {
        const diffDays = (d - weekStartBase.value) / 86400000
        raw = (diffDays / 7) * colWidthPct.value
    } else {
        const colIdx = monthKey(d) - spanStartMonthIdx.value
        const daysInMonth = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()
        const fraction = (d.getDate() - 1 + d.getHours() / 24 + d.getMinutes() / 1440) / daysInMonth
        raw = (colIdx + fraction) * colWidthPct.value
    }
    return Math.min(100, Math.max(0, raw))
}

// One tick per visible column (month or week, depending on viewMode) — drives both the header
// labels and the background gridlines.
const axisTicks = computed(() => {
    const ticks = []
    if (viewMode.value === 'week') {
        for (let i = 0; i < weekCount.value; i++) {
            const d = new Date(weekStartBase.value.getTime() + i * 7 * 86400000)
            ticks.push({ key: 'w' + i, label: `Wk ${isoWeekNumber(d)} · ${shortDate(d)}`, pct: i * colWidthPct.value })
        }
    } else {
        for (let i = 0; i < monthCount.value; i++) {
            const totalMonth = spanStartMonthIdx.value + i
            const year = Math.floor(totalMonth / 12)
            const month = totalMonth % 12
            ticks.push({
                key: `${year}-${month}`,
                label: new Date(year, month, 1).toLocaleDateString('nl-NL', { month: 'short' }),
                pct: i * colWidthPct.value,
            })
        }
    }
    return ticks
})

// The sidebar lives outside .tl-scroller entirely now, so its content width IS the full chart
// canvas — no LEFT_COL_PX offset needed here anymore. Container grows to keep each column at
// least as wide as the zoom slider says; this, plus native overflow-x on the wrapper below, is
// what makes the chart scrollable/zoomable without any JS touching scroll position.
const containerMinWidthPx = computed(() => axisTicks.value.length * zoomColPx.value)
const laneAreaPxEstimate = computed(() => containerMinWidthPx.value)

// Smallest useful horizontal zoom: the point where content exactly fills the wrapper. Zooming
// out further wouldn't reveal anything more — it'd just shrink everything into empty space.
const minZoomColPx = computed(() => {
    if (!wrapperWidthPx.value || !axisTicks.value.length) return 20
    return Math.max(20, Math.floor(wrapperWidthPx.value / axisTicks.value.length))
})

watch(minZoomColPx, (min) => {
    if (zoomColPx.value < min) zoomColPx.value = min
})

function shortDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })
}

function soDateRangeLabel(span) {
    if (!span) return 'Nog niet gepland'
    const s = shortDate(span.start)
    const e = shortDate(span.end)
    return s === e ? s : `${s} – ${e}`
}

function eventDateRangeLabel(ev) {
    return `${shortDate(ev.start)} ${nlTime(ev.start)}`
}

function eventLabel(ev) {
    return ev.name || 'Afspraak'
}

function ticketDateRangeLabel(t) {
    const s = shortDate(t.created_at)
    return t.closed_on ? `${s} – ${shortDate(t.closed_on)}` : `${s} – heden`
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

function soTooltipHtml(chip) {
    const so = chip.so
    const title = escapeHtml(`#${so.id}${so.description ? ' ' + so.description : ''}`)
    const users = so.executing_users.map(u => escapeHtml(u.name)).join(', ')
    return `<div class="text-xs space-y-0.5">
        <div class="font-semibold">${title}</div>
        <div>${escapeHtml(soDateRangeLabel(chip.span))}</div>
        ${so.stage ? `<div>${escapeHtml(so.stage.name)}</div>` : ''}
        ${users ? `<div>${users}</div>` : ''}
    </div>`
}

function evTooltipHtml(chip) {
    const ev = chip.ev
    const users = ev.executing_users.map(u => escapeHtml(u.name)).join(', ')
    return `<div class="text-xs space-y-0.5">
        <div class="font-semibold">${escapeHtml(eventLabel(ev))}</div>
        <div>${escapeHtml(eventDateRangeLabel(ev))}</div>
        ${users ? `<div>${users}</div>` : ''}
    </div>`
}

function ticketTooltipHtml(chip) {
    const t = chip.t
    return `<div class="text-xs space-y-0.5">
        <div class="font-semibold">${escapeHtml(t.subject || 'Storing')}</div>
        <div>${escapeHtml(ticketDateRangeLabel(t))}</div>
        <div>${escapeHtml(t.status)} · Prioriteit: ${escapeHtml(t.priority)}</div>
    </div>`
}

// Converts a chip's forced min-width (px) into an equivalent percentage buffer (using the
// container's estimated pixel width) so temporally-close chips don't visually overlap despite
// not overlapping in time. Packing happens in pct-space (not time-space) since the axis mapping
// above isn't linear in time, but pct-to-pixel always is.
function packLanesWithMinWidth(entries, minWidthPx) {
    const bufferPct = (minWidthPx / laneAreaPxEstimate.value) * 100
    const converted = entries.map(e => ({ start: pct(e.start), end: pct(e.end) + bufferPct }))
    return packLanes(converted)
}

// --- Lane packing: places overlapping-in-time items into separate rows,
// non-overlapping items share a lane so the chart doesn't waste vertical space.
function packLanes(entries) {
    const indices = entries.map((_, i) => i).sort((a, b) => entries[a].start - entries[b].start)
    const laneEnds = []
    const lanes = new Array(entries.length).fill(0)
    for (const i of indices) {
        const { start, end } = entries[i]
        let lane = laneEnds.findIndex(e => e <= start)
        if (lane === -1) {
            lane = laneEnds.length
            laneEnds.push(end)
        } else {
            laneEnds[lane] = end
        }
        lanes[i] = lane
    }
    return { lanes, laneCount: Math.max(1, laneEnds.length) }
}

const soChips = computed(() => sortedServiceOrders.value.map(so => {
    const span = soSpan(so)
    const start = span ? new Date(span.start) : new Date(so.created_at)
    const end = span ? new Date(span.end) : new Date(start.getTime() + 3600 * 1000)
    return { so, span, start, end }
}))
const soPacked = computed(() => packLanesWithMinWidth(soChips.value, 130))

const evChips = computed(() => sortedEvents.value.map(ev => ({
    ev,
    start: new Date(ev.start),
    end: new Date(ev.end),
})))
const evPacked = computed(() => packLanesWithMinWidth(evChips.value, 120))

const ticketChips = computed(() => sortedTickets.value.map(t => {
    const start = new Date(t.created_at)
    const rawEnd = t.closed_on ? new Date(t.closed_on) : new Date()
    return { t, start, end: rawEnd < start ? start : rawEnd }
}))
const ticketPacked = computed(() => packLanesWithMinWidth(ticketChips.value, 120))

const msEntries = computed(() => milestones.value.map(ms => {
    const raw = ms.projected_date || ms.actual_date || new Date().toISOString()
    const d = new Date(raw)
    const pad = (MILESTONE_MIN_GAP_DAYS * 86400000) / 2
    return { start: new Date(d.getTime() - pad), end: new Date(d.getTime() + pad) }
}))
const msPacked = computed(() => packLanes(msEntries.value))

function soChipStyle(chip, lane) {
    const leftPct = pct(chip.start)
    const rightPct = pct(chip.end)
    const widthPct = Math.max(0.5, rightPct - leftPct)
    const closed = chip.so.is_closed
    return {
        left: leftPct + '%',
        width: `max(130px, ${widthPct}%)`,
        top: (lane * LANE_HEIGHT.value + 4) + 'px',
        height: (LANE_HEIGHT.value - 8) + 'px',
        backgroundColor: closed ? 'color-mix(in srgb, #16a34a 12%, white)' : 'color-mix(in srgb, #3b82f6 10%, white)',
        borderColor: closed ? 'color-mix(in srgb, #16a34a 45%, white)' : 'color-mix(in srgb, #3b82f6 45%, white)',
    }
}

function evChipStyle(chip, lane) {
    const leftPct = pct(chip.ev.start)
    const rightPct = pct(chip.ev.end)
    const widthPct = Math.max(0.5, rightPct - leftPct)
    const color = chip.ev.color || '#a855f7' // purple-500 fallback for events with no type color
    return {
        left: leftPct + '%',
        width: `max(120px, ${widthPct}%)`,
        top: (lane * LANE_HEIGHT.value + 4) + 'px',
        height: (LANE_HEIGHT.value - 8) + 'px',
        backgroundColor: `color-mix(in srgb, ${color} 12%, white)`,
        borderColor: `color-mix(in srgb, ${color} 45%, white)`,
    }
}

function ticketChipStyle(chip, lane) {
    const leftPct = pct(chip.start)
    const rightPct = pct(chip.end)
    const widthPct = Math.max(0.5, rightPct - leftPct)
    const closed = chip.t.status === 'Gesloten'
    return {
        left: leftPct + '%',
        width: `max(120px, ${widthPct}%)`,
        top: (lane * LANE_HEIGHT.value + 4) + 'px',
        height: (LANE_HEIGHT.value - 8) + 'px',
        backgroundColor: closed ? 'color-mix(in srgb, #6b7280 12%, white)' : 'color-mix(in srgb, #f97316 12%, white)',
        borderColor: closed ? 'color-mix(in srgb, #6b7280 45%, white)' : 'color-mix(in srgb, #f97316 45%, white)',
    }
}

const todayPct = computed(() => {
    const today = new Date()
    if (today < spanStart.value || today > spanEnd.value) return null
    return pct(today)
})

const todayLabel = computed(() => shortDate(new Date()))

const projectBoundMarks = computed(() => {
    const marks = []
    if (props.projectStartDate) marks.push({ key: 'start', label: 'Start', date: props.projectStartDate })
    if (props.projectEndDate) marks.push({ key: 'end', label: 'Einde', date: props.projectEndDate })
    return marks
})

function milestoneColor(ms) {
    if (ms.actual_date) return 'bg-green-600'
    if (!ms.projected_date) return 'bg-gray-400'
    const today = new Date().toISOString().substring(0, 10)
    if (ms.projected_date < today) return 'bg-red-500'
    return 'bg-blue-600'
}

// --- Selection / drawer ---

const selected = ref({ type: null, item: null })
const drawerOpen = ref(false)

function toggleSelect(type, item) {
    if (drawerOpen.value && selected.value.type === type && selected.value.item.id === item.id) {
        drawerOpen.value = false
        return
    }
    selected.value = { type, item }
    drawerOpen.value = true
}

const drawerTitle = computed(() => {
    if (selected.value.type === 'milestone') return selected.value.item.title
    if (selected.value.type === 'serviceorder') {
        const description = selected.value.item.description ? ` ${selected.value.item.description}` : ''
        return `#${selected.value.item.id}${description}`
    }
    if (selected.value.type === 'event') return eventLabel(selected.value.item)
    if (selected.value.type === 'ticket') return selected.value.item.subject || 'Storing'
    return ''
})
</script>

<style scoped>
/* All scroll/zoom behavior below is CSS-only — no JS scroll manipulation, no snapping, the
   user's own drag/wheel/scrollbar input is the only thing that ever moves this. */
.tl-scroller {
    scroll-behavior: smooth;
}

/* Dragging the zoom slider changes min-width reactively; this transition is what makes the
   expansion read as a fluid horizontal zoom instead of a snap-to-new-size jump. Everything
   inside (gridlines, chips, markers) is positioned by percentage, so it scales continuously
   along with it for free. */
.tl-zoomable {
    transition: min-width 120ms ease-out;
}

/* Small buffer only — the sidebar is no longer a descendant of .tl-scroller, so there's nothing
   of its own overlapping the scroller's content to account for here. */
#tl-today {
    scroll-margin-left: 40px;
}

/* Fixed sidebar — a genuinely separate element from the scrolling canvas, not sticky-positioned
   inside it, so there's no scroll-context edge case for it to fall into. */
.tl-sidebar {
    background: white;
    border-right: 1px solid rgb(229 231 235); /* gray-200 */
}

:global(.dark) .tl-sidebar {
    background: rgb(15 23 42); /* slate-900, matches BoxComponent's dark surface */
    border-right-color: rgb(51 65 85); /* slate-700 */
}

/* Fixed-height gap row between groups, giving the sidebar a light divider line between sections. */
.tl-sidebar-gap {
    height: 0.75rem;
}

.tl-sidebar-divider {
    border-top: 1px solid rgb(229 231 235); /* gray-200 */
}

:global(.dark) .tl-sidebar-divider {
    border-top-color: rgb(51 65 85); /* slate-700 */
}

/*
    Two independent zoom axes, each wired to its own slider's v-model in JS (unavoidable — a
    <input type=range> needs a bound value), but what actually PAINTS in response is CSS only:

    - Horizontal slider -> zoomColPx -> containerMinWidthPx (time-axis column width). No content
      visibility decision is made from this in JS.
    - Vertical slider -> LANE_HEIGHT -> each chip's own rendered height (a genuine JS-necessary
      number, since absolutely-positioned lane packing to avoid overlapping chips can't be done
      in CSS). Whether a chip then shows its compact or detailed content is decided entirely by
      a CSS container query keyed to that chip's own resulting height — JS never branches on it.
*/
.tl-chip {
    container-type: size;
    /* No overflow:hidden here — verified it silently breaks position:sticky on the content
       wrapper inside (an overflow value other than visible on an ancestor changes what a sticky
       descendant sticks relative to). Content is kept in-bounds via .tl-chip-content's max-width
       and each inner element's own truncate/overflow-hidden instead. */
}

/* Sticky content wrapper: stays in view while its chip is only partially scrolled into the
   viewport, up to the chip's own edges. display:inline-block is required — verified block
   silently breaks position:sticky in this browser. A plain px max-width keeps text truncating
   correctly; a cqw/percentage width was tried and also breaks stickiness (likely a circular
   dependency with the chip's own container-type), so it's deliberately a fixed px value.
   overflow:hidden here is safe (verified) — it's a sticky element's OWN overflow that's fine;
   it's only an ANCESTOR's overflow that breaks stickiness. This clips content that needs more
   vertical room than the chip's current height (controlled by the separate vertical zoom
   slider) provides, instead of letting it visibly spill past the chip's border. */
.tl-chip-content {
    left: 0;
    max-width: 220px;
    height: 100%;
    box-sizing: border-box;
}

/* Clip vertically to the parent's height (set by the vertical zoom slider) — no fade, no JS
   line-counting, just a hard clip at the box's own edge. Column by default (title/date/avatars
   stacked, same look as the old plain block flow); the container query below switches to a row
   instead once the chip is too short to stack without clipping the avatar row. */
.tl-chip-clamp {
    max-height: 100%;
    width: 100%;
    max-width: 100cqw;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.tl-detail-only {
    display: none;
}

@container (min-height: 78px) {
    .tl-compact-only {
        display: none;
    }
    .tl-detail-only {
        display: revert;
    }
}

@container (max-height: 52px) {
    .tl-chip-clamp {
        flex-direction: row;
        align-items: center;
        gap: 0.375rem;
    }
    .tl-chip-title {
        min-width: 0;
        flex: 1 1 auto;
    }
    .tl-chip-daterange {
        min-width: 0;
        flex: 0 1 auto;
    }
    .tl-compact-only {
        flex: 0 0 auto;
        margin-top: 0;
    }
}

/* Native vertical range input via writing-mode — no transform/JS orientation hack. */
.tl-vslider-wrap {
    display: flex;
    align-self: stretch;
    background: white;
    padding: 0.25rem 0;
}

:global(.dark) .tl-vslider-wrap {
    background: rgb(15 23 42); /* slate-900, matches .tl-sticky-col */
}

.tl-vslider {
    writing-mode: vertical-lr;
    direction: rtl;
    width: 1.1rem;
    /* .tl-vslider-wrap is display:flex, so this lone flex item stretches to fill it by
       default (align-items:stretch) — no percentage height needed, which is what was
       resolving against the viewport instead of the actual flex parent. */
}
</style>
