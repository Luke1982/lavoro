<template>
    <div ref="rootEl" class="flex flex-col h-full  dark:bg-slate-900 text-gray-900 dark:text-slate-100"
        @pointermove="onWindowPointerMove" @pointerup="onWindowPointerUp">
        <!-- Top toolbar -->
        <div
            class="relative z-30 flex items-center px-4 py-3 border-b border-gray-200 dark:border-slate-800 gap-3 flex-wrap  dark:bg-slate-900">
            <h1 class="text-xl font-bold pr-4">Planning</h1>
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="goToday">Vandaag</button>
            <button v-if="showPrevButton"
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(-1)" :aria-label="plannerView === 'day' ? 'Vorige dag' : 'Vorige week'">
                <ChevronLeftIcon class="size-4" />
            </button>
            <button v-if="showNextButton"
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(1)" :aria-label="plannerView === 'day' ? 'Volgende dag' : 'Volgende week'">
                <ChevronRightIcon class="size-4" />
            </button>
            <div class="font-semibold text-sm">{{ periodTitle }}</div>

            <!-- View mode toggle -->
            <div class="flex rounded-md border border-gray-300 dark:border-slate-700 overflow-hidden text-sm">
                <button class="px-3 py-1.5 transition-colors"
                    :class="plannerView === 'week' ? 'bg-lavoro-blue text-white' : 'hover:bg-gray-50 dark:hover:bg-slate-800'"
                    @click="setPlannerView('week')">Week</button>
                <button class="px-3 py-1.5 border-l border-gray-300 dark:border-slate-700 transition-colors"
                    :class="plannerView === 'day' ? 'bg-lavoro-blue text-white' : 'hover:bg-gray-50 dark:hover:bg-slate-800'"
                    @click="setPlannerView('day')">Dag</button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <button v-if="hasPermission('event.export')"
                    class="flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                    @click="exportDrawerOpen = true">
                    <ArrowDownTrayIcon class="size-4 shrink-0" />
                    Exporteren
                </button>
                <button v-if="allPingsArray.length > 0"
                    class="flex items-center gap-1.5 rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                    @click="mapModalOpen = true">
                    <MapIcon class="size-4 shrink-0" />
                    Monteurkaart
                </button>
                <template v-if="hasPermission('settings.update_default_planner_minutes')">
                    <label class="text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">Standaard min.</label>
                    <input type="number" v-model.number="plannerMinutes" min="15" max="1200" step="15"
                        class="w-25 rounded-md border border-gray-300 dark:border-slate-700 dark:bg-slate-800 text-sm px-2 py-1.5 text-center"
                        @blur="savePlannerMinutes" />
                </template>
                <SelectMenuComponent v-model="slotMinutes" :options="slotOptions" :icon="Squares2X2Icon">
                    <template #sr-label>Slotgrootte</template>
                </SelectMenuComponent>
                <label class="text-xs text-gray-500 dark:text-slate-400 ml-2">Dag</label>
                <select v-model.number="dayStartHour"
                    class="rounded-md border border-gray-300 dark:border-slate-700  dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 12" :key="h - 1" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option>
                </select>
                <span class="text-xs">tot</span>
                <select v-model.number="dayEndHour"
                    class="rounded-md border border-gray-300 dark:border-slate-700  dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 24" :key="h" :value="h">{{ String(h).padStart(2, '0') }}:00</option>
                </select>
            </div>
        </div>

        <!--
            Single native scroll container with CSS sticky panes (no JS scroll sync).
            Layout = two stacked rows inside one `overflow-auto` scroller:
              • header row  → sticky top  (frozen while scrolling down)
              • body row    → sidebar (sticky left) + time grid
            The top-left corner and the sidebar are `sticky left-0`; the header is `sticky top-0`.
            `w-max min-w-full` lets each row grow to its content width (so the grid scrolls
            horizontally) while still filling the viewport when it is wider than the content.
            `[overscroll-behavior:contain]` stops wheel deltas from chaining out (kills the
            old "scroll buffer" feel) and lets native drag-to-edge auto-scroll do its job.
        -->
        <div class="flex-1 min-h-0 overflow-auto relative [overscroll-behavior:contain]" ref="gridScrollRef"
            @dragleave="onGridDragLeave">
            <Transition enter-active-class="transition-opacity duration-150" enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
                <div v-if="eventsLoading"
                    class="absolute inset-0 z-50 flex items-start justify-center pt-16 pointer-events-none">
                    <div class="flex items-center gap-2 bg-white/80 dark:bg-slate-900/80 backdrop-blur-sm rounded-full px-4 py-2 shadow text-xs text-gray-500 dark:text-slate-400">
                        <svg class="animate-spin size-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        Laden…
                    </div>
                </div>
            </Transition>

            <!-- Sticky header row: sidebar label + day/time bars -->
            <div class="sticky top-0 z-30 flex w-max min-w-full">
                <div class="sticky left-0 z-10 w-64 shrink-0 bg-white dark:bg-slate-900 border-r border-b border-gray-200 dark:border-slate-800 px-3 flex flex-col justify-between gap-1 pb-2 pt-2 text-xs text-gray-500 dark:text-slate-400"
                    :style="{ height: headerHeight + 'px' }">
                    <!-- Group filter -->
                    <div v-if="groups.length" class="flex items-center gap-1 flex-wrap min-h-0">
                        <span class="text-[10px] text-gray-400 shrink-0">Groepen:</span>
                        <button v-for="group in groups" :key="group.id"
                            class="h-4 px-1.5 rounded text-[10px] font-medium border transition-colors truncate max-w-[80px]"
                            :class="selectedGroupIds.includes(group.id)
                                ? 'text-white border-transparent'
                                : 'text-gray-500 border-gray-200 dark:border-slate-700 hover:border-gray-400'"
                            :style="selectedGroupIds.includes(group.id) ? { background: group.color, borderColor: group.color } : {}"
                            :title="group.name" @click="toggleGroupFilter(group.id)">
                            {{ group.name }}
                        </button>
                        <button v-if="selectedGroupIds.length"
                            class="text-[10px] text-gray-400 hover:text-gray-600 shrink-0" title="Alle groepen tonen"
                            @click="selectedGroupIds = []">✕</button>
                    </div>
                    <!-- Monteurs count + collapse -->
                    <div class="flex items-center justify-between">
                        <span>Monteurs ({{ visibleUsers.length }})</span>
                        <button v-if="visibleUsers.length" @click="toggleAllRows"
                            class="flex items-center gap-0.5 rounded px-1.5 py-1 hover:bg-gray-100 dark:hover:bg-slate-800 font-medium">
                            <ChevronDownIcon v-if="allRowsCollapsed" class="size-3.5" />
                            <ChevronRightIcon v-else class="size-3.5" />
                            {{ allRowsCollapsed ? 'Alles uitklappen' : 'Alles inklappen' }}
                        </button>
                    </div>
                </div>
                <div class="flex-1 bg-white dark:bg-slate-900" :style="{ minWidth: gridMinWidth + 'px' }">
                    <div class="grid border-b border-gray-200 dark:border-slate-800"
                        :style="{ gridTemplateColumns: dayGridTemplate, minWidth: gridMinWidth + 'px', height: dayHeaderHeight + 'px' }">
                        <div v-for="day in weekDays" :key="'dh-' + day.iso"
                            class="px-3 flex items-center justify-center text-sm font-semibold border-l border-gray-200 dark:border-slate-800 first:border-l-0">
                            <span class="uppercase">{{ dayLabel(day.date) }}</span>
                            <span v-if="isToday(day.date)"
                                class="inline-block ml-2 rounded-full bg-blue-600 text-white text-xs px-2 py-0.5">
                                {{ String(day.date.getDate()).padStart(2, '0') }}
                            </span>
                        </div>
                    </div>
                    <div class="grid border-b border-gray-200 dark:border-slate-800  dark:bg-slate-900 text-[11px] text-gray-500 dark:text-slate-400"
                        :style="{ gridTemplateColumns: dayGridTemplate, minWidth: gridMinWidth + 'px', height: hourHeaderHeight + 'px' }">
                        <div v-for="day in weekDays" :key="'hh-' + day.iso"
                            class="grid border-l border-gray-200 dark:border-slate-800 first:border-l-0 relative"
                            :style="{ gridTemplateColumns: `repeat(${hourCount}, minmax(0, 1fr))` }">
                            <div v-for="h in hourCount" :key="'hl-' + day.iso + '-' + h"
                                class="border-l border-gray-100 dark:border-slate-800/70 first:border-l-0 flex items-end pb-1 pl-1">
                                {{ String(dayStartHour + h - 1).padStart(2, '0') }}:00
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Body row: frozen resource sidebar + scrolling time grid -->
            <div class="flex w-max min-w-full">
                <!-- Resource sidebar (frozen left via sticky) -->
                <div class="sticky left-0 z-20 w-64 shrink-0 border-r border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900">
                    <!-- Group bar overlay: one thin bar per user per group, stacked left to right -->
                    <div class="absolute top-0 left-0 pointer-events-none"
                        style="width: 0; overflow: visible; z-index: 1;">
                        <div v-for="(bar, i) in groupBars" :key="i" class="absolute rounded-sm"
                            :style="{ top: bar.top + 'px', height: bar.height + 'px', left: bar.x + 'px', width: BAR_W + 'px', background: bar.color }" />
                    </div>
                    <div v-if="showProjects && allDayLaneHeight" :style="{ height: allDayLaneHeight + 'px' }"
                        class="relative border-b border-gray-200 dark:border-slate-800 text-xs font-medium text-gray-500 dark:text-slate-400 bg-gray-50/40 dark:bg-slate-800/40 transition-[height] duration-200 ease-in-out">
                        <button
                            class="absolute top-1.5 left-1.5 rounded p-0.5 hover:bg-gray-200 dark:hover:bg-slate-700"
                            @click="toggleAllDay"
                            :aria-label="allDayState === 'closed' ? 'Projecten uitklappen' : allDayState === 'partial' ? 'Alle projecten tonen' : 'Projecten inklappen'">
                            <ChevronRightIcon v-if="allDayState === 'closed'" class="size-4" />
                            <ChevronDownIcon v-else-if="allDayState === 'partial'" class="size-4" />
                            <ChevronDoubleDownIcon v-else class="size-4" />
                        </button>
                        <span class="block pl-9 pt-2">Projecten ({{ allDay.tracks.length }})</span>
                    </div>
                    <div v-for="(user, idx) in visibleUsers" :key="user.id"
                        :style="{ height: rowHeightFor(user.id) + 'px' }"
                        class="relative flex flex-col pl-9 pr-2 border-b border-gray-100 dark:border-slate-800 transition-[height] duration-200 ease-in-out overflow-hidden"
                        :class="[
                            idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : '',
                            latestPings[user.id] && !collapsedUsers.has(user.id) ? 'pt-2 pb-2 gap-1' : 'justify-center'
                        ]">
                        <button
                            class="absolute top-1.5 left-1.5 rounded p-0.5 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-400"
                            @click="toggleUserRow(user.id)"
                            :aria-label="collapsedUsers.has(user.id) ? 'Rij uitklappen' : 'Rij inklappen'">
                            <ChevronDownIcon v-if="!collapsedUsers.has(user.id)" class="size-4" />
                            <ChevronRightIcon v-else class="size-4" />
                        </button>
                        <div class="flex items-center gap-2 shrink-0">
                            <div class="rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center overflow-hidden text-xs font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 transition-all duration-200 ease-in-out"
                                :class="collapsedUsers.has(user.id) ? 'h-7 w-7' : 'h-10 w-10'">
                                <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full"
                                    :alt="user.name" />
                                <span v-else>{{ initials(user.name) }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold truncate">{{ user.name }}</div>
                                <div v-if="!collapsedUsers.has(user.id)"
                                    class="text-xs text-gray-500 dark:text-slate-400">
                                    {{ userHoursLabel(user.id) }}</div>
                            </div>
                        </div>
                        <div v-if="!collapsedUsers.has(user.id) && latestPings[user.id]"
                            class="flex flex-col flex-1 min-h-0">
                            <div class="flex items-center justify-between mb-0.5 shrink-0">
                                <span class="text-[10px] text-gray-400 dark:text-slate-500 font-medium">Locatie</span>
                                <button @click.stop="toggleMapExpand(user.id)"
                                    class="rounded p-0.5 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-400"
                                    :title="mapExpandedUsers.has(user.id) ? 'Kaart verkleinen' : 'Kaart vergroten'">
                                    <ArrowsPointingInIcon v-if="mapExpandedUsers.has(user.id)" class="size-3" />
                                    <ArrowsPointingOutIcon v-else class="size-3" />
                                </button>
                            </div>
                            <div class="flex-1 min-h-0">
                                <TechnicianMiniMap :key="mapExpandedUsers.has(user.id) ? 'exp' : 'mini'"
                                    :ping="latestPings[user.id]" />
                            </div>
                        </div>
                    </div>
                    <div v-if="visibleUsers.length === 0" class="p-4 text-xs text-gray-500 dark:text-slate-400">
                        Geen inplanbare monteurs.
                        Schakel "Inplanbaar" in op een gebruiker via Gebruikers.
                    </div>
                </div>

                <!-- Time grid (scrolls with the header; bodyRef is the positioning context for overlays) -->
                <div class="flex-1 relative" :style="{ minWidth: gridMinWidth + 'px' }" ref="bodyRef">
                        <!-- All-day project band -->
                        <div v-if="showProjects && allDayLaneHeight"
                            class="relative border-b border-gray-200 dark:border-slate-800 bg-gray-50/30 dark:bg-slate-900/30 transition-[height] duration-200 ease-in-out"
                            :style="{ height: allDayLaneHeight + 'px', minWidth: gridMinWidth + 'px' }">
                            <!-- Full project stack; scrolls with the grid (labels/hanging-SOs stay sticky to the left edge). -->
                            <div class="relative"
                                :style="{ height: allDayContentHeight + 'px', minWidth: gridMinWidth + 'px' }">
                                <!-- Day gridlines -->
                                <div class="absolute inset-0 grid pointer-events-none"
                                    :style="{ gridTemplateColumns: dayGridTemplate }">
                                    <div v-for="day in weekDays" :key="'adg-' + day.iso"
                                        class="border-l border-gray-100 dark:border-slate-800/60 first:border-l-0" />
                                </div>

                                <template v-for="track in visibleTracks" :key="'track-' + track.id">
                                    <!-- Project bar (background spans full range; label sticks to the viewport) -->
                                    <div class="absolute rounded-md border bg-indigo-50 dark:bg-indigo-950/50 border-indigo-300 dark:border-indigo-800"
                                        :style="{ left: track.leftPct + '%', width: track.widthPct + '%', top: track.top + 'px', height: PROJECT_BAR_H + 'px' }"
                                        :title="`${track.title}${track.customerName ? ' — ' + track.customerName : ''}`">
                                        <!-- Label sticks to the left visible grid edge (just past the frozen sidebar) while scrolling horizontally. -->
                                        <div class="sticky left-64 inline-block max-w-full px-2 py-1">
                                            <div
                                                class="text-xs font-semibold leading-tight truncate text-indigo-900 dark:text-indigo-200">
                                                {{ track.continuesLeft ? '◂ ' : '' }}{{ track.title }}{{
                                                    track.continuesRight ?
                                                        ' ▸' : '' }}
                                            </div>
                                            <div v-if="track.customerName"
                                                class="text-[10px] leading-tight truncate text-indigo-600/80 dark:text-indigo-300/80">
                                                {{ track.customerName }}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Unplanned service orders hanging below the project (side by side, wrapping) -->
                                    <div v-if="track.serviceOrders.length" class="absolute"
                                        :style="{ left: track.leftPct + '%', width: track.widthPct + '%', top: track.hangingTop + 'px' }">
                                        <!-- The whole group sticks under its project's visible portion while scrolling horizontally. -->
                                        <div class="sticky left-64 inline-flex flex-wrap content-start gap-1"
                                            :style="{ maxWidth: '100%' }">
                                            <div v-for="so in track.serviceOrders" :key="'pso-' + so.id"
                                                draggable="true" @dragstart="onProjectServiceOrderDragStart($event, so)"
                                                @dragend="onProjectServiceOrderDragEnd"
                                                :style="{ height: SO_CARD_H + 'px', width: SO_CARD_W + 'px' }"
                                                class="group cursor-grab active:cursor-grabbing select-none flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-slate-700  dark:bg-slate-800 px-2 shadow-sm hover:border-lavoro-blue transition"
                                                :title="`Sleep naar de planning — werkbon #${so.id}`">
                                                <ArrowsRightLeftIcon
                                                    class="size-3 shrink-0 text-gray-400 dark:text-slate-500" />
                                                <span class="text-xs font-semibold shrink-0">#{{ so.id }}</span>
                                                <span class="text-[11px] text-gray-500 dark:text-slate-400 truncate">{{
                                                    so.description || 'Werkbon' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Locked-event group overlays -->
                        <div v-for="ov in lockedGroupOverlays" :key="'lock-ov-' + ov.id"
                            class="absolute pointer-events-none z-[6] rounded-xl border-2 border-dashed" :style="{
                                top: (ov.top - 5 - ov.nestingLevel * 5) + 'px',
                                height: (ov.height + 10 + ov.nestingLevel * 10) + 'px',
                                left: `calc(${ov.leftPct}% - ${5 + ov.nestingLevel * 5}px)`,
                                width: `calc(${ov.widthPct}% + ${10 + ov.nestingLevel * 10}px)`,
                                borderColor: ov.color,
                                opacity: 0.5,
                            }" />

                        <div v-for="(user, idx) in visibleUsers" :key="'row-' + user.id"
                            class="grid relative transition-[height] duration-200 ease-in-out"
                            :style="{ gridTemplateColumns: dayGridTemplate, height: rowHeightFor(user.id) + 'px' }"
                            :class="idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : ''">

                            <div v-for="day in weekDays" :key="'cell-' + user.id + '-' + day.iso"
                                class="relative border-l border-b border-gray-200 dark:border-slate-800 first:border-l-0"
                                :data-user-id="user.id" :data-day-iso="day.iso"
                                @pointerdown="onCellPointerDown($event, user, day)"
                                @dragover.prevent="onDragOver($event, user, day)"
                                @drop.prevent="onExternalDrop($event, user, day)">
                                <!-- Hour grid lines -->
                                <div class="absolute inset-0 grid pointer-events-none"
                                    :style="{ gridTemplateColumns: `repeat(${hourCount}, minmax(0, 1fr))` }">
                                    <div v-for="h in hourCount" :key="'hgl-' + user.id + '-' + day.iso + '-' + h"
                                        class="border-l border-gray-100 dark:border-slate-800/60 first:border-l-0" />
                                </div>

                                <!-- Unavailability overlays -->
                                <template v-for="(overlay, oi) in getBlockOverlays(user.id, day.iso)"
                                    :key="'block-' + user.id + '-' + day.iso + '-' + oi">
                                    <div class="absolute top-0 bottom-0 pointer-events-none z-[5] flex items-center overflow-hidden"
                                        :style="{
                                            left: overlay.left + '%',
                                            width: overlay.width + '%',
                                            background: 'repeating-linear-gradient(-45deg, transparent, transparent 4px, rgba(156,163,175,0.35) 4px, rgba(156,163,175,0.35) 8px)',
                                        }">
                                        <span
                                            class="text-[10px] font-medium text-gray-500 dark:text-gray-400 px-1.5 truncate select-none whitespace-nowrap">
                                            {{ overlay.label || 'Niet beschikbaar' }}
                                        </span>
                                    </div>
                                </template>

                                <!-- Now indicator -->
                                <div v-if="isToday(day.date) && nowOffsetPercent !== null"
                                    class="absolute top-0 bottom-0 w-px bg-red-500/70 pointer-events-none z-10"
                                    :style="{ left: nowOffsetPercent + '%' }">
                                    <div class="absolute -top-1 -translate-x-1/2 size-2 rounded-full bg-red-500"></div>
                                </div>

                                <!-- Events for this user/day -->
                                <PlannerEvent v-for="ev in eventsFor(user.id, day.iso)" :key="ev.id + '-' + user.id"
                                    :event="ev" :user-id="user.id" :day="day" :slot-minutes="slotMinutes"
                                    :day-start-hour="dayStartHour" :day-end-hour="dayEndHour"
                                    :row-height="rowHeightFor(user.id)" :event-padding-y="paddingYFor(user.id)"
                                    :is-locked="ev.executing_user_ids.length > 1"
                                    :is-being-dragged="drag.eventId === ev.id"
                                    :user-roles="userRoles"
                                    @click="handleEventClick(ev)"
                                    @contextmenu="onEventContextMenu($event, ev)"
                                    @pointerdown-on-event="onEventPointerDown($event, ev, user)"
                                    @pointerdown-on-resize="onResizePointerDown($event, ev, user, $event.edge)"
                                    @changed="fetchEvents()" />

                                <!-- Live selection rectangle (click-drag-create) -->
                                <div v-if="selectRect && selectRect.userId === user.id && selectRect.dayIso === day.iso"
                                    class="absolute top-1 bottom-1 bg-blue-500/30 border-2 border-dashed border-blue-500 rounded-md pointer-events-none"
                                    :style="{ left: selectRect.left + '%', width: selectRect.width + '%' }">
                                    <div
                                        class="absolute -top-5 left-1 text-[10px] font-semibold text-blue-700 dark:text-blue-300  dark:bg-slate-900 rounded px-1">
                                        {{ formatTimeFromMinutes(selectRect.startMinutes) }} –
                                        {{ formatTimeFromMinutes(selectRect.endMinutes) }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Drag ghost (floats above grid) -->
                        <div v-if="dragGhost"
                            class="absolute pointer-events-none rounded-md border-2 border-dashed /90 dark:bg-slate-800/90 shadow-lg z-30 px-2 py-1 text-xs"
                            :style="dragGhost.style">
                            <div class="font-semibold truncate">{{ dragGhost.title }}</div>
                            <div class="text-[11px]">
                                {{ formatTimeFromDate(dragGhost.start) }} – {{ formatTimeFromDate(dragGhost.end) }}
                            </div>
                            <div v-if="dragGhost.userName" class="text-[10px] opacity-75">→ {{ dragGhost.userName }}
                            </div>
                        </div>
                </div>
            </div>
        </div>

        <!-- Create/edit modal -->
        <EventEditModal v-if="modalOpen" :event-types="eventTypes" :event-statusses="eventStatusses"
            :all-customers="allCustomers" :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :all-users="allUsers" :user-roles="userRoles" :initial="modalInitial"
            :editing-existing="editingExistingEvent" @close="closeModal" @saved="onSaved" />

        <!-- All-technicians map modal -->
        <ModalDialog :open="mapModalOpen" @update:open="mapModalOpen = $event" title="Monteurlocaties (laatste 8u)"
            max-width-class="sm:max-w-[90vw]">
            <div style="height: 80vh; width: 90vw;" class="relative">
                <TechnicianMapCanvas v-if="mapModalOpen" :pings="allPingsArray" :init-delay="350" />
            </div>
        </ModalDialog>

        <PlannerExportDrawer v-model="exportDrawerOpen" :plannable-users="plannableUsers" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronLeftIcon, ChevronRightIcon, ChevronDownIcon, ChevronDoubleDownIcon, Squares2X2Icon, ArrowsRightLeftIcon, ArrowsPointingOutIcon, ArrowsPointingInIcon, MapIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { initials, formatLocalDateAsISO, formatUtcDatetime, nlTime, hasPermission } from '@/Utilities/Utilities'
import { setServiceOrderDragData } from '@/Utilities/plannerDnd'
import dayjs from '@/Utilities/dayjs'
import { usePlannerEvents } from '@/Composables/usePlannerEvents'
import PlannerEvent from '@/Components/Planner/PlannerEvent.vue'
import EventEditModal from '@/Components/Planner/EventEditModal.vue'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import TechnicianMiniMap from '@/Components/Planner/TechnicianMiniMap.vue'
import TechnicianMapCanvas from '@/Components/Planner/TechnicianMapCanvas.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import PlannerExportDrawer from '@/Components/Planner/PlannerExportDrawer.vue'
import ContextMenu from '@imengyu/vue3-context-menu'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    plannableUsers: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    /** Projects rendered as all-day bars in the row above the resource lanes */
    projects: { type: Array, default: () => [] },
    /** Default slot snap in minutes */
    defaultSlotMinutes: { type: Number, default: 30 },
    /** Default visible day hours */
    defaultDayStartHour: { type: Number, default: 7 },
    defaultDayEndHour: { type: Number, default: 18 },
    /** Row height in px (so we can keep rows equal as the screenshot requires) */
    rowHeight: { type: Number, default: 135 },
    /** Vertical padding around event cards within each lane */
    eventPaddingY: { type: Number, default: 14 },
    /** Default duration in minutes for new events created by drop or single click */
    defaultPlannerMinutes: { type: Number, default: 120 },
    /** Plan groups for sorting and color bars */
    groups: { type: Array, default: () => [] },
    /** Latest location ping per user_id, keyed by user_id */
    latestPings: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['service-order-planned', 'service-order-unplanned'])

const page = usePage()

const authUserId = computed(() => page.props.auth?.user?.id ?? null)

function canEditEvent(ev) {
    if (hasPermission('event.update_others')) return true
    return hasPermission('event.update') && ev.executing_user_ids.includes(authUserId.value)
}

function canDeleteEvent(ev) {
    if (hasPermission('event.delete_others')) return true
    return hasPermission('event.delete') && ev.executing_user_ids.includes(authUserId.value)
}

const visibleUsers = computed(() => {
    const base = hasPermission('event.see_all')
        ? props.plannableUsers
        : props.plannableUsers.filter(u => u.id === authUserId.value)

    const filtered = selectedGroupIds.value.length
        ? base.filter(u => {
            if (selectedGroupIds.value.includes('ungrouped') && (!u.plan_group_ids || u.plan_group_ids.length === 0)) return true
            return u.plan_group_ids?.some(gid => selectedGroupIds.value.includes(gid))
        })
        : base

    return [...filtered].sort((a, b) => a.name.localeCompare(b.name))
})

const BAR_W = 4
const BAR_GAP = 1

const groupBars = computed(() => {
    const users = visibleUsers.value
    if (!users.length) return []

    const groupMap = Object.fromEntries(props.groups.map(g => [g.id, g]))
    const bars = []
    let top = allDayLaneHeight.value

    for (const user of users) {
        const rowH = rowHeightFor(user.id)
            ; (user.plan_group_ids ?? []).forEach((gid, i) => {
                const group = groupMap[gid]
                if (!group) return
                bars.push({ top, height: rowH, color: group.color, x: i * (BAR_W + BAR_GAP) })
            })
        top += rowH
    }

    return bars
})

const showProjects = computed(() => hasPermission('project.read'))

const WEEK_STORAGE_KEY = 'lavoro_planner_week'
const VIEW_STORAGE_KEY = 'lavoro_planner_view'
const SLOT_STORAGE_KEY = 'lavoro_planner_slot_minutes'
const DAY_START_STORAGE_KEY = 'lavoro_planner_day_start'
const DAY_END_STORAGE_KEY = 'lavoro_planner_day_end'

function loadInt(key, fallback) {
    const v = parseInt(localStorage.getItem(key))
    return isNaN(v) ? fallback : v
}

const slotMinutes = ref(loadInt(SLOT_STORAGE_KEY, props.defaultSlotMinutes))
const dayStartHour = ref(loadInt(DAY_START_STORAGE_KEY, props.defaultDayStartHour))
const dayEndHour = ref(loadInt(DAY_END_STORAGE_KEY, props.defaultDayEndHour))

watch(slotMinutes, v => localStorage.setItem(SLOT_STORAGE_KEY, v))
watch(dayStartHour, v => localStorage.setItem(DAY_START_STORAGE_KEY, v))
watch(dayEndHour, v => localStorage.setItem(DAY_END_STORAGE_KEY, v))

function loadStoredWeekStart() {
    if (!hasPermission('events.see_beyond_current_week')) {
        return startOfWeek(new Date())
    }
    const stored = localStorage.getItem(WEEK_STORAGE_KEY)
    if (stored) {
        const date = dayjs(stored, 'YYYY-MM-DD', true)
        if (date.isValid()) return date.toDate()
    }
    return startOfWeek(new Date())
}

function loadStoredView() {
    const v = localStorage.getItem(VIEW_STORAGE_KEY)
    return v === 'day' ? 'day' : 'week'
}

const weekStart = ref(loadStoredWeekStart())
const plannerView = ref(loadStoredView())
const selectedGroupIds = ref([])

const current_iso_week_start = computed(() => dayjs().startOf('isoWeek'))

const showPrevButton = computed(() => {
    if (hasPermission('events.see_beyond_current_week')) return true
    if (plannerView.value !== 'day') return false
    return dayjs(weekStart.value).isAfter(current_iso_week_start.value, 'day')
})

const showNextButton = computed(() => {
    if (hasPermission('events.see_beyond_current_week')) return true
    if (plannerView.value !== 'day') return false
    return dayjs(weekStart.value).isBefore(current_iso_week_start.value.add(6, 'day'), 'day')
})

const rootEl = ref(null)

const viewDayCount = computed(() => plannerView.value === 'day' ? 1 : 7)
const { events, eventsLoading, fetchEvents, startPolling, stopPolling, resetFingerprint } = usePlannerEvents(
    weekStart,
    viewDayCount,
    () => !!drag.value.mode || modalOpen.value || !rootEl.value?.offsetParent,
)

const gridScrollRef = ref(null)
const bodyRef = ref(null)

const modalOpen = ref(false)
const editingExistingEvent = ref(false)
const modalInitial = ref(null)

const drag = ref({ eventId: null, mode: null })
const dragGhost = ref(null)
const selectRect = ref(null)
let suppressClickUntil = 0

const HOUR_PX_MIN = 60
const SLOT_PX_MIN = 56
const dayHeaderHeight = 44
const hourHeaderHeight = 44
const headerHeight = dayHeaderHeight + hourHeaderHeight

const MAP_DEFAULT_ROW_H = 220
const MAP_EXPANDED_ROW_H = 380

const slotOptions = [
    { value: 15, title: '15 min per slot', shortTitle: '15 min', description: 'Bredere kolommen, ideaal voor korte afspraken' },
    { value: 30, title: '30 min per slot', shortTitle: '30 min', description: 'Standaard slotgrootte' },
    { value: 60, title: '60 min per slot', shortTitle: '60 min', description: 'Compactere weergave, voor lange afspraken' },
]

const hourCount = computed(() => Math.max(1, dayEndHour.value - dayStartHour.value))
const slotsPerHour = computed(() => 60 / slotMinutes.value)
const hourWidthPx = computed(() => Math.max(HOUR_PX_MIN, slotsPerHour.value * SLOT_PX_MIN))
const dayWidthPx = computed(() => hourWidthPx.value * hourCount.value)
const gridMinWidth = computed(() => dayWidthPx.value * weekDays.value.length)
const dayGridTemplate = computed(() => `repeat(${weekDays.value.length}, minmax(${dayWidthPx.value}px, 1fr))`)

// --- All-day project band (row above the resource lanes) ---
const PROJECT_BAR_H = 38
const SO_CARD_H = 34
const SO_CARD_W = 150 // hanging service-order card width
const SO_GAP = 4
const TRACK_TOP_PAD = 6
const TRACK_BOTTOM_PAD = 10
const COLLAPSED_LANE_H = 32 // all-day lane height when collapsed
const COLLAPSED_ROW_H = 40 // resource row height when collapsed
const PROJECTS_VISIBLE_COUNT = 3 // projects rendered in the 'partial' state

// All-day project band visibility: 'closed' (just the header), 'partial' (first few projects) or 'full' (all projects).
// 'partial' simply renders fewer projects (no inner scroll) so the band stays part of the single native
// scroll and the project labels / hanging service-orders keep their native horizontal stickiness.
const allDayState = ref('partial')
const collapsedUsers = ref(new Set())
const mapExpandedUsers = ref(new Set())
const mapModalOpen = ref(false)
const exportDrawerOpen = ref(false)

const allPingsArray = computed(() =>
    Object.values(props.latestPings).filter(p => p.lat != null && p.lng != null)
)

/** One horizontal track per project, positioned within the visible week. */
const allDay = computed(() => {
    const dayCount = plannerView.value === 'day' ? 1 : 7
    const ws = dayjs(weekStart.value).startOf('day')
    const weekEnd = ws.add(dayCount, 'day') // exclusive: start of the day after the period
    const tracks = []
    let top = TRACK_TOP_PAD
    const sortedProjects = [...props.projects].sort((a, b) => {
        const aHas = (a.service_orders?.length ?? 0) > 0 ? 0 : 1
        const bHas = (b.service_orders?.length ?? 0) > 0 ? 0 : 1
        return aHas - bHas
    })
    for (const p of sortedProjects) {
        if (!p.start_date || !p.end_date) continue
        const start = dayjs(p.start_date).startOf('day')
        const endExclusive = dayjs(p.end_date).startOf('day').add(1, 'day') // through end of end_date (23:59)
        if (!endExclusive.isAfter(ws) || !start.isBefore(weekEnd)) continue // not visible this week
        const visibleStart = start.isAfter(ws) ? start : ws
        const visibleEnd = endExclusive.isBefore(weekEnd) ? endExclusive : weekEnd
        const startDay = Math.round(visibleStart.diff(ws, 'day', true))
        const endDayExclusive = Math.round(visibleEnd.diff(ws, 'day', true))
        const leftPct = (startDay / dayCount) * 100
        const widthPct = ((endDayExclusive - startDay) / dayCount) * 100
        const pixelWidth = (endDayExclusive - startDay) * dayWidthPx.value
        const serviceOrders = p.service_orders || []
        const perRow = Math.max(1, Math.floor((pixelWidth + SO_GAP) / (SO_CARD_W + SO_GAP)))
        const rows = serviceOrders.length ? Math.ceil(serviceOrders.length / perRow) : 0
        const hangingHeight = rows ? rows * (SO_CARD_H + SO_GAP) + SO_GAP : 0
        tracks.push({
            id: p.id,
            project: p,
            title: p.title || `Project #${p.id}`,
            customerName: p.customer?.name || '',
            leftPct,
            widthPct,
            top,
            hangingTop: top + PROJECT_BAR_H,
            serviceOrders,
            continuesLeft: start.isBefore(ws),
            continuesRight: endExclusive.isAfter(weekEnd),
        })
        top += PROJECT_BAR_H + hangingHeight + TRACK_BOTTOM_PAD
    }
    return { tracks, height: tracks.length ? top : 0 }
})

// Height that fits the first few projects; falls back to the full height when there are fewer.
const partialLaneHeight = computed(() => {
    const tracks = allDay.value.tracks
    if (tracks.length <= PROJECTS_VISIBLE_COUNT) return allDay.value.height
    return tracks[PROJECTS_VISIBLE_COUNT].top
})

// Effective lane height honoring the three-state toggle.
const allDayLaneHeight = computed(() => {
    if (!allDay.value.height) return 0
    if (allDayState.value === 'closed') return COLLAPSED_LANE_H
    if (allDayState.value === 'partial') return partialLaneHeight.value
    return allDay.value.height
})

// Tracks to actually render: none when closed, the first few when partial, all when full.
const visibleTracks = computed(() => {
    if (allDayState.value === 'closed') return []
    if (allDayState.value === 'partial') return allDay.value.tracks.slice(0, PROJECTS_VISIBLE_COUNT)
    return allDay.value.tracks
})

// Inner content height matches the rendered tracks (no inner scrolling).
const allDayContentHeight = computed(() => (allDayState.value === 'closed' ? 0 : allDayLaneHeight.value))

const lockedGroupOverlays = computed(() => {
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    if (totalMin <= 0) return []
    const users = visibleUsers.value
    const days = weekDays.value

    let rowTop = allDayLaneHeight.value
    const rowTops = users.map(u => {
        const t = rowTop
        rowTop += rowHeightFor(u.id)
        return t
    })

    const raw = events.value
        .filter(ev => ev.executing_user_ids.length > 1)
        .map(ev => {
            const evDayIso = formatLocalDateAsISO(ev.start)
            const dayIdx = days.findIndex(d => d.iso === evDayIso)
            if (dayIdx === -1) return null

            const userIndices = ev.executing_user_ids
                .map(uid => users.findIndex(u => u.id === uid))
                .filter(i => i !== -1)
            if (userIndices.length < 2) return null

            const minIdx = Math.min(...userIndices)
            const maxIdx = Math.max(...userIndices)

            const topPad = paddingYFor(users[minIdx].id)
            const bottomPad = paddingYFor(users[maxIdx].id)

            let overlayHeight = 0
            for (let i = minIdx; i <= maxIdx; i++) overlayHeight += rowHeightFor(users[i].id)

            let overallStartMin = ev.start.getHours() * 60 + ev.start.getMinutes() - dayStartHour.value * 60
            let overallEndMin = ev.end.getHours() * 60 + ev.end.getMinutes() - dayStartHour.value * 60
            for (const u of (ev.executing_users || [])) {
                if (!u.has_diverging_times) continue
                if (u.diverging_start) {
                    const [h, m] = u.diverging_start.slice(0, 5).split(':').map(Number)
                    overallStartMin = Math.min(overallStartMin, h * 60 + m - dayStartHour.value * 60)
                }
                if (u.diverging_end) {
                    const [h, m] = u.diverging_end.slice(0, 5).split(':').map(Number)
                    overallEndMin = Math.max(overallEndMin, h * 60 + m - dayStartHour.value * 60)
                }
            }
            const startOffsetMin = Math.max(0, Math.min(totalMin, overallStartMin))
            const endOffsetMin = Math.max(0, Math.min(totalMin, overallEndMin))

            return {
                id: ev.id,
                color: ev.color || '#3b82f6',
                top: rowTops[minIdx] + topPad,
                height: overlayHeight - topPad - bottomPad,
                leftPct: (dayIdx + startOffsetMin / totalMin) / days.length * 100,
                widthPct: (endOffsetMin - startOffsetMin) / totalMin / days.length * 100,
                nestingLevel: 0,
            }
        })
        .filter(Boolean)

    // Smallest-height overlay = innermost (level 0); larger ones nest outward
    for (const ov of raw) {
        const spatiallyOverlapping = raw.filter(other =>
            other !== ov &&
            other.leftPct < ov.leftPct + ov.widthPct &&
            other.leftPct + other.widthPct > ov.leftPct &&
            other.top < ov.top + ov.height &&
            other.top + other.height > ov.top
        )
        ov.nestingLevel = spatiallyOverlapping.filter(other =>
            other.height < ov.height ||
            (other.height === ov.height && other.id < ov.id)
        ).length
    }

    return raw
})

function toggleAllDay() {
    const order = ['closed', 'partial', 'full']
    allDayState.value = order[(order.indexOf(allDayState.value) + 1) % order.length]
}

function rowHeightFor(userId) {
    if (collapsedUsers.value.has(userId)) return COLLAPSED_ROW_H
    if (props.latestPings[userId]) {
        return mapExpandedUsers.value.has(userId) ? MAP_EXPANDED_ROW_H : MAP_DEFAULT_ROW_H
    }
    return props.rowHeight
}

function toggleMapExpand(userId) {
    const next = new Set(mapExpandedUsers.value)
    next.has(userId) ? next.delete(userId) : next.add(userId)
    mapExpandedUsers.value = next
}

function paddingYFor(userId) {
    return collapsedUsers.value.has(userId) ? 4 : props.eventPaddingY
}

function toggleUserRow(userId) {
    const next = new Set(collapsedUsers.value)
    next.has(userId) ? next.delete(userId) : next.add(userId)
    collapsedUsers.value = next
}

const allRowsCollapsed = computed(() =>
    visibleUsers.value.length > 0 && visibleUsers.value.every(u => collapsedUsers.value.has(u.id))
)

function toggleAllRows() {
    collapsedUsers.value = allRowsCollapsed.value
        ? new Set()
        : new Set(visibleUsers.value.map(u => u.id))
}

const weekDays = computed(() => {
    const count = plannerView.value === 'day' ? 1 : 7
    const start = dayjs(weekStart.value)
    return Array.from({ length: count }, (_, i) => {
        const d = start.add(i, 'day')
        return { date: d.toDate(), iso: d.format('YYYY-MM-DD') }
    })
})

const months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
    'juli', 'augustus', 'september', 'oktober', 'november', 'december']
const dayNames = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag']

const periodTitle = computed(() => {
    if (plannerView.value === 'day') {
        const d = weekDays.value[0].date
        return `${dayNames[d.getDay()]} ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`
    }
    const first = weekDays.value[0].date
    const last = weekDays.value[6].date
    const sameMonth = first.getMonth() === last.getMonth()
    const sameYear = first.getFullYear() === last.getFullYear()
    if (sameMonth && sameYear) {
        return `${first.getDate()} – ${last.getDate()} ${months[first.getMonth()]} ${first.getFullYear()}`
    }
    if (sameYear) {
        return `${first.getDate()} ${months[first.getMonth()]} – ${last.getDate()} ${months[last.getMonth()]} ${first.getFullYear()}`
    }
    return `${first.getDate()} ${months[first.getMonth()]} ${first.getFullYear()} – ${last.getDate()} ${months[last.getMonth()]} ${last.getFullYear()}`
})

function startOfWeek(date) {
    return dayjs(date).startOf('isoWeek').toDate()
}

function dayLabel(date) {
    const days = ['ZO', 'MA', 'DI', 'WO', 'DO', 'VR', 'ZA']
    return `${days[date.getDay()]} ${dayjs(date).format('DD-MM')}`
}

function isToday(date) {
    return dayjs(date).isSame(dayjs(), 'day')
}

const nowOffsetPercent = ref(null)

function updateNow() {
    const t = new Date()
    const minutes = t.getHours() * 60 + t.getMinutes()
    const startMin = dayStartHour.value * 60
    const endMin = dayEndHour.value * 60
    if (minutes < startMin || minutes > endMin) {
        nowOffsetPercent.value = null
        return
    }
    nowOffsetPercent.value = ((minutes - startMin) / (endMin - startMin)) * 100
}

let nowInterval = null
onMounted(() => {
    updateNow()
    nowInterval = setInterval(updateNow, 60_000)
    fetchEvents()
    nextTick(() => scrollToWorkdayStart())
    startPolling()
    window.addEventListener('dragend', onPlannerDragEnd)
})
onUnmounted(() => {
    if (nowInterval) clearInterval(nowInterval)
    stopPolling()
    window.removeEventListener('dragend', onPlannerDragEnd)
    stopDragAutoScroll()
})

watch([dayStartHour, dayEndHour], () => updateNow())
watch(weekStart, (val) => {
    if (hasPermission('events.see_beyond_current_week')) {
        localStorage.setItem(WEEK_STORAGE_KEY, dayjs(val).format('YYYY-MM-DD'))
    }
    resetFingerprint()
    fetchEvents()
})
watch(plannerView, () => {
    resetFingerprint()
    fetchEvents()
})

function shiftPeriod(direction) {
    if (!hasPermission('events.see_beyond_current_week')) {
        if (plannerView.value !== 'day') return
        const next = dayjs(weekStart.value).add(direction, 'day')
        const week_start = dayjs().startOf('isoWeek')
        if (next.isBefore(week_start, 'day') || next.isAfter(week_start.add(6, 'day'), 'day')) return
    }
    const days = plannerView.value === 'day' ? 1 : 7
    weekStart.value = dayjs(weekStart.value).add(direction * days, 'day').toDate()
}

function setPlannerView(view) {
    if (plannerView.value === view) return
    if (view === 'week') {
        weekStart.value = startOfWeek(weekStart.value)
    }
    plannerView.value = view
    localStorage.setItem(VIEW_STORAGE_KEY, view)
}

function toggleGroupFilter(groupId) {
    selectedGroupIds.value = selectedGroupIds.value.includes(groupId)
        ? selectedGroupIds.value.filter(id => id !== groupId)
        : [...selectedGroupIds.value, groupId]
}

function goToday() {
    const today = new Date()
    weekStart.value = plannerView.value === 'day' ? today : startOfWeek(today)
    nextTick(() => plannerView.value === 'week' ? scrollToDate(today) : null)
}

function scrollToDate(date) {
    const grid = gridScrollRef.value
    if (!grid) return
    const dayIndex = Math.round(dayjs(date).startOf('day').diff(dayjs(weekStart.value).startOf('day'), 'day', true))
    if (dayIndex < 0 || dayIndex > 6) return
    grid.scrollTo({ left: dayIndex * dayWidthPx.value, behavior: 'smooth' })
}

function scrollToWorkdayStart() {
    const grid = gridScrollRef.value
    if (!grid) return
    grid.scrollLeft = 0
}

function userUnavailabilitiesFor(userId) {
    return props.plannableUsers.find(u => u.id === userId)?.unavailabilities ?? []
}

function unavailabilityMatchesDay(unav, dayIso) {
    if (unav.type === 'holiday') {
        const end = unav.end_date ?? unav.date
        return dayIso >= unav.date && dayIso <= end
    }
    if (unav.type === 'recurring') {
        // dayjs: 0=Sun,1=Mon..6=Sat → convert to 0=Mon..6=Sun
        const dow = (dayjs(dayIso).day() + 6) % 7
        if (dow !== unav.day_of_week) return false
        if (unav.repeat === 'biweekly' && unav.reference_date) {
            const weeksDiff = Math.abs(dayjs(unav.reference_date).startOf('isoWeek').diff(dayjs(dayIso).startOf('isoWeek'), 'week'))
            return weeksDiff % 2 === 0
        }
        return true
    }
    return false
}

function isBlockedAtTime(userId, dayIso, startMin, endMin) {
    const absStart = startMin + dayStartHour.value * 60
    const absEnd = endMin + dayStartHour.value * 60
    return userUnavailabilitiesFor(userId).some(unav => {
        if (!unavailabilityMatchesDay(unav, dayIso)) return false
        if (unav.start_time === null) return true
        const [sh, sm] = unav.start_time.split(':').map(Number)
        const [eh, em] = unav.end_time.split(':').map(Number)
        return absStart < eh * 60 + em && absEnd > sh * 60 + sm
    })
}

function getBlockOverlays(userId, dayIso) {
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    return userUnavailabilitiesFor(userId)
        .filter(unav => unavailabilityMatchesDay(unav, dayIso))
        .map(unav => {
            if (unav.start_time === null) {
                return { left: 0, width: 100, label: unav.label }
            }
            const [sh, sm] = unav.start_time.split(':').map(Number)
            const [eh, em] = unav.end_time.split(':').map(Number)
            const offsetStart = sh * 60 + sm - dayStartHour.value * 60
            const offsetEnd = eh * 60 + em - dayStartHour.value * 60
            const clampedStart = Math.max(0, offsetStart)
            const clampedEnd = Math.min(totalMin, offsetEnd)
            return {
                left: (clampedStart / totalMin) * 100,
                width: Math.max(0, ((clampedEnd - clampedStart) / totalMin) * 100),
                label: unav.label,
            }
        })
}

function eventsFor(userId, dayIso) {
    return events.value.filter(ev => {
        if (!ev.executing_user_ids.includes(userId)) return false
        const evDayIso = formatLocalDateAsISO(ev.start)
        return evDayIso === dayIso
    })
}

function userHoursLabel(userId) {
    let mins = 0
    const dayStart = plannerView.value === 'day' ? dayjs(weekStart.value).startOf('day').valueOf() : null
    const dayEnd = plannerView.value === 'day' ? dayjs(weekStart.value).endOf('day').valueOf() : null
    for (const ev of events.value) {
        if (!ev.executing_user_ids.includes(userId)) continue
        if (dayStart !== null && (ev.end <= dayStart || ev.start >= dayEnd)) continue
        const user = ev.executing_users?.find(u => u.id === userId)
        const userBreaktime = user?.breaktime ?? 0
        let evDurationMin
        if (user?.has_diverging_times && user.diverging_start && user.diverging_end) {
            const dateStr = dayjs(ev.start).format('YYYY-MM-DD')
            const dStart = dayjs(`${dateStr} ${user.diverging_start.slice(0, 5)}`).valueOf()
            const dEnd   = dayjs(`${dateStr} ${user.diverging_end.slice(0, 5)}`).valueOf()
            evDurationMin = Math.max(0, (dEnd - dStart) / 60000)
        } else {
            evDurationMin = (ev.end - ev.start) / 60000
        }
        mins += Math.max(0, evDurationMin - userBreaktime)
    }
    const h = Math.floor(mins / 60)
    const m = Math.round(mins % 60)
    const label = plannerView.value === 'day' ? 'vandaag' : 'deze week'
    return `${h}u ${String(m).padStart(2, '0')}m ${label}`
}

function snapMinutes(min) {
    return Math.round(min / slotMinutes.value) * slotMinutes.value
}

function minutesFromDayStart(date) {
    return date.getHours() * 60 + date.getMinutes() - dayStartHour.value * 60
}

function formatTimeFromMinutes(min) {
    const h = Math.floor((dayStartHour.value * 60 + min) / 60)
    const m = (dayStartHour.value * 60 + min) % 60
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

function formatTimeFromDate(date) {
    return nlTime(date)
}

function findCellElement(target) {
    let el = target
    while (el && el !== document.body) {
        if (el.dataset && el.dataset.userId && el.dataset.dayIso) {
            return el
        }
        el = el.parentElement
    }
    return null
}

function cellInfoFromPoint(clientX, clientY) {
    const el = document.elementFromPoint(clientX, clientY)
    const cell = findCellElement(el)
    if (!cell) return null
    const rect = cell.getBoundingClientRect()
    const xPct = (clientX - rect.left) / rect.width
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    const minutes = Math.max(0, Math.min(totalMin, Math.round(xPct * totalMin)))
    const userId = parseInt(cell.dataset.userId, 10)
    return { cell, rect, userId, dayIso: cell.dataset.dayIso, minutes, totalMin }
}

function onCellPointerDown(e, user, day) {
    if (!hasPermission('event.create')) return
    if (e.target.closest('[data-planner-event]')) return
    if (e.button !== 0) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    if (isBlockedAtTime(user.id, day.iso, snapMinutes(info.minutes), snapMinutes(info.minutes) + slotMinutes.value)) return
    const startMin = snapMinutes(info.minutes)
    selectRect.value = {
        userId: user.id,
        dayIso: day.iso,
        startMinutes: startMin,
        endMinutes: startMin + slotMinutes.value,
        left: (startMin / info.totalMin) * 100,
        width: (slotMinutes.value / info.totalMin) * 100,
        cellRect: info.rect,
        totalMin: info.totalMin,
        dragged: false,
    }
    drag.value = { mode: 'select', user, day }
    e.preventDefault()
}

function onEventPointerDown(e, ev, user) {
    if (e.button !== 0) return
    if (!canEditEvent(ev)) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const startMin = minutesFromDayStart(ev.start)
    drag.value = {
        mode: 'move',
        eventId: ev.id,
        originalEvent: ev,
        cursorOffsetMinutes: info.minutes - startMin,
        durationMinutes: (ev.end - ev.start) / 60000,
        previewStart: new Date(ev.start),
        previewEnd: new Date(ev.end),
        previewUserId: user.id,
        ghostRect: null,
        isLocked: ev.executing_user_ids.length > 1,
    }
    updateGhost(e.clientX, e.clientY)
    e.preventDefault()
    e.stopPropagation()
}

function onResizePointerDown(e, ev, user, edge) {
    if (e.button !== 0) return
    if (!canEditEvent(ev)) return
    drag.value = {
        mode: 'resize',
        edge,
        eventId: ev.id,
        originalEvent: ev,
        previewStart: new Date(ev.start),
        previewEnd: new Date(ev.end),
        previewUserId: user.id,
    }
    updateGhost(e.clientX, e.clientY)
    e.preventDefault()
    e.stopPropagation()
}

function dateFromDayIsoAndMinutes(dayIso, minutes) {
    return dayjs(dayIso)
        .startOf('day')
        .add(dayStartHour.value * 60 + minutes, 'minute')
        .toDate()
}

function onWindowPointerMove(e) {
    if (!drag.value.mode) return
    if (drag.value.mode === 'select') {
        const info = cellInfoFromPoint(e.clientX, e.clientY)
        if (!info) return
        if (info.userId !== selectRect.value.userId || info.dayIso !== selectRect.value.dayIso) return
        selectRect.value.dragged = true
        const rawEnd = snapMinutes(info.minutes)
        const start = selectRect.value.startMinutes
        const end = Math.max(start + slotMinutes.value, rawEnd)
        selectRect.value.endMinutes = end
        selectRect.value.left = (Math.min(start, end) / info.totalMin) * 100
        selectRect.value.width = (Math.abs(end - start) / info.totalMin) * 100
        return
    }
    if (drag.value.mode === 'move' || drag.value.mode === 'resize') {
        updateGhost(e.clientX, e.clientY)
    }
}

function updateGhost(clientX, clientY) {
    const info = cellInfoFromPoint(clientX, clientY)
    if (!info) {
        dragGhost.value = null
        return
    }
    const ev = drag.value.originalEvent
    let previewStart, previewEnd, previewUserId, dayIso = info.dayIso

    if (drag.value.mode === 'move') {
        const rawStartMin = snapMinutes(info.minutes - drag.value.cursorOffsetMinutes)
        const totalMin = info.totalMin
        const dur = drag.value.durationMinutes
        const startMin = Math.max(0, Math.min(totalMin - dur, rawStartMin))
        previewStart = dateFromDayIsoAndMinutes(info.dayIso, startMin)
        previewEnd = new Date(previewStart.getTime() + dur * 60000)
        previewUserId = drag.value.isLocked ? ev.executing_user_ids[0] : info.userId
    } else {
        const minutes = snapMinutes(info.minutes)
        if (drag.value.edge === 'start') {
            previewEnd = new Date(ev.end)
            const newStart = dateFromDayIsoAndMinutes(formatLocalDateAsISO(ev.start), minutes)
            previewStart = newStart < previewEnd ? newStart : new Date(previewEnd.getTime() - slotMinutes.value * 60000)
        } else {
            previewStart = new Date(ev.start)
            const newEnd = dateFromDayIsoAndMinutes(formatLocalDateAsISO(ev.start), minutes)
            previewEnd = newEnd > previewStart ? newEnd : new Date(previewStart.getTime() + slotMinutes.value * 60000)
        }
        previewUserId = drag.value.previewUserId
        dayIso = formatLocalDateAsISO(ev.start)
    }

    drag.value.previewStart = previewStart
    drag.value.previewEnd = previewEnd
    drag.value.previewUserId = previewUserId
    drag.value.previewDayIso = dayIso

    const targetUser = props.plannableUsers.find(u => u.id === previewUserId)
    const targetCell = document.querySelector(
        `[data-user-id="${previewUserId}"][data-day-iso="${dayIso}"]`
    )
    if (!targetCell || !bodyRef.value) {
        dragGhost.value = null
        return
    }
    const cellRect = targetCell.getBoundingClientRect()
    const bodyRect = bodyRef.value.getBoundingClientRect()
    const startMin = minutesFromDayStart(previewStart)
    const totalMin = info.totalMin
    const padY = paddingYFor(previewUserId)
    const leftPx = (cellRect.left - bodyRect.left) + (startMin / totalMin) * cellRect.width
    const topPx = cellRect.top - bodyRect.top
    const widthPx = ((previewEnd - previewStart) / 60000 / totalMin) * cellRect.width

    dragGhost.value = {
        title: ev.title,
        start: previewStart,
        end: previewEnd,
        userName: (drag.value.mode === 'move' && targetUser && targetUser.id !== ev.executing_user_ids[0]) ? targetUser.name : null,
        style: {
            left: leftPx + 'px',
            top: (topPx + padY) + 'px',
            width: Math.max(40, widthPx) + 'px',
            height: (rowHeightFor(previewUserId) - 2 * padY) + 'px',
            borderColor: ev.color || '#3b82f6',
            color: ev.color || '#3b82f6',
        },
    }
}

async function onWindowPointerUp() {
    if (!drag.value.mode) return
    const mode = drag.value.mode
    if (mode === 'select') {
        const sel = selectRect.value
        selectRect.value = null
        drag.value = { eventId: null, mode: null }
        if (!sel) return
        const startMin = Math.min(sel.startMinutes, sel.endMinutes)
        const endMin = sel.dragged
            ? Math.max(sel.startMinutes, sel.endMinutes)
            : startMin + plannerMinutes.value
        const start = dateFromDayIsoAndMinutes(sel.dayIso, startMin)
        const end = dateFromDayIsoAndMinutes(sel.dayIso, endMin)
        openCreate({ start, end, userId: sel.userId })
        return
    }
    if (mode === 'move' || mode === 'resize') {
        const ev = drag.value.originalEvent
        const previewStart = drag.value.previewStart
        const previewEnd = drag.value.previewEnd
        const previewUserId = drag.value.previewUserId
        const movedTime = previewStart.getTime() !== ev.start.getTime() ||
            previewEnd.getTime() !== ev.end.getTime()
        const movedUser = !drag.value.isLocked && mode === 'move' &&
            !ev.executing_user_ids.includes(previewUserId)
        dragGhost.value = null
        drag.value = { eventId: null, mode: null }
        if (movedTime || movedUser) {
            suppressClickUntil = Date.now() + 300
            await persistEventChange(ev, previewStart, previewEnd, movedUser ? previewUserId : null)
        }
    }
}

function handleEventClick(ev) {
    if (Date.now() < suppressClickUntil) return
    if (!hasPermission('event.create')) return
    if (!canEditEvent(ev)) return
    openEdit(ev)
}

function onEventContextMenu(e, ev) {
    drag.value = { eventId: null, mode: null }
    dragGhost.value = null
    injectTypeColorStyles()
    const items = []
    if (canEditEvent(ev)) {
        items.push({
            label: 'Wijzig type',
            children: props.eventTypes.map(t => ({
                label: ev.event_type_id === t.id ? `${t.name}  ✓` : t.name,
                customClass: 'planner-cm-type-' + t.id,
                onClick: () => changeEventType(ev, t),
            })),
        })
        items.push({
            label: `Monteurs (${ev.executing_user_ids.length})`,
            children: props.plannableUsers.map(u => {
                const assigned = ev.executing_user_ids.includes(u.id)
                const isLast = assigned && ev.executing_user_ids.length === 1
                return {
                    label: assigned ? `${u.name}  ✓` : u.name,
                    disabled: isLast,
                    onClick: () => toggleExecutingUser(ev, u),
                }
            }),
        })
        items.push({ label: 'Bewerken…', onClick: () => openEdit(ev) })
        items.push({
            label: ev.is_preliminary ? 'Markeer als definitief' : 'Markeer als voorlopig',
            divided: true,
            onClick: () => togglePreliminary(ev),
        })
        if (hasPermission('event.create')) {
            items.push({
                label: 'Kopiëren',
                children: [
                    { label: 'Naar morgen', onClick: () => copyEvent(ev, [1]) },
                    { label: 'Rest van de week', onClick: () => copyEvent(ev, restOfWeekOffsets(ev)) },
                    { label: 'Naar volgende week', onClick: () => copyEvent(ev, [7]) },
                ],
            })
        }
    }
    if (ev.eventable_id) {
        items.push({
            label: `Open werkbon #${ev.eventable_id}`,
            divided: items.length > 0,
            onClick: () => router.visit(`/serviceorders/${ev.eventable_id}`),
        })
        items.push({
            label: 'Stuur afspraakbevestiging',
            onClick: () => sendAppointmentConfirmation(ev),
        })
    }
    if (ev.customer_id && hasPermission('customer.read')) {
        items.push({
            label: `Open klant${ev.customer_name ? ` — ${ev.customer_name}` : ''}`,
            onClick: () => router.visit(`/customers/${ev.customer_id}`),
        })
    }
    if (canDeleteEvent(ev)) {
        items.push({ label: 'Verwijderen', divided: items.length > 0, onClick: () => deleteEvent(ev) })
    }
    ContextMenu.showContextMenu({
        x: e.clientX,
        y: e.clientY,
        items,
    })
}

let typeStyleEl = null
function injectTypeColorStyles() {
    if (typeStyleEl) return
    typeStyleEl = document.createElement('style')
    typeStyleEl.textContent = props.eventTypes.map(t =>
        `.mx-context-menu-item.planner-cm-type-${t.id} .label::before { content: "● "; color: ${t.color || '#3b82f6'}; font-weight: 700; }`
    ).join('\n')
    document.head.appendChild(typeStyleEl)
}

async function toggleExecutingUser(ev, user) {
    const wasAssigned = ev.executing_user_ids.includes(user.id)
    const original = {
        ids: [...ev.executing_user_ids],
        users: [...ev.executing_users],
    }
    const next_ids = wasAssigned
        ? ev.executing_user_ids.filter(id => id !== user.id)
        : [...ev.executing_user_ids, user.id]
    if (next_ids.length === 0) return
    ev.executing_user_ids = next_ids
    ev.executing_users = wasAssigned
        ? ev.executing_users.filter(u => u.id !== user.id)
        : [...ev.executing_users, { id: user.id, name: user.name, avatar: user.avatar }]
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { executing_user_ids: next_ids })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = wasAssigned
            ? `${user.name} verwijderd van afspraak`
            : `${user.name} toegevoegd aan afspraak`
    } catch (e) {
        console.error('Failed to update executing users', e)
        ev.executing_user_ids = original.ids
        ev.executing_users = original.users
        page.props.flash.error = e.response?.data?.message || 'Kon monteurs niet bijwerken'
    }
}

async function togglePreliminary(ev) {
    const original = ev.is_preliminary
    ev.is_preliminary = !original
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { is_preliminary: ev.is_preliminary })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = ev.is_preliminary ? 'Afspraak gemarkeerd als voorlopig' : 'Afspraak gemarkeerd als definitief'
    } catch (e) {
        console.error('Failed to toggle preliminary', e)
        ev.is_preliminary = original
        page.props.flash.error = e.response?.data?.message || 'Kon voorlopig-status niet wijzigen'
    }
}

async function changeEventType(ev, type) {
    const original = { event_type_id: ev.event_type_id, title: ev.title, color: ev.color }
    ev.event_type_id = type.id
    ev.title = type.name
    ev.color = type.color || ev.color
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { event_type_id: type.id })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = `Afspraaktype gewijzigd naar "${type.name}"`
    } catch (e) {
        console.error('Failed to change event type', e)
        ev.event_type_id = original.event_type_id
        ev.title = original.title
        ev.color = original.color
        page.props.flash.error = e.response?.data?.message || 'Kon afspraaktype niet wijzigen'
    }
}

async function sendAppointmentConfirmation(ev) {
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post(`/api/events/${ev.id}/send-confirmation`)
        page.props.flash.success = r.data.message
    } catch (e) {
        page.props.flash.error = e.response?.data?.message || 'Kon bevestiging niet verzenden'
    }
}

async function deleteEvent(ev) {
    if (!confirm(`Weet je zeker dat je afspraak #${ev.id} wilt verwijderen?`)) return
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.delete(`/api/events/${ev.id}`)
        if (r.status !== 204) throw new Error('bad response')
        events.value = events.value.filter(x => x.id !== ev.id)
        if (ev.eventable_id && ev.eventable_type === '\\App\\Models\\ServiceOrder') {
            emit('service-order-unplanned', ev.eventable_id)
        }
        page.props.flash.success = 'Afspraak verwijderd'
    } catch (e) {
        console.error('Failed to delete event', e)
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet verwijderen'
    }
}

function restOfWeekOffsets(ev) {
    const dow = new Date(ev.start).getDay() // 0=Sun … 6=Sat
    const offsets = []
    for (let d = 1; dow + d <= 5; d++) {
        offsets.push(d)
    }
    return offsets
}

async function copyEvent(ev, offsets) {
    if (offsets.length === 0) {
        page.props.flash.error = 'Geen werkdagen meer om naar te kopiëren deze week.'
        return
    }
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post(`/api/events/${ev.id}/copy`, { offsets })
        if (r.status !== 201) throw new Error('bad response')
        fetchEvents()
        const n = r.data.length
        page.props.flash.success = `${n} afspraak${n !== 1 ? 'en' : ''} gekopieerd`
    } catch (e) {
        console.error('Failed to copy event', e)
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet kopiëren'
    }
}

function shiftTimeString(hhmm, deltaMin) {
    const [h, m] = hhmm.slice(0, 5).split(':').map(Number)
    const total = ((h * 60 + m + Math.round(deltaMin)) % 1440 + 1440) % 1440
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

async function persistEventChange(ev, newStart, newEnd, replaceWithUserId) {
    const startDeltaMs = newStart.getTime() - ev.start.getTime()
    const isMove = !replaceWithUserId && startDeltaMs !== 0 && startDeltaMs === newEnd.getTime() - ev.end.getTime()
    const hasDivergingUsers = isMove && ev.executing_users.some(u => u.has_diverging_times)

    const originalDivergingTimes = hasDivergingUsers
        ? ev.executing_users.map(u => ({ id: u.id, diverging_start: u.diverging_start, diverging_end: u.diverging_end }))
        : null

    const original = { start: ev.start, end: ev.end, executing_user_ids: [...ev.executing_user_ids] }
    ev.start = newStart
    ev.end = newEnd

    if (hasDivergingUsers) {
        const deltaMin = startDeltaMs / 60000
        ev.executing_users.forEach(u => {
            if (!u.has_diverging_times || !u.diverging_start || !u.diverging_end) return
            u.diverging_start = shiftTimeString(u.diverging_start, deltaMin)
            u.diverging_end = shiftTimeString(u.diverging_end, deltaMin)
        })
    }

    if (replaceWithUserId) {
        ev.executing_user_ids = [replaceWithUserId]
        ev.executing_users = props.plannableUsers
            .filter(u => u.id === replaceWithUserId)
            .map(u => ({ id: u.id, name: u.name, avatar: u.avatar }))
    }
    try {
        const payload = {
            start: formatUtcDatetime(newStart).slice(0, 16),
            end: formatUtcDatetime(newEnd).slice(0, 16),
        }
        if (replaceWithUserId) {
            payload.executing_user_ids = ev.executing_user_ids
        }
        if (hasDivergingUsers) {
            payload.executing_user_ids = ev.executing_user_ids
            payload.executing_user_breaktimes = Object.fromEntries(
                ev.executing_users.map(u => [u.id, u.breaktime ?? 0])
            )
            payload.executing_user_roles = Object.fromEntries(
                ev.executing_users.map(u => [u.id, u.user_role_ids ?? []])
            )
            payload.executing_user_diverging_times = Object.fromEntries(
                ev.executing_users.map(u => [u.id, {
                    has_diverging_times: u.has_diverging_times ?? false,
                    diverging_start: u.diverging_start ?? null,
                    diverging_end: u.diverging_end ?? null,
                }])
            )
        }
        await axios.get('sanctum/csrf-cookie')
        const response = await axios.put(`/api/events/${ev.id}`, payload)
        if (response.status !== 200) throw new Error('bad response')
        page.props.flash.success = replaceWithUserId
            ? 'Afspraak verplaatst naar andere monteur'
            : 'Afspraak bijgewerkt'
    } catch (e) {
        console.error('Failed to update event', e)
        ev.start = original.start
        ev.end = original.end
        ev.executing_user_ids = original.executing_user_ids
        if (originalDivergingTimes) {
            originalDivergingTimes.forEach(({ id, diverging_start, diverging_end }) => {
                const u = ev.executing_users.find(u => u.id === id)
                if (u) { u.diverging_start = diverging_start; u.diverging_end = diverging_end }
            })
        }
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet bijwerken'
        fetchEvents()
    }
}

const plannerMinutes = ref(props.defaultPlannerMinutes)

/** Snapped, day-clamped start minute for an incoming drop of the given duration. */
function dropStartMinutes(info, durationMin) {
    const snapped = snapMinutes(info.minutes)
    const maxStart = Math.max(0, info.totalMin - durationMin)
    return Math.max(0, Math.min(maxStart, snapped))
}

function onDragOver(e) {
    if (!(e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload'))) return
    dragPointerY = e.clientY
    startDragAutoScroll()
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (info) {
        const startMin = dropStartMinutes(info, plannerMinutes.value)
        if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + plannerMinutes.value)) {
            e.dataTransfer.dropEffect = 'none'
            dragGhost.value = null
            return
        }
    }
    e.dataTransfer.dropEffect = 'copy'
    updateExternalDropGhost(e.clientX, e.clientY)
}

function onGridDragLeave(e) {
    // Only clear when the cursor actually leaves the grid, not when crossing child cells.
    if (!e.currentTarget.contains(e.relatedTarget)) {
        dragGhost.value = null
        stopDragAutoScroll()
    }
}

// --- Auto-scroll while dragging a service order near the grid's top/bottom edge ---
// Native HTML5 drag suppresses the wheel, so we scroll the single grid container ourselves.
// A requestAnimationFrame loop recomputes speed from the latest cursor Y every frame, so the
// scroll stays smooth even though `dragover` fires irregularly, and keeps going while the cursor
// is held in the edge zone. It stops on leave/drop/dragend.
const AUTO_SCROLL_EDGE = 320 // px from an edge where auto-scroll kicks in (the deeper, the faster)
const AUTO_SCROLL_MAX_SPEED = 38 // px per frame right at the very edge
let dragPointerY = null
let autoScrollRaf = null

// Speed for a cursor `dist` px from an edge: 0 outside the zone, ramping to full at the edge with
// a steep ease-in (cubic) curve so the zone creeps slowly for fine control and the speed climbs
// dramatically as the cursor nears the very edge.
function edgeScrollSpeed(dist) {
    if (dist >= AUTO_SCROLL_EDGE) return 0
    const intensity = (AUTO_SCROLL_EDGE - dist) / AUTO_SCROLL_EDGE
    return AUTO_SCROLL_MAX_SPEED * intensity * intensity * intensity
}

function autoScrollStep() {
    const el = gridScrollRef.value
    if (!el || dragPointerY === null) {
        autoScrollRaf = null
        return
    }
    const rect = el.getBoundingClientRect()
    const vel = -edgeScrollSpeed(dragPointerY - rect.top) || edgeScrollSpeed(rect.bottom - dragPointerY)
    if (!vel) {
        autoScrollRaf = null
        return
    }
    el.scrollTop += vel
    autoScrollRaf = requestAnimationFrame(autoScrollStep)
}

function startDragAutoScroll() {
    if (autoScrollRaf === null) autoScrollRaf = requestAnimationFrame(autoScrollStep)
}

function stopDragAutoScroll() {
    dragPointerY = null
    if (autoScrollRaf !== null) {
        cancelAnimationFrame(autoScrollRaf)
        autoScrollRaf = null
    }
}

// Fires for any native drag end (drop, drop outside the grid, or Esc cancel) — clean up the
// floating preview and the auto-scroll loop so nothing stays stuck on the planner.
function onPlannerDragEnd() {
    stopDragAutoScroll()
    dragGhost.value = null
}

function updateExternalDropGhost(clientX, clientY) {
    const info = cellInfoFromPoint(clientX, clientY)
    if (!info || !bodyRef.value) {
        dragGhost.value = null
        return
    }
    const startMin = dropStartMinutes(info, plannerMinutes.value)
    const start = dateFromDayIsoAndMinutes(info.dayIso, startMin)
    const end = new Date(start.getTime() + plannerMinutes.value * 60000)
    const targetUser = props.plannableUsers.find(u => u.id === info.userId)
    const targetCell = document.querySelector(
        `[data-user-id="${info.userId}"][data-day-iso="${info.dayIso}"]`
    )
    if (!targetCell) {
        dragGhost.value = null
        return
    }
    const cellRect = targetCell.getBoundingClientRect()
    const bodyRect = bodyRef.value.getBoundingClientRect()
    const padY = paddingYFor(info.userId)
    const leftPx = (cellRect.left - bodyRect.left) + (startMin / info.totalMin) * cellRect.width
    const topPx = cellRect.top - bodyRect.top
    const widthPx = (plannerMinutes.value / info.totalMin) * cellRect.width
    dragGhost.value = {
        title: `Nieuwe afspraak (${plannerMinutes.value} min)`,
        start,
        end,
        userName: targetUser?.name || null,
        style: {
            left: leftPx + 'px',
            top: (topPx + padY) + 'px',
            width: Math.max(40, widthPx) + 'px',
            height: (rowHeightFor(info.userId) - 2 * padY) + 'px',
            borderColor: '#2563ff',
            color: '#2563ff',
        },
    }
}

function onProjectServiceOrderDragStart(e, so) {
    setServiceOrderDragData(e, so)
    e.currentTarget.classList.add('opacity-40')
}

function onProjectServiceOrderDragEnd(e) {
    e.currentTarget.classList.remove('opacity-40')
}

function onExternalDrop(e, user, day) {
    dragGhost.value = null
    stopDragAutoScroll()
    const raw = e.dataTransfer?.getData('application/x-planner-payload')
    if (!raw) return
    let payload
    try { payload = JSON.parse(raw) } catch { return }

    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const duration = payload.duration_minutes || plannerMinutes.value
    const startMin = dropStartMinutes(info, duration)
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) return
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end = new Date(start.getTime() + duration * 60000)
    createEventFromDrop({ start, end, userId: user.id, payload })
}

async function createEventFromDrop({ start, end, userId, payload }) {
    if (!hasPermission('event.create')) {
        page.props.flash.error = 'Je hebt geen rechten om afspraken te maken'
        return
    }
    const eventTypeId = props.eventTypes[0]?.id
    if (!eventTypeId) {
        page.props.flash.error = 'Geen afspraaktype beschikbaar om in te plannen'
        return
    }
    const body = {
        event_type_id: eventTypeId,
        name: payload.name || '',
        description: payload.description || '',
        status: 'Gepland',
        start: formatUtcDatetime(start).slice(0, 16),
        end: formatUtcDatetime(end).slice(0, 16),
        eventable_type: payload.eventable_type || '\\App\\Models\\ServiceOrder',
        eventable_id: payload.eventable_id || null,
        executing_user_ids: [userId],
    }
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post('/api/events', body)
        if (r.status !== 201) throw new Error('bad response')
        page.props.flash.success = `Werkbon ingepland (${plannerMinutes.value} min)`
        if (body.eventable_id) emit('service-order-planned', body.eventable_id)
        fetchEvents()
    } catch (err) {
        console.error('Failed to create event from drop', err)
        page.props.flash.error = err.response?.data?.message || 'Kon werkbon niet inplannen'
    }
}

async function savePlannerMinutes() {
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put('/api/settings/defaultplannerminutes', { value: plannerMinutes.value })
        page.props.flash.success = `Standaard planminuten opgeslagen (${plannerMinutes.value} min)`
    } catch (e) {
        console.error('Failed to save planner minutes', e)
        page.props.flash.error = e.response?.data?.message || 'Kon standaard planminuten niet opslaan'
    }
}

function openCreate(initial) {
    editingExistingEvent.value = false
    modalInitial.value = {
        id: null,
        event_type_id: props.eventTypes[0]?.id || '',
        name: initial.name || '',
        description: initial.description || '',
        status: props.eventStatusses[0]?.name || 'Gepland',
        start: initial.start,
        end: initial.end,
        eventable_type: initial.eventable_type || '\\App\\Models\\ServiceOrder',
        eventable_id: initial.eventable_id || '',
        customer_id: initial.customer_id || null,
        executing_user_ids: [initial.userId],
    }
    modalOpen.value = true
}

function openEdit(ev) {
    editingExistingEvent.value = true
    modalInitial.value = {
        id: ev.id,
        event_type_id: ev.event_type_id,
        name: ev.name,
        description: ev.description,
        status: ev.status,
        start: ev.start,
        end: ev.end,
        eventable_type: ev.eventable_type,
        eventable_id: ev.eventable_id,
        customer_id: ev.customer_id,
        customer_name: ev.customer_name || null,
        executing_user_ids: [...ev.executing_user_ids],
        executing_users: [...(ev.executing_users || [])],
        is_preliminary: ev.is_preliminary || false,
    }
    modalOpen.value = true
}

function closeModal() {
    modalOpen.value = false
    modalInitial.value = null
}

function onSaved() {
    closeModal()
    fetchEvents()
}
</script>
