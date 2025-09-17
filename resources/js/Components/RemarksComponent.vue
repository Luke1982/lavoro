<template>
    <div>
        <div
            class="p-6 border border-gray-300 dark:border-slate-700/60 rounded-md bg-gray-50 dark:bg-slate-900 relative pt-8 mt-5">
            <div
                class="absolute left-4 -top-5 bg-white dark:bg-slate-900 p-3 border border-gray-400 dark:border-slate-600 rounded-full flex">
                <ChatBubbleLeftRightIcon class="h-5 w-5 text-gray-500 dark:text-slate-400" aria-hidden="true" />
                <h4 class="text-gray-500 dark:text-slate-300 ml-2 text-sm">Opmerkingen</h4>
            </div>
            <ul role="list" class="space-y-6 max-h-[20rem] overflow-y-scroll" v-auto-animate>
                <li v-for="(comment, idx) in comments" :key="comment.id"
                    class="relative flex gap-x-4 bg-gray-100 dark:bg-slate-800/40">
                    <div
                        :class="[idx === comments.length - 1 ? 'h-6' : '-bottom-6 top-6', 'absolute left-0 top-0 flex w-6 justify-center']">
                        <div class="w-px bg-gray-200 dark:bg-slate-700"></div>
                    </div>
                    <div
                        class="flex-auto rounded-md p-3 ring-1 ring-inset ring-gray-200 dark:ring-slate-700/60 pr-10 bg-white dark:bg-slate-800">
                        <div class="flex justify-between gap-x-4">
                            <div class="py-0.5 text-xs/5 text-gray-500 dark:text-slate-400">
                                <span class="font-medium text-gray-900 dark:text-slate-200">{{ comment.user.name
                                    }}</span> maakte een
                                opmerking
                            </div>
                            <time :datetime="comment.created_at"
                                class="flex-none py-0.5 text-xs/5 text-gray-500 dark:text-slate-500">
                                {{ nlDate(comment.created_at) }} {{ nlTime(comment.created_at) }}
                            </time>
                        </div>
                        <p class="text-sm/6 text-gray-500 dark:text-slate-300">{{ comment.content }}</p>
                    </div>
                    <TrashIcon v-if="page.props.auth.user.id === comment.user_id"
                        class="h-5 w-5 text-red-400 dark:text-red-400 cursor-pointer absolute top-3 right-3 z-[50]"
                        aria-hidden="true" @click="deleteComment(comment.id)" />
                </li>
            </ul>
            <div class="mt-6 flex gap-x-3">
                <div class="relative flex-auto">
                    <div
                        class="overflow-hidden rounded-lg pb-12 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-slate-700/60 focus-within:ring-2 focus-within:ring-indigo-600 dark:focus-within:ring-indigo-500 dark:bg-slate-800">
                        <label for="comment" class="sr-only">Voeg je opmerking toe</label>
                        <textarea rows="2" name="comment" id="comment" v-model="form.content"
                            class="bg-white dark:bg-slate-800 block w-full resize-none border-0 p-1.5 text-gray-900 dark:text-slate-100 placeholder:text-gray-400 dark:placeholder:text-slate-500 focus:ring-0 sm:text-sm/6"
                            placeholder="Plaats een opmerking" />
                    </div>
                    <div
                        class="absolute inset-x-0 bottom-0 flex justify-end py-2 pl-3 pr-2 bg-white dark:bg-slate-800/95">
                        <button type="button" @click="addComment"
                            class="rounded-md bg-white dark:bg-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-gray-900 dark:text-white shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-0 hover:bg-gray-50 dark:hover:bg-indigo-500">Plaats
                            opmerking</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { usePage, useForm } from '@inertiajs/vue3';
import { ChatBubbleLeftRightIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { nlDate, nlTime } from '@/Utilities/Utilities';

const { comments, remarkableType, remarkableId } = defineProps({
    comments: Array,
    remarkableType: String,
    remarkableId: Number
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
