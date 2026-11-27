<template>
    <div class="flex items-start justify-between gap-4">
        <BreadcrumbComponent :items="[{ label: 'Offertes', href: '/quotes' }, { label: quote.number }]" />
        <TourButton tour="offerte" />
    </div>

    <header class="mt-4 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex min-w-0 flex-1 gap-4" data-tour="quote.header">
            <div class="hidden sm:flex size-16 shrink-0 items-center justify-center rounded-2xl bg-blue-50 dark:bg-blue-900/30">
                <FileTextIcon class="size-8 text-lavoro-blue" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 dark:text-slate-100">{{ quote.number }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="quoteStatusPillClass(quote.status)">
                        {{ quote.status }}
                    </span>
                    <span class="rounded-full bg-gray-100 dark:bg-slate-800 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:text-slate-300" data-tour="quote.version"
                        v-tooltip="versionSent ? 'De versie waar je aan werkt; de klant heeft hem gekregen' : 'De versie waar je aan werkt; nog niet verstuurd'">
                        Versie {{ quote.version }}<template v-if="!versionSent"> · nog niet verstuurd</template>
                    </span>
                </div>
                <input v-if="canEdit" v-model="form.title" @change="save('title')" aria-label="Titel" data-tour="quote.title"
                    class="mt-1 w-full max-w-2xl rounded-lg border-0 bg-white dark:bg-slate-900 px-3 py-1.5 text-2xl font-bold ring-1 ring-inset ring-gray-200 dark:ring-slate-700 focus:ring-2 focus:ring-indigo-600" />
                <h1 v-else class="mt-1 text-2xl font-bold">{{ quote.title }}</h1>
                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.title }}</p>
                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-slate-400">
                    <Link :href="`/customers/${quote.customer.id}`" class="inline-flex items-center gap-1.5 hover:text-lavoro-blue">
                        <UserIcon class="size-4" /> {{ quote.customer.name }}
                    </Link>
                    <span class="inline-flex items-center gap-1.5"><CalendarIcon class="size-4" /> {{ nlDateMedium(quote.quote_date) }}</span>
                    <span v-if="quote.valid_until" class="inline-flex items-center gap-1.5">
                        <CalendarCheckIcon class="size-4" /> Geldig tot {{ nlDateMedium(quote.valid_until) }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="can.update || can.create" class="flex flex-wrap items-center gap-2" data-tour="quote.actions">
            <DropdownMenu placement="bottom-end" width-class="w-64" title="Meer acties" :button-class="`${BUTTON} ${OUTLINE} px-3`"
                data-tour="quote.menu">
                <template #button>
                    <EllipsisIcon class="size-5" />
                </template>
                <MenuItem v-if="canStartVersion" v-slot="{ active }">
                    <button type="button" :class="menuItem(active)" @click="startVersion">
                        <LayersIcon class="size-4" /> Nieuwe versie maken
                    </button>
                </MenuItem>
                <MenuItem v-if="can.create" v-slot="{ active }">
                    <button type="button" :class="menuItem(active)" @click="copying = 'copy'">
                        <CopyIcon class="size-4" /> Kopie maken
                    </button>
                </MenuItem>
                <MenuItem v-if="can.create" v-slot="{ active }">
                    <button type="button" :class="menuItem(active)" @click="copying = 'template'">
                        <BookmarkIcon class="size-4" /> Bewaren als sjabloon
                    </button>
                </MenuItem>
                <MenuItem v-for="status in can.update ? nextStatuses : []" :key="status" v-slot="{ active }">
                    <button type="button" :class="menuItem(active)" @click="moveTo(status)">{{ STATUS_ACTIONS[status] }}</button>
                </MenuItem>
                <MenuItem v-if="can.delete && !quote.sent_at" v-slot="{ active }">
                    <button type="button" :class="menuItem(active, true)" @click="destroy">
                        <Trash2Icon class="size-4" /> Verwijderen
                    </button>
                </MenuItem>
            </DropdownMenu>
            <a :href="`/quotes/${quote.id}/preview`" target="_blank" :class="[BUTTON, OUTLINE]" data-tour="quote.preview">
                <EyeIcon class="size-4" /> Voorbeeld
            </a>
            <a :href="`/quotes/${quote.id}/pdf`" target="_blank" :class="[BUTTON, OUTLINE]" data-tour="quote.pdf">
                <FileDownIcon class="size-4" /> PDF
            </a>
            <button v-if="canSend" type="button" data-tour="quote.send" @click="sending = true"
                :class="[BUTTON, 'bg-lavoro-blue text-white hover:opacity-90']">
                {{ quote.sent_at ? 'Opnieuw versturen' : 'Versturen' }}
            </button>
            <button v-if="can.update && acceptable" type="button" data-tour="quote.accept" @click="accepting = true"
                :class="[BUTTON, 'border border-green-500 bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400']">
                <CheckIcon class="size-4" /> Akkoord vastleggen
            </button>
            <Link v-if="serviceOrder" :href="`/serviceorders/${serviceOrder.id}`" :class="[BUTTON, OUTLINE]">
                <ClipboardCheckIcon class="size-4" /> Werkbon {{ serviceOrder.number }}
            </Link>
            <button v-else-if="quote.status === 'Geaccepteerd' && can.update && can.createServiceOrder" type="button" @click="convert"
                :class="[BUTTON, 'bg-green-600 text-white hover:bg-green-700']">
                <ClipboardCheckIcon class="size-4" /> Werkbon maken
            </button>
        </div>
    </header>

    <p v-if="!editable && can.update" class="mt-4 rounded-lg bg-slate-100 dark:bg-slate-800 px-4 py-2 text-sm text-gray-600 dark:text-slate-300">
        Deze offerte is {{ quote.status.toLowerCase() }}.
        {{ canStartVersion
            ? 'Kies in het menu Nieuwe versie maken om hem aan te passen, of zet hem terug naar concept om deze versie te verbeteren.'
            : 'Zet hem via het menu terug naar concept om hem aan te passen.' }}
    </p>

    <QuoteSendDrawer v-if="can.update" v-model="sending" :quote-id="quote.id" :defaults="sendDefaults" />
    <QuoteAcceptDialog v-if="can.update" v-model="accepting" :quote-id="quote.id" :lines="lines" :totals="totals" :layout="layout"
        :terms-name="termsOnOffer" :is-consumer="quote.is_consumer"
        :default-name="quote.contact?.full_name ?? ''" :can-create-service-order="can.createServiceOrder" />
    <QuoteDeclineDialog v-if="can.update" v-model="declining" :quote-id="quote.id" :reasons="lostReasons" />
    <QuoteCopyDialog v-if="can.create" :model-value="copying !== null" @update:model-value="(value) => { if (!value) copying = null }"
        :quote="quote" :as-template="copying === 'template'" :customers="customers" :customers-use-ajax="customersUseAjax" />

    <TwoThirdsOneThird class="mt-6">
        <template #main>
            <ChaptersComponent v-model="activeChapter">
                <ChapterHeaders data-tour="quote.chapters">
                    <ChapterHeader v-for="(chapter, index) in chapters" :key="chapter.label" :index="index">
                        <span class="inline-flex items-center gap-2">
                            <component :is="chapter.icon" class="size-4" />
                            {{ chapter.label }}<template v-if="chapter.count"> ({{ chapter.count }})</template>
                        </span>
                    </ChapterHeader>
                </ChapterHeaders>
                <ChapterContents>
                    <template #chapter-0>
                        <BoxComponent padding="px-0 pt-0 pb-2 sm:px-0 sm:pb-2 xl:px-0 xl:pt-0 xl:pb-2" class="@container flow-root" data-tour="quote.lines">
                            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <SectionHeader :icon="FileTextIcon" title="Offerteregels" chapter="financial" flush class="min-w-0 flex-1"
                                    subtitle="Stel hier de inhoud van de offerte samen. Bepaal per regel of deze optioneel is en meetelt in het totaal." />
                                <div class="flex shrink-0 gap-2">
                                    <button v-if="hasPanels" type="button" @click="toggleAllPanels" :class="[SMALL_BUTTON, OUTLINE]">
                                        <ArrowDownIcon class="size-4 transition-transform" :class="allOpen ? 'rotate-180' : ''" />
                                        {{ allOpen ? 'Alles inklappen' : 'Alles uitvouwen' }}
                                    </button>
                                    <button v-if="rows.length > 1" type="button" data-tour="quote.reorder" @click="compact = !compact"
                                        :class="[SMALL_BUTTON, compact ? 'border border-lavoro-blue bg-blue-50 text-lavoro-blue dark:bg-slate-800' : OUTLINE]"
                                        v-tooltip="'Alleen de namen, om makkelijk te verslepen'">
                                        <ArrowDownUpIcon class="size-4" /> Volgorde
                                    </button>
                                </div>
                            </div>

                            <div v-if="rows.length && !compact"
                                class="hidden @4xl:flex items-center gap-2 px-5 pb-1 text-xs font-semibold text-gray-500 dark:text-slate-400">
                                <div class="flex-1" />
                                <div :class="COLUMNS.quantity" class="text-center">Aantal</div>
                                <div :class="COLUMNS.unit">Eenheid</div>
                                <div :class="COLUMNS.price" class="text-right">Prijs</div>
                                <div :class="COLUMNS.vat">Btw</div>
                                <div :class="COLUMNS.total" class="text-right">Totaal</div>
                                <div :class="COLUMNS.actions" />
                            </div>

                            <p v-if="isExample" class="px-5 pb-2 text-xs italic text-amber-700 dark:text-amber-400">
                                Voorbeeld uit de rondleiding: deze regels worden niet bewaard.
                            </p>

                            <draggable v-model="rows" handle=".draghandle" :animation="150" :disabled="!canEdit || isExample"
                                class="space-y-1 px-2 pb-2" @end="reorder">
                                <QuoteLineRow v-for="(line, index) in rows" :key="line.id + '-' + line.updated_at" :quote-id="quote.id"
                                    :line="line" :vat-rates="vatRates" :units="units" :editable="canEdit" :see-margins="can.seeMargins"
                                    :compact="compact" :can-be-alternative="index > 0 && rows[index - 1].kind !== 'text'"
                                    :data-tour="line.id === firstPricedLineId ? 'quote.line' : null"
                                    class="border-t first:border-t-0 border-gray-100 dark:border-slate-800">
                                    <QuoteMaterialsPanel v-if="line.kind === 'product' && !compact && (line.children.length || canEdit)"
                                        :quote-id="quote.id" :product="line" :units="units" :editable="canEdit && !isExample"
                                        :data-tour="line.id === firstProductId ? 'quote.materials' : null"
                                        :open="openPanels[line.id] ?? true" @update:open="(value) => openPanels[line.id] = value" />
                                </QuoteLineRow>
                            </draggable>

                            <div v-if="!rows.length" class="px-5 py-10 text-center text-sm text-gray-400 dark:text-slate-500">
                                <FileTextIcon class="mx-auto mb-2 size-10" />
                                Nog niets aangeboden. Begin met een product, een dienst of een tekst.
                            </div>

                            <QuoteAddBar v-if="canEdit" :quote-id="quote.id" />
                        </BoxComponent>
                    </template>

                    <template #chapter-1>
                        <BoxComponent data-tour="quote.texts">
                            <SectionHeader :icon="FileTextIcon" title="Aanhef & slot" chapter="details"
                                subtitle="Boven en onder de regels, zoals de klant ze leest." />
                            <template v-if="canEdit">
                                <label class="text-xs font-bold text-slate-400">Aanhef</label>
                                <TipTapEditor v-model="texts.intro_text" class="mt-1" :has-error="Boolean(texts.errors.intro_text)" />
                                <label class="mt-4 block text-xs font-bold text-slate-400">Slot</label>
                                <TipTapEditor v-model="texts.closing_text" class="mt-1" :has-error="Boolean(texts.errors.closing_text)" />
                                <p v-for="error in texts.errors" :key="error" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                                <div class="mt-4 flex justify-end">
                                    <button type="button" @click="saveTexts" :disabled="!texts.isDirty || texts.processing"
                                        :class="[BUTTON, 'bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50']">
                                        Opslaan
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div v-if="quote.intro_text" class="rich-text text-sm" v-html="quote.intro_text" />
                                <div v-if="quote.closing_text" class="rich-text mt-4 text-sm" v-html="quote.closing_text" />
                                <p v-if="!quote.intro_text && !quote.closing_text" class="text-sm text-gray-400">Geen aanhef of slot.</p>
                            </template>
                        </BoxComponent>
                    </template>

                    <template #chapter-2>
                        <BoxComponent>
                            <SectionHeader :icon="HandshakeIcon" title="Afspraken" chapter="details"
                                subtitle="Wat de klant over prijs, planning en betaling leest. De installatievoorwaarden vragen dat de offerte dit noemt." />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2" data-tour="quote.pricing">
                                    <span class="text-xs font-bold text-slate-400">Prijsafspraak</span>
                                    <SegmentedToggle v-model="form.pricing_method" class="mt-1" :options="pricingMethods" :disabled="!canEdit"
                                        @update:model-value="save('pricing_method')" />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">De klant leest: {{ pricingExplanation }}</p>
                                </div>
                                <div data-tour="quote.start">
                                    <span class="text-xs font-bold text-slate-400">Start</span>
                                    <TextInput v-model="form.start_period" :disabled="!canEdit" placeholder="Bijvoorbeeld: week 14, in overleg"
                                        @change="save('start_period')" :has-error="Boolean(form.errors.start_period)" :error-message="form.errors.start_period" />
                                </div>
                                <div data-tour="quote.duration">
                                    <span class="text-xs font-bold text-slate-400">Duur</span>
                                    <TextInput v-model="form.duration" :disabled="!canEdit" placeholder="Bijvoorbeeld: twee werkdagen"
                                        @change="save('duration')" :has-error="Boolean(form.errors.duration)" :error-message="form.errors.duration" />
                                </div>
                                <div class="sm:col-span-2" data-tour="quote.payment-terms">
                                    <span class="text-xs font-bold text-slate-400">Betaling</span>
                                    <textarea v-model="form.payment_terms" rows="2" :disabled="!canEdit" @change="save('payment_terms')"
                                        placeholder="Bijvoorbeeld: betaling binnen 14 dagen na factuurdatum"
                                        class="mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2" />
                                </div>
                                <div class="sm:col-span-2" data-tour="quote.payment-schedule">
                                    <span class="text-xs font-bold text-slate-400">Betaaltermijnen</span>
                                    <p v-if="!form.payment_schedule?.length" class="text-xs text-gray-500 dark:text-slate-400">
                                        Geen termijnen: de klant betaalt volgens de afspraak hierboven.
                                    </p>
                                    <QuotePaymentSchedule v-model="form.payment_schedule" :editable="canEdit" :gross="totals.gross"
                                        :is-consumer="form.is_consumer" :max-upfront="maxUpfrontPercent" :error="scheduleError"
                                        @save="save('payment_schedule')" />
                                </div>
                            </div>
                        </BoxComponent>
                    </template>

                    <template #chapter-3>
                        <DocumentUploadComponent :existing="quote.documents" :documentable-id="quote.id" data-tour="quote.attachments"
                            documentable-type="\App\Models\Quote" title="Bijlagen" subtitle="Tekeningen, brochures en andere stukken bij deze offerte." />
                    </template>

                    <template #chapter-4>
                        <BoxComponent>
                            <SectionHeader :icon="SettingsIcon" title="Instellingen" chapter="details"
                                subtitle="Wanneer de offerte gemaakt is, hoe lang hij geldig is, en wat de klant ervan ziet." />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <span class="text-xs font-bold text-slate-400">Offertedatum</span>
                                    <TextInput v-model="form.quote_date" type="date" :disabled="!canEdit" @change="save('quote_date')"
                                        :has-error="Boolean(form.errors.quote_date)" :error-message="form.errors.quote_date" />
                                </div>
                                <div>
                                    <span class="text-xs font-bold text-slate-400">Geldig tot en met</span>
                                    <TextInput v-model="form.valid_until" type="date" :disabled="!canEdit" @change="save('valid_until')"
                                        :has-error="Boolean(form.errors.valid_until)" :error-message="form.errors.valid_until" />
                                </div>
                            </div>
                            <div class="mt-6 border-t border-gray-100 dark:border-slate-800 pt-4" data-tour="quote.layout">
                                <span class="text-xs font-bold text-slate-400">Wat de klant ziet</span>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <label v-for="(label, choice) in layoutChoices" :key="choice" class="flex items-center gap-2 text-sm">
                                        <input v-model="form.layout[choice]" type="checkbox" :disabled="!canEdit"
                                            class="rounded border-gray-300 text-lavoro-blue" @change="save('layout')" />
                                        {{ label }}
                                    </label>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">
                                    Zonder prijs per eenheid en regeltotaal ziet de klant alleen het totaal, zoals bij een vaste prijs gebruikelijk.
                                    De standaard stel je in bij de offerte-instellingen.
                                </p>
                            </div>
                        </BoxComponent>
                    </template>
                </ChapterContents>
            </ChaptersComponent>
        </template>

        <template #sidebar>
            <div class="space-y-4">
                <BoxComponent data-tour="quote.totals">
                    <SectionHeader :icon="EuroIcon" title="Totaal" chapter="financial" />
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between px-3 py-1"><dt>Totaal excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(shownTotals.net) }}</dd></div>
                        <div v-for="row in shownTotals.vat" :key="row.rate" class="flex justify-between px-3 py-1 text-gray-600 dark:text-slate-400">
                            <dt>Btw {{ nlQuantity(row.rate) }}%</dt><dd class="tabular-nums">{{ nlCurrency(row.vat) }}</dd>
                        </div>
                        <div class="flex justify-between rounded-lg bg-green-50 dark:bg-green-900/20 px-3 py-2 text-base font-bold text-green-900 dark:text-green-200">
                            <dt>Totaal incl. btw</dt><dd class="tabular-nums">{{ nlCurrency(shownTotals.gross) }}</dd>
                        </div>
                        <div v-if="shownTotals.provisional_net" class="flex justify-between px-3 pt-2 text-amber-700 dark:text-amber-400">
                            <dt>Waarvan stelposten</dt><dd class="tabular-nums">{{ nlCurrency(shownTotals.provisional_net) }}</dd>
                        </div>
                        <div v-if="shownTotals.optional_net" class="flex justify-between px-3 pt-2 text-sky-700 dark:text-sky-400">
                            <dt>Opties die niet meetellen</dt><dd class="tabular-nums">{{ nlCurrency(shownTotals.optional_net) }}</dd>
                        </div>
                        <template v-if="can.seeMargins">
                            <div class="flex justify-between px-3 pt-2 text-gray-500"><dt>Inkoop</dt><dd class="tabular-nums">{{ nlCurrency(shownTotals.cost) }}</dd></div>
                            <div class="flex justify-between px-3 text-gray-500">
                                <dt>Marge</dt>
                                <dd class="tabular-nums">{{ nlCurrency(shownTotals.margin) }}<span v-if="shownTotals.net"> ({{ marginShare }})</span></dd>
                            </div>
                        </template>
                    </dl>
                </BoxComponent>

                <QuotePreviewPanel :document="preview" :example="example" />

                <BoxComponent v-if="quote.accepted_name">
                    <SectionHeader :icon="PenLineIcon" title="Akkoord" chapter="signoff"
                        :subtitle="`${quote.accepted_name}, ${acceptedVia}, ${nlDate(quote.decided_at)}`" />
                    <img v-if="quote.accepted_signature" :src="quote.accepted_signature" alt="Handtekening"
                        class="w-full rounded border border-gray-200 dark:border-slate-700 bg-white" />
                    <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-slate-300">
                        <li v-if="quote.accepted_terms_name">Algemene voorwaarden geaccepteerd ({{ quote.accepted_terms_name }})</li>
                        <li v-if="coolingOffUntil">
                            Bedenktijd tot en met {{ nlDateMedium(coolingOffUntil) }}.
                            {{ quote.starts_within_cooling_off ? 'De klant vroeg om eerder te beginnen.' : 'Niet eerder beginnen, tenzij de klant erom vraagt.' }}
                        </li>
                    </ul>
                </BoxComponent>

                <BoxComponent v-if="quote.status === 'Afgewezen' && (quote.lost_reason || quote.lost_reason_note)">
                    <SectionHeader :icon="CircleXIcon" title="Afgewezen" chapter="signoff" :subtitle="quote.lost_reason?.name ?? 'Zonder reden'" />
                    <p v-if="quote.lost_reason_note" class="text-sm whitespace-pre-line">{{ quote.lost_reason_note }}</p>
                </BoxComponent>

                <BoxComponent v-if="versions.length" data-tour="quote.versions">
                    <SectionHeader :icon="LayersIcon" title="Versies" chapter="documents"
                        subtitle="Wat de klant elke keer kreeg, zoals het er toen uitzag." />
                    <ul class="divide-y divide-gray-100 dark:divide-slate-800 text-sm">
                        <li v-for="version in versions" :key="version.id" class="flex items-start justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 font-medium">
                                    Versie {{ version.number }}
                                    <span v-if="version.accepted"
                                        class="rounded-full bg-green-100 dark:bg-green-900/40 px-2 py-0.5 text-xs text-green-700 dark:text-green-300">
                                        Geaccepteerd
                                    </span>
                                </div>
                                <div class="truncate text-xs text-gray-500 dark:text-slate-400">
                                    {{ nlDate(version.created_at) }} · {{ version.recipient ?? 'in de app' }}
                                    <template v-if="version.net !== null"> · {{ nlCurrency(version.net) }} excl. btw</template>
                                </div>
                                <div v-if="version.viewed_at || version.reminded_at" class="text-xs text-gray-500 dark:text-slate-400">
                                    <template v-if="version.viewed_at">Bekeken op {{ nlDate(version.viewed_at) }}</template>
                                    <template v-if="version.viewed_at && version.reminded_at"> · </template>
                                    <template v-if="version.reminded_at">herinnerd op {{ nlDate(version.reminded_at) }}</template>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-0.5 text-xs font-semibold">
                                <a :href="`/quotes/${quote.id}/versions/${version.id}`" target="_blank" class="text-lavoro-blue hover:underline">Bekijken</a>
                                <a :href="`/quotes/${quote.id}/versions/${version.id}/pdf`" target="_blank" class="text-lavoro-blue hover:underline">PDF</a>
                            </div>
                        </li>
                    </ul>
                </BoxComponent>

                <BoxComponent v-if="answers.length">
                    <SectionHeader :icon="MessageSquareIcon" title="Reacties van de klant" chapter="remarks" />
                    <div v-for="(answer, index) in answers" :key="index"
                        class="py-2 text-sm border-b last:border-b-0 border-gray-100 dark:border-slate-800">
                        <div class="font-medium">{{ answer.name }} {{ ANSWER_KINDS[answer.kind] }}</div>
                        <div class="text-xs text-gray-500 dark:text-slate-400">{{ nlDate(answer.answered_at) }} · {{ answer.recipient }}</div>
                        <p v-if="answer.reason" class="mt-1">Reden: {{ answer.reason }}</p>
                        <p v-if="answer.message" class="mt-1 whitespace-pre-line">{{ answer.message }}</p>
                    </div>
                </BoxComponent>

                <BoxComponent data-tour="quote.settings">
                    <SectionHeader :icon="SettingsIcon" title="Offerte-instellingen" chapter="details" />
                    <label class="flex items-start justify-between gap-3">
                        <span>
                            <span class="block text-sm font-semibold">Klant mag opties selecteren</span>
                            <span class="block text-xs text-gray-500 dark:text-slate-400">
                                De klant kan optionele onderdelen aan- of uitzetten. Het totaal wordt automatisch aangepast.
                            </span>
                        </span>
                        <SwitchComponent v-model="form.customer_picks_options" :disabled="!canEdit"
                            @update:model-value="save('customer_picks_options')" />
                    </label>
                    <label class="mt-4 flex items-start justify-between gap-3 border-t border-gray-100 dark:border-slate-800 pt-4">
                        <span>
                            <span class="block text-sm font-semibold">Particuliere klant</span>
                            <span class="block text-xs text-gray-500 dark:text-slate-400">
                                De klant krijgt 14 dagen bedenktijd, met het modelformulier voor herroeping bij de offerte.
                            </span>
                        </span>
                        <SwitchComponent v-model="form.is_consumer" :disabled="!canEdit" @update:model-value="save('is_consumer')" />
                    </label>
                    <p v-if="form.errors.is_consumer" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.is_consumer }}</p>
                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-gray-100 dark:border-slate-800 pt-4">
                        <span class="inline-flex items-center gap-1 text-sm">
                            Standaard btw-tarief
                            <InfoIcon class="size-4 text-gray-400" v-tooltip="'Het tarief waarmee nieuwe regels beginnen. Bestaande regels houden het hunne.'" />
                        </span>
                        <select v-model.number="form.default_vat_percent" :disabled="!canEdit" @change="save('default_vat_percent')"
                            :class="[BOXED_FIELD, 'w-24']" aria-label="Standaard btw-tarief">
                            <option v-for="rate in vatRates" :key="rate" :value="rate">{{ rate }}%</option>
                        </select>
                    </div>
                </BoxComponent>

                <BoxComponent>
                    <SectionHeader :icon="InfoIcon" title="Gegevens" chapter="customer" />
                    <div class="flex flex-col gap-3">
                        <div v-if="origin">
                            <span class="text-xs font-bold text-slate-400">Gemaakt vanuit</span>
                            <Link v-if="origin.url" :href="origin.url" class="block text-sm text-lavoro-blue hover:underline">{{ origin.label }}</Link>
                            <p v-else class="text-sm">{{ origin.label }}</p>
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-400">Locatie</span>
                            <ComboBox :options="locationOptions" v-model="form.location_id" placeholder="Hoofdadres van de klant"
                                :disabled="!canEdit" :has-error="Boolean(form.errors.location_id)" :error-message="form.errors.location_id" />
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-400">Contactpersoon</span>
                            <ComboBox :options="contactOptions" v-model="form.contact_id" placeholder="Geen contactpersoon"
                                :disabled="!canEdit" :has-error="Boolean(form.errors.contact_id)" :error-message="form.errors.contact_id" />
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-400">Project</span>
                            <ComboBox :options="projectOptions" v-model="form.project_id" placeholder="Geen project"
                                :disabled="!canEdit" :has-error="Boolean(form.errors.project_id)" :error-message="form.errors.project_id" />
                        </div>
                    </div>
                </BoxComponent>

                <BoxComponent data-tour="quote.timeline">
                    <SectionHeader :icon="HistoryIcon" title="Tijdlijn" chapter="timeline" />
                    <TimelineComponent v-if="quote.activities?.length" :activities="quote.activities" :limit="3" subject-type="App\Models\Quote" />
                    <p v-else class="text-sm text-gray-400 dark:text-slate-500">Nog niets gebeurd.</p>
                </BoxComponent>
            </div>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { MenuItem } from '@headlessui/vue'
import { VueDraggableNext as draggable } from 'vue-draggable-next'
import {
    ArrowDownIcon, ArrowDownUpIcon, BookmarkIcon, CalendarCheckIcon, CalendarIcon, CheckIcon, CircleXIcon, ClipboardCheckIcon,
    CopyIcon, EllipsisIcon, EuroIcon, EyeIcon, FileDownIcon, FileTextIcon, HandshakeIcon, HistoryIcon, InfoIcon, LayersIcon,
    ListIcon, MessageSquareIcon, PaperclipIcon, PenLineIcon, SettingsIcon, Trash2Icon, UserIcon,
} from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ChapterContents from '@/Components/Chapters/ChapterContents.vue'
import ChapterHeader from '@/Components/Chapters/ChapterHeader.vue'
import ChapterHeaders from '@/Components/Chapters/ChapterHeaders.vue'
import ChaptersComponent from '@/Components/Chapters/ChaptersComponent.vue'
import DocumentUploadComponent from '@/Components/DocumentUploadComponent.vue'
import QuoteAcceptDialog from '@/Components/Quotes/QuoteAcceptDialog.vue'
import QuoteAddBar from '@/Components/Quotes/QuoteAddBar.vue'
import QuoteCopyDialog from '@/Components/Quotes/QuoteCopyDialog.vue'
import QuoteDeclineDialog from '@/Components/Quotes/QuoteDeclineDialog.vue'
import QuoteLineRow from '@/Components/Quotes/QuoteLineRow.vue'
import QuoteMaterialsPanel from '@/Components/Quotes/QuoteMaterialsPanel.vue'
import QuotePaymentSchedule from '@/Components/Quotes/QuotePaymentSchedule.vue'
import QuotePreviewPanel from '@/Components/Quotes/QuotePreviewPanel.vue'
import QuoteSendDrawer from '@/Components/Quotes/QuoteSendDrawer.vue'
import { BOXED_FIELD, COLUMNS, menuItem } from '@/Components/Quotes/quoteColumns'
import { useSaveField } from '@/Components/Quotes/useSaveField'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import DropdownMenu from '@/Components/UI/DropdownMenu.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import { temporarily, useTour } from '@/Composables/useTour'
import { exampleQuoteLines } from '@/Tours/examples'
import { nlCurrency, nlDate, nlDateMedium, nlQuantity, quoteStatusPillClass } from '@/Utilities/Utilities'

const props = defineProps({
    quote: { type: Object, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    vatRates: { type: Array, required: true },
    units: { type: Array, default: () => [] },
    answers: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    acceptedVia: { type: String, default: null },
    sendDefaults: { type: Object, required: true },
    serviceOrder: { type: Object, default: null },
    acceptable: { type: Boolean, default: false },
    nextStatuses: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    /** Whether the version being worked on went out: only then can a new one start. */
    versionSent: { type: Boolean, default: false },
    /** The general terms the customer would accept with it, by name. */
    termsOnOffer: { type: String, default: null },
    coolingOffUntil: { type: String, default: null },
    /** What the quote was made from: { label, url }. */
    origin: { type: Object, default: null },
    layout: { type: Object, required: true },
    layoutChoices: { type: Object, required: true },
    /** The quote as the customer reads it, QuoteForCustomer::forPage, for the live preview. */
    preview: { type: Object, required: true },
    /** [{ value, label, explanation }] */
    pricingMethods: { type: Array, default: () => [] },
    maxUpfrontPercent: { type: Number, required: true },
    lostReasons: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    can: { type: Object, required: true },
})

const chapters = computed(() => [
    { label: 'Regels', icon: ListIcon },
    { label: 'Aanhef & slot', icon: FileTextIcon },
    { label: 'Afspraken', icon: HandshakeIcon },
    { label: 'Bijlagen', icon: PaperclipIcon, count: props.quote.documents.length },
    { label: 'Instellingen', icon: SettingsIcon },
])

/** How the sidebar says what the customer answered on their page. */
const ANSWER_KINDS = {
    accepted: 'ging akkoord',
    changes: 'vroeg een wijziging',
    declined: 'wees de offerte af',
}

/** What a plain status button says. Sending and accepting have buttons of their own. */
const STATUS_ACTIONS = {
    Concept: 'Terug naar concept',
    Verzonden: 'Markeer als verzonden',
    Afgewezen: 'Afgewezen',
    Vervallen: 'Vervallen',
}

const BUTTON = 'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors'
const SMALL_BUTTON = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium'
const OUTLINE = 'border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-800'

const sending = ref(false)
const accepting = ref(false)
const declining = ref(false)
/** Which way the quote is being taken along: 'copy', 'template', or not at all. */
const copying = ref(null)
const compact = ref(false)
const activeChapter = ref(0)

const canEdit = computed(() => props.editable && props.can.update)
const canSend = computed(() => props.can.update && props.quote.status !== 'Geaccepteerd')
const canStartVersion = computed(() => props.can.update && props.versionSent && props.quote.status !== 'Geaccepteerd')

/** What the customer reads about the way the price holds, as the server words it for the page and the PDF. */
const pricingExplanation = computed(() => props.pricingMethods.find(method => method.value === form.pricing_method)?.explanation)
const scheduleError = computed(() => Object.entries(form.errors)
    .find(([field]) => field.startsWith('payment_schedule'))?.[1] ?? null)

/** The rondleiding's example lines and their totals, { lines, totals }, while it shows them on a quote without lines. */
const example = ref(null)
const isExample = computed(() => example.value !== null)
const shownTotals = computed(() => example.value?.totals ?? props.totals)

/** The lines in the order they are dragged to; the server's order again after every reload, which also ends an example. */
const rows = ref([...props.lines])
watch(() => props.lines, (lines) => {
    example.value = null
    rows.value = [...lines]
})

/** Which products have their materials open: all, until someone closes one. */
const openPanels = reactive({})
const firstProductId = computed(() => rows.value.find(line => line.kind === 'product')?.id ?? null)
const firstPricedLineId = computed(() => rows.value.find(line => line.kind !== 'text')?.id ?? null)
const firstOptionCheck = () => document.querySelector('[data-tour="quote.lines"] [data-cell=check]')
const firstProvisionalBadge = () => document.querySelector('[data-tour="quote.lines"] [data-cell=provisional]')
const hasPanels = computed(() => firstProductId.value !== null)
const allOpen = computed(() => rows.value.filter(line => line.kind === 'product').every(line => openPanels[line.id] ?? true))

function toggleAllPanels() {
    const open = !allOpen.value
    rows.value.filter(line => line.kind === 'product').forEach(line => { openPanels[line.id] = open })
}

function reorder() {
    router.put(`/quotes/${props.quote.id}/lines/order`, { line_ids: rows.value.map(line => line.id) }, { preserveScroll: true })
}

const form = useForm({
    title: props.quote.title,
    quote_date: props.quote.quote_date,
    valid_until: props.quote.valid_until,
    location_id: props.quote.location_id,
    contact_id: props.quote.contact_id,
    project_id: props.quote.project_id,
    customer_picks_options: props.quote.customer_picks_options,
    default_vat_percent: props.quote.default_vat_percent,
    is_consumer: props.quote.is_consumer,
    layout: { ...props.layout },
    pricing_method: props.quote.pricing_method,
    start_period: props.quote.start_period,
    duration: props.quote.duration,
    payment_terms: props.quote.payment_terms,
    payment_schedule: props.quote.payment_schedule,
})

/**
 * The opening and closing text are saved together with a button: an editor
 * changes with every keystroke, and saving each one would fill the timeline.
 * The server cleans the HTML; what comes back is what the customer will see.
 */
const texts = useForm({ intro_text: props.quote.intro_text ?? '', closing_text: props.quote.closing_text ?? '' })

function saveTexts() {
    texts.patch(`/quotes/${props.quote.id}`, { preserveScroll: true, onSuccess: () => texts.defaults() })
}

const locationOptions = computed(() => (props.quote.customer.locations ?? []).map(location => ({
    id: location.id,
    name: [location.title, location.address, location.city].filter(Boolean).join(', '),
})))
const contactOptions = computed(() => (props.quote.customer.contacts ?? []).map(contact => ({
    id: contact.id,
    name: contact.full_name,
})))
const projectOptions = computed(() => (props.quote.customer.projects ?? []).map(project => ({
    id: project.id,
    name: `${project.number} ${project.title}`,
})))

const marginShare = computed(() => Math.round((shownTotals.value.margin / shownTotals.value.net) * 100) + '%')

const save = useSaveField(form, () => `/quotes/${props.quote.id}`, () => ({ ...props.quote, layout: props.layout }))

/** Picking toggles: clicking the chosen location, contact or project again clears it. */
watch(() => [form.location_id, form.contact_id, form.project_id], () => {
    ;['location_id', 'contact_id', 'project_id'].forEach(save)
})

function moveTo(status) {
    if (status === 'Afgewezen') {
        declining.value = true
        return
    }
    router.patch(`/quotes/${props.quote.id}/status`, { status }, { preserveScroll: true })
}

function startVersion() {
    if (!confirm(`Versie ${props.quote.version + 1} van offerte ${props.quote.number} beginnen? Versie ${props.quote.version} blijft bewaard zoals de klant hem kreeg.`)) return
    router.post(`/quotes/${props.quote.id}/new-version`, {}, { preserveScroll: true })
}

/** Within the cooling-off period, and without the customer asking to start sooner, the werkbon waits: say so first. */
function convert() {
    const waiting = props.coolingOffUntil && !props.quote.starts_within_cooling_off
        ? ` De bedenktijd loopt nog tot en met ${nlDateMedium(props.coolingOffUntil)} en de klant vroeg niet om eerder te beginnen.`
        : ''
    if (!confirm(`Van offerte ${props.quote.number} een werkbon maken?${waiting}`)) return
    router.post(`/quotes/${props.quote.id}/service-order`)
}

function destroy() {
    if (!confirm(`Offerte ${props.quote.number} verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return
    router.delete(`/quotes/${props.quote.id}`)
}

function openChapter(label) {
    const index = chapters.value.findIndex(chapter => chapter.label === label)
    if (index >= 0) activeChapter.value = index
}

/**
 * The offerte rondleiding opens the tab it talks about and the drawer for
 * sending; it never sends or saves anything. A tab is opened without an undo of
 * its own, so going from tab to tab never slides back past the first one:
 * rememberChapter turns back to the tab that was open once the chapter ends.
 * On a quote without lines it shows example lines, in the totals and the
 * preview too, that nothing on the page can save.
 */
useTour('offerte', {
    rememberChapter: () => {
        const before = activeChapter.value

        return () => { activeChapter.value = before }
    },
    openLines: () => openChapter('Regels'),
    openTexts: () => openChapter('Aanhef & slot'),
    openAgreements: () => openChapter('Afspraken'),
    openAttachments: () => openChapter('Bijlagen'),
    openSettings: () => openChapter('Instellingen'),
    openSend: () => canSend.value ? temporarily(sending, true) : null,
    showExampleLines: () => {
        if (props.lines.length || isExample.value) return null
        example.value = exampleQuoteLines()
        rows.value = example.value.lines

        return () => {
            example.value = null
            rows.value = [...props.lines]
        }
    },
}, {
    spots: {
        'quote.line.menu': () => document.querySelector('[data-tour="quote.line"] [data-cell=menu]'),
        'quote.option': () => firstOptionCheck()?.closest('[data-line]') ?? null,
        'quote.option.check': firstOptionCheck,
        'quote.provisional': () => firstProvisionalBadge()?.closest('[data-line]') ?? null,
        'quote.provisional.badge': firstProvisionalBadge,
    },
})
</script>
