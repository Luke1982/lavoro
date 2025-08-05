<template>
    <div class="flex min-h-full flex-1 h-full">
        <div class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-6 lg:flex-none lg:px-20 xl:px-24 bg-white">
            <div class="mx-auto w-full max-w-sm lg:w-96">
                <div>
                    <img class="h-10 w-auto" src="https://susan.nu/onewebstatic/81ba2bac65.jpg" alt="Stedelijk water" />
                    <img class="h-10 w-auto"
                        src="https://nlgaat.nu/wp-content/themes/nlgaat/assets/img/flowfabriek-logo.png"
                        alt="NLgaat.nl" />
                    <h2 class="mt-8 text-2xl/9 font-bold tracking-tight text-gray-900">Log in</h2>
                </div>

                <div class="mt-10">
                    <div>
                        <form class="space-y-6" @submit.prevent="login">
                            <div>
                                <label for="email" class="block text-sm/6 font-medium text-gray-900">Email
                                    adres</label>
                                <div class="mt-2">
                                    <input v-model="loginForm.email" name="email" type="email" autocomplete="email"
                                        required=""
                                        class="text-black block w-full rounded-md border-0 py-1.5 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6" />
                                </div>
                            </div>

                            <div>
                                <label for="password" class="block text-sm/6 font-medium text-gray-900">Password</label>
                                <div class="mt-2">
                                    <input v-model="loginForm.password" name="password" type="password"
                                        autocomplete="current-password" required=""
                                        class="text-black block w-full rounded-md border-0 py-1.5 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6" />
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" @click="login"
                                    class="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm/6 font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Log
                                    in</button>
                            </div>
                            <div v-if="loginForm.errors" class="text-red-700">
                                <span v-for="error in loginForm.errors" :key="error">{{ error }}</span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="relative hidden w-0 flex-1 lg:block">
            <img class="absolute inset-0 h-full w-full object-cover"
                src="https://unsplash.com/photos/B9Ty9YPIme4/download?ixid=M3wxMjA3fDB8MXxzZWFyY2h8M3x8cmFpbnBpcGV8ZW58MHx8fHwxNzMwNzkzNjY0fDA&force=true&w=1920"
                alt="" />
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
const loginForm = useForm({
    email: '',
    password: '',
});


const login = () => loginForm.post('/login');
</script>

<script>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
export default {
    layout: EmptyLayout,
};
</script>
