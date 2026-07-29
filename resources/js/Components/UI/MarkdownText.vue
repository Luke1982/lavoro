<template>
    <div class="assistant-markdown" @click="onClick" v-html="rendered" />
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import MarkdownIt from 'markdown-it'

/**
 * Renders what the model writes, which is always markdown whether or not anyone
 * asked for it. Shown raw, a list of werkbonnen arrives as a wall of asterisks.
 *
 * html is off, and that is the whole safety story: markdown-it escapes any tag
 * in the source rather than passing it through, so v-html can only ever receive
 * markup this parser produced. Nothing the model writes — and nothing a customer
 * name or a remark dragged in behind it — can become an element on the page.
 * A sanitiser afterwards would be a second, weaker line for the same thing.
 */
const props = defineProps({
    text: { type: String, default: '' },
})

/** So whatever is showing this can get out of the way before the page changes. */
const emit = defineEmits(['navigate'])

const markdown = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
})

const isInternal = (href) => typeof href === 'string' && href.startsWith('/') && !href.startsWith('//')

/**
 * Links to records stay in the app; anything else leaves it.
 *
 * A werkbon the assistant just named is somewhere the reader wants to go, and
 * opening that in a new tab loses the conversation that led them there. An
 * outside link is the opposite case — it is quoted out of a record rather than
 * written by us, so it should not be able to navigate the page out from under
 * somebody mid-answer.
 */
markdown.renderer.rules.link_open = (tokens, index, options, env, self) => {
    const href = tokens[index].attrGet('href')

    if (isInternal(href)) {
        tokens[index].attrSet('data-internal', 'true')
    } else {
        tokens[index].attrSet('target', '_blank')
        tokens[index].attrSet('rel', 'noopener noreferrer')
    }

    return self.renderToken(tokens, index, options)
}

const rendered = computed(() => markdown.render(props.text ?? ''))

/**
 * Caught here rather than bound per link, because the markup is handed over as a
 * string and there are no components in it to attach a handler to.
 */
function onClick(event) {
    const link = event.target.closest('a[data-internal]')

    if (!link) return

    event.preventDefault()
    emit('navigate')
    router.visit(link.getAttribute('href'))
}
</script>

<style scoped>
/**
 * Deliberately quiet. This sits in a small box under a question, so headings are
 * a shade heavier rather than larger — the answer should read as an answer, not
 * as a document.
 */
.assistant-markdown :deep(p) {
    margin-bottom: 0.5rem;
}

.assistant-markdown :deep(> *:last-child) {
    margin-bottom: 0;
}

.assistant-markdown :deep(ul),
.assistant-markdown :deep(ol) {
    margin-bottom: 0.5rem;
    padding-left: 1.15rem;
}

.assistant-markdown :deep(ul) {
    list-style: disc;
}

.assistant-markdown :deep(ol) {
    list-style: decimal;
}

.assistant-markdown :deep(li) {
    margin-bottom: 0.15rem;
}

.assistant-markdown :deep(li > ul),
.assistant-markdown :deep(li > ol) {
    margin-top: 0.15rem;
    margin-bottom: 0;
}

.assistant-markdown :deep(h1),
.assistant-markdown :deep(h2),
.assistant-markdown :deep(h3),
.assistant-markdown :deep(h4) {
    font-weight: 600;
    margin-bottom: 0.35rem;
    color: var(--color-slate-900, #0f172a);
}

.assistant-markdown :deep(strong) {
    font-weight: 600;
    color: var(--color-slate-900, #0f172a);
}

.assistant-markdown :deep(a) {
    color: var(--color-lavoro-blue, #2563ff);
    text-decoration: underline;
}

.assistant-markdown :deep(code) {
    background: var(--color-slate-100, #f1f5f9);
    border-radius: 0.25rem;
    padding: 0.05rem 0.25rem;
    font-size: 0.85em;
}

.assistant-markdown :deep(pre) {
    background: var(--color-slate-100, #f1f5f9);
    border-radius: 0.5rem;
    padding: 0.6rem 0.75rem;
    margin-bottom: 0.5rem;
    overflow-x: auto;
}

.assistant-markdown :deep(pre code) {
    background: none;
    padding: 0;
}

.assistant-markdown :deep(blockquote) {
    border-left: 2px solid var(--color-slate-200, #e2e8f0);
    padding-left: 0.6rem;
    color: var(--color-slate-500, #64748b);
    margin-bottom: 0.5rem;
}

/** Wide output scrolls inside itself rather than stretching the whole box. */
.assistant-markdown :deep(table) {
    display: block;
    overflow-x: auto;
    max-width: 100%;
    margin-bottom: 0.5rem;
    border-collapse: collapse;
}

.assistant-markdown :deep(th),
.assistant-markdown :deep(td) {
    border: 1px solid var(--color-slate-200, #e2e8f0);
    padding: 0.2rem 0.45rem;
    text-align: left;
}

.assistant-markdown :deep(hr) {
    border-color: var(--color-slate-200, #e2e8f0);
    margin: 0.6rem 0;
}
</style>
