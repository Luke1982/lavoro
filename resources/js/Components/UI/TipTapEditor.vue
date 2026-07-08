<template>
    <div class="rounded-lg border overflow-hidden dark:border-slate-600" :class="hasError ? 'ring-1 ring-red-400' : ''">
        <div class="flex items-center gap-1 border-b border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-800 px-2 py-1.5">
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
            <button v-if="placeholders?.length" type="button" @mousedown.prevent="insertPlaceholderTrigger"
                class="ml-auto px-2 py-1 rounded text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700">
                Plaatshouder invoegen...
            </button>
        </div>
        <EditorContent :editor="editor"
            class="prose prose-sm max-w-none dark:prose-invert px-3 py-2 min-h-[10rem] focus:outline-none [&_.ProseMirror]:focus:outline-none" />
    </div>
</template>

<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Mention from '@tiptap/extension-mention'
import tippy from 'tippy.js'
import { BoldIcon, ItalicIcon, ListBulletIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholders: { type: Array, default: null },
    hasError: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

function buildMentionExtension() {
    return Mention.extend({
        name: 'mention',
        renderHTML({ node, HTMLAttributes }) {
            return ['span', { ...HTMLAttributes, class: 'standard-email-placeholder', contenteditable: 'false' }, node.attrs.id]
        },
        renderText({ node }) {
            return node.attrs.id
        },
    }).configure({
        suggestion: {
            char: '{{',
            items: ({ query }) => (props.placeholders || [])
                .filter((p) => p.label.toLowerCase().includes(query.toLowerCase()))
                .slice(0, 10),
            render: () => {
                let popup
                let listEl

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
                        listEl.className = 'bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg py-1 min-w-[12rem] max-h-60 overflow-y-auto'
                        renderItems(suggestionProps.items, suggestionProps.command)
                        popup = tippy('body', {
                            getReferenceClientRect: suggestionProps.clientRect,
                            appendTo: () => document.body,
                            content: listEl,
                            showOnCreate: true,
                            interactive: true,
                            trigger: 'manual',
                            placement: 'bottom-start',
                        })
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

function toolbarBtnClass(active) {
    return [
        'p-1.5 rounded text-sm',
        active ? 'bg-lavoro-blue text-white' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-700',
    ]
}

watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value, false)
    }
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>
