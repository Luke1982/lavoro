<template>
    <div class="rounded-lg border overflow-hidden dark:border-slate-600" :class="hasError ? 'ring-1 ring-red-400' : ''">
        <div data-editor-toolbar class="flex items-center gap-1 border-b border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-800 px-2 py-1.5">
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleBold().run()"
                :class="toolbarBtnClass(editor?.isActive('bold'))">
                <BoldIcon class="h-4 w-4" />
            </button>
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleItalic().run()"
                :class="toolbarBtnClass(editor?.isActive('italic'))">
                <ItalicIcon class="h-4 w-4" />
            </button>
            <button type="button" @mousedown.prevent="editor?.chain().focus().toggleBulletList().run()"
                :class="toolbarBtnClass(editor?.isActive('bulletList'))">
                <ListBulletIcon class="h-4 w-4" />
            </button>
            <button v-if="placeholders?.length" type="button" data-editor-placeholders @mousedown.prevent="insertPlaceholderTrigger"
                class="ml-auto px-2 py-1 rounded text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700">
                Plaatshouder invoegen...
            </button>
        </div>
        <EditorContent :editor="editor"
            class="rich-text px-3 py-2 min-h-[10rem] text-sm focus:outline-none [&_.ProseMirror]:focus:outline-none" />
    </div>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Mention from '@tiptap/extension-mention'
import tippy from 'tippy.js'
import { BoldIcon, ItalicIcon, ListBulletIcon } from '@heroicons/vue/24/outline'
import { isInsideTourLayer } from '@/Tours/layer'
import { PLACEHOLDER_CLASS } from '@/Utilities/emailPlaceholders'

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholders: { type: Array, default: null },
    hasError: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

function buildMentionExtension() {
    return Mention.extend({
        name: 'mention',
        parseHTML() {
            return [{ tag: 'span.' + PLACEHOLDER_CLASS }]
        },
        renderHTML({ node, HTMLAttributes }) {
            return ['span', { ...HTMLAttributes, class: PLACEHOLDER_CLASS, contenteditable: 'false' }, node.attrs.id]
        },
        renderText({ node }) {
            return node.attrs.id
        },
    }).configure({
        suggestion: {
            char: '{{',
            items: ({ query }) => (props.placeholders || [])
                .filter((p) => p.label.toLowerCase().includes(query.toLowerCase())),
            render: () => {
                let popup
                let listEl

                /**
                 * What tippy's hideOnClick did, except for a press on the rondleiding:
                 * that is somebody pressing "Volgende" on this very list.
                 */
                function hideOnPressElsewhere(event) {
                    if (!listEl.contains(event.target) && !isInsideTourLayer(event.target)) popup[0].hide()
                }

                function renderItems(items, command) {
                    listEl.innerHTML = ''
                    items.forEach((item) => {
                        const btn = document.createElement('button')
                        btn.type = 'button'
                        btn.textContent = item.label
                        btn.className = 'block w-full text-left px-3 py-1.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700'
                        btn.addEventListener('mousedown', (event) => {
                            event.preventDefault()
                            command({ id: item.token, label: item.label })
                        })
                        listEl.appendChild(btn)
                    })
                }

                return {
                    onStart: (suggestionProps) => {
                        listEl = document.createElement('div')
                        listEl.dataset.editorPlaceholderList = ''
                        listEl.className = 'bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg py-1 min-w-[12rem] max-h-60 overflow-y-auto'
                        renderItems(suggestionProps.items, suggestionProps.command)
                        popup = tippy('body', {
                            getReferenceClientRect: suggestionProps.clientRect,
                            appendTo: () => document.body,
                            content: listEl,
                            showOnCreate: true,
                            interactive: true,
                            trigger: 'manual',
                            hideOnClick: false,
                            placement: 'bottom-start',
                        })
                        document.addEventListener('mousedown', hideOnPressElsewhere, true)
                    },
                    onUpdate: (suggestionProps) => {
                        renderItems(suggestionProps.items, suggestionProps.command)
                        popup[0].setProps({ getReferenceClientRect: suggestionProps.clientRect })
                    },
                    onKeyDown: (suggestionProps) => {
                        if (suggestionProps.event.key === 'Escape') {
                            popup[0].hide()
                            return true
                        }
                        return false
                    },
                    onExit: () => {
                        document.removeEventListener('mousedown', hideOnPressElsewhere, true)
                        popup[0].destroy()
                    },
                }
            },
            command: ({ editor, range, props: item }) => {
                editor.chain().focus().insertContentAt(range, [
                    { type: 'mention', attrs: { id: item.id, label: item.label } },
                    { type: 'text', text: ' ' },
                ]).run()
            },
        },
    })
}

function buildExtensions() {
    const extensions = [StarterKit]
    if (props.placeholders?.length) {
        extensions.push(buildMentionExtension())
    }
    return extensions
}

const editor = useEditor({
    content: props.modelValue,
    extensions: buildExtensions(),
    onUpdate: ({ editor: currentEditor }) => {
        emit('update:modelValue', currentEditor.getHTML())
    },
})

function insertPlaceholderTrigger() {
    editor.value?.chain().focus().insertContent('{{').run()
}

/**
 * Opens the list of placeholders as the button does, for whoever needs to show
 * it. Returns how to put the text back as it was, which also closes the list.
 */
function openPlaceholders() {
    if (!editor.value || !props.placeholders?.length) return null
    const before = editor.value.getHTML()
    insertPlaceholderTrigger()

    return () => editor.value?.commands.setContent(before)
}

defineExpose({ openPlaceholders })

function toolbarBtnClass(active) {
    return [
        'p-1.5 rounded text-sm',
        active ? 'bg-lavoro-blue text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700',
    ]
}

watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value, { emitUpdate: false })
    }
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>
