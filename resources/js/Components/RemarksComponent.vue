<template>
    <div v-if="comments.length > 0 || !disabled">
        <div class="flex items-center gap-x-3 mb-6">
            <div class="flex items-center justify-center w-11 h-11 rounded-lavoro-sm bg-lavoro-blue flex-none">
                <ChatBubbleLeftRightIcon class="h-5 w-5 text-white" />
            </div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-slate-100 flex items-center gap-x-2">
                Opmerkingen
                <span v-if="comments.length > 0"
                    class="inline-flex items-center rounded-lavoro-sm bg-lavoro-blue/20 text-lavoro-blue text-xs font-medium px-2 py-1">
                    {{ comments.length }}
                </span>
            </h3>
        </div>

        <ul role="list" v-auto-animate>
            <li v-for="(comment, idx) in comments" :key="comment.id" class="relative flex gap-x-3 pb-6">
                <div v-if="idx !== comments.length - 1"
                    class="absolute left-5 top-10 bottom-0 w-0.5 bg-gray-200 dark:bg-slate-700" />

                <div
                    class="relative flex h-10 w-10 flex-none items-center justify-center rounded-full bg-lavoro-blue/20 border-gray-300 border-1">
                    <ChatBubbleLeftRightIcon class="h-4 w-4 text-lavoro-blue" />
                </div>

                <div class="flex-auto min-w-0">
                    <div class="flex justify-between gap-x-4 mb-2">
                        <div class="flex items-center gap-x-2 min-w-0">
                            <div
                                class="flex items-center justify-center w-6 h-6 rounded-full bg-gray-200 dark:bg-slate-600 flex-none">
                                <span class="text-xs font-medium text-gray-600 dark:text-slate-200">{{
                                    initials(comment.user.name) }}</span>
                            </div>
                            <span class="text-sm font-semibold text-gray-900 dark:text-slate-100 truncate">{{
                                comment.user.name }}</span>
                            <span class="text-sm text-gray-500 dark:text-slate-400 hidden sm:inline">maakte een
                                opmerking</span>
                        </div>
                        <div class="flex items-center gap-x-2 flex-none">
                            <time :datetime="comment.created_at" class="text-xs text-gray-400 dark:text-slate-500">
                                {{ nlDate(comment.created_at) }} {{ nlTime(comment.created_at) }}
                            </time>
                            <button v-if="page.props.auth.user.id === comment.user_id && !disabled"
                                @click="deleteComment(comment.id)"
                                class="p-0.5 rounded text-gray-400 hover:text-red-500 dark:text-slate-500 dark:hover:text-red-400 transition-colors">
                                <Trash2Icon class="h-4 w-4 text-red-500" />
                            </button>
                        </div>
                    </div>
                    <div
                        class="rounded-lavoro-sm bg-gray-50 dark:bg-slate-800/60 border border-gray-200 dark:border-slate-700/40 px-4 py-3">
                        <p class="text-sm text-gray-700 dark:text-slate-300">{{ comment.content }}</p>
                    </div>
                    <div v-if="idx !== comments.length - 1"
                        class="mt-5 border-b border-gray-100 dark:border-slate-800" />
                </div>
            </li>
        </ul>

        <div v-if="!disabled" class="flex gap-x-3 items-start mt-2">
            <div
                class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-200 dark:bg-slate-600 flex-none">
                <span class="text-sm font-medium text-gray-600 dark:text-slate-200">{{
                    initials(page.props.auth.user.name) }}</span>
            </div>

            <div
                class="flex-auto rounded-2xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
                <label for="remark-input" class="sr-only">Voeg je opmerking toe</label>
                <textarea id="remark-input" v-model="form.content" rows="2" placeholder="Plaats een opmerking..."
                    class="block w-full resize-none border-0 bg-transparent px-4 pt-3 pb-1 text-sm text-gray-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-slate-500 focus:ring-0" />
                <div class="flex justify-end px-3 pb-3">
                    <button type="button" @click="addComment"
                        class="inline-flex items-center gap-x-1.5 rounded-lavoro-sm bg-lavoro-blue p-2.5 mt-2 text-sm font-semibold text-white hover:bg-lavoro-blue/85 transition-colors">
                        Opmerking plaatsen
                        <PaperAirplaneIcon class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { usePage, useForm } from '@inertiajs/vue3';
import { ChatBubbleLeftRightIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline';
import { nlDate, nlTime, initials } from '@/Utilities/Utilities';
import { Trash2Icon } from '@lucide/vue';

const { comments, remarkableType, remarkableId, disabled } = defineProps({
    comments: Array,
    remarkableType: String,
    remarkableId: Number,
    disabled: {
        type: Boolean,
        default: false
    }
})

const page = usePage();
const form = useForm({
    content: '',
    user_id: page.props.auth.user.id,
    remarkable_type: remarkableType,
    remarkable_id: remarkableId
})

const addComment = async () => {
    form.post('/remarks', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('content');
        },
    })
}

const deleteComment = async (id) => {
    form.delete(`/remarks/${id}`, {
        preserveScroll: true
    })
}
</script>
