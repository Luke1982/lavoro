<template>
    <div>
        <div class="p-6 border border-gray-300 rounded-md bg-gray-50 relative pt-8 mt-5">
            <div class="absolute left-4 -top-5 bg-white p-3 border border-gray-400 rounded-full flex">
                <ChatBubbleLeftRightIcon class="h-5 w-5 text-gray-500" aria-hidden="true" />
                <h4 class="text-gray-500 ml-2 text-sm">Opmerkingen</h4>
            </div>
            <ul role="list" class="space-y-6 max-h-[20rem] overflow-y-scroll" v-auto-animate>
                <li v-for="(comment, idx) in comments" :key="comment.id" class="relative flex gap-x-4 bg-gray-100">
                    <div
                        :class="[idx === comments.length - 1 ? 'h-6' : '-bottom-6 top-6', 'absolute left-0 top-0 flex w-6 justify-center']">
                        <div class="w-px bg-gray-200"></div>
                    </div>
                    <div class="flex-auto rounded-md p-3 ring-1 ring-inset ring-gray-200 pr-10 bg-white">
                        <div class="flex justify-between gap-x-4">
                            <div class="py-0.5 text-xs/5 text-gray-500">
                                <span class="font-medium text-gray-900">{{ comment.user.name }}</span> maakte een
                                opmerking
                            </div>
                            <time :datetime="comment.created_at" class="flex-none py-0.5 text-xs/5 text-gray-500">
                                {{ nlDate(comment.created_at) }} {{ nlTime(comment.created_at) }}
                            </time>
                        </div>
                        <p class="text-sm/6 text-gray-500">{{ comment.content }}</p>
                    </div>
                    <TrashIcon v-if="page.props.auth.user.id === comment.user_id"
                        class="h-5 w-5 text-red-400 cursor-pointer absolute top-3 right-3 z-[50]" aria-hidden="true"
                        @click="deleteComment(comment.id)" />
                </li>
            </ul>
            <div class="mt-6 flex gap-x-3">
                <div class="relative flex-auto">
                    <div
                        class="overflow-hidden rounded-lg pb-12 shadow-sm ring-1 ring-inset ring-gray-300 focus-within:ring-2 focus-within:ring-indigo-600">
                        <label for="comment" class="sr-only">Voeg je opmerking toe</label>
                        <textarea rows="2" name="comment" id="comment" v-model="form.content"
                            class="bg-white block w-full resize-none border-0 p-1.5 text-gray-900 placeholder:text-gray-400 focus:ring-0 sm:text-sm/6"
                            placeholder="Plaats een opmerking" />
                    </div>

                    <div class="absolute inset-x-0 bottom-0 flex justify-end py-2 pl-3 pr-2 bg-white">
                        <button type="button" @click="addComment"
                            class="rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">Plaats
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
