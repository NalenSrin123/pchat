<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const input =
    'mt-1.5 block w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm';
</script>

<template>
    <Head title="Register" />

    <div
        class="flex min-h-[100dvh] items-center justify-center bg-stone-100 px-4 py-8 sm:px-6"
    >
        <div
            class="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl ring-1 ring-stone-900/5 sm:p-10"
        >
            <div class="text-center sm:text-left">
                <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">
                    Create your account
                </h1>
                <p class="mt-2 text-sm text-stone-500">
                    It only takes a minute to get started.
                </p>
            </div>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-stone-700">Name</label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Full name"
                            :class="input"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <label for="username" class="block text-sm font-medium text-stone-700">Username</label>
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            required
                            autocomplete="username"
                            autocapitalize="none"
                            placeholder="Choose a username"
                            :class="input"
                        />
                        <InputError class="mt-2" :message="form.errors.username" />
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        inputmode="email"
                        required
                        autocomplete="email"
                        placeholder="you@example.com"
                        :class="input"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-stone-700">Password</label>
                    <div class="relative">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Create a password"
                            :class="[input, 'pr-16']"
                        />
                        <button
                            type="button"
                            class="absolute bottom-0 right-0 top-1.5 rounded-r-xl px-4 text-sm font-medium text-stone-500 hover:text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-stone-700">Confirm password</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        autocomplete="new-password"
                        placeholder="Repeat your password"
                        :class="input"
                    />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex w-full items-center justify-center rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ form.processing ? 'Creating account…' : 'Create account' }}
                </button>

                <div class="flex items-center gap-3 text-xs text-stone-400">
                    <span class="h-px flex-1 bg-stone-200" />
                    or
                    <span class="h-px flex-1 bg-stone-200" />
                </div>

                <a
                    :href="route('google.redirect')"
                    class="flex w-full items-center justify-center gap-3 rounded-xl border border-stone-300 bg-white px-4 py-3 text-sm font-medium text-stone-700 transition hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                >
                    <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
                        <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8z" />
                        <path fill="#34A853" d="M12 24c3.2 0 6-1.1 7.9-2.9l-3.9-3c-1.1.7-2.4 1.2-4 1.2-3.1 0-5.7-2.1-6.6-4.9H1.4v3.1A12 12 0 0 0 12 24z" />
                        <path fill="#FBBC05" d="M5.4 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.4a12 12 0 0 0 0 10.8l4-3.1z" />
                        <path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.4 6.6l4 3.1C6.3 6.9 8.9 4.8 12 4.8z" />
                    </svg>
                    Continue with Google
                </a>

                <p class="text-center text-sm text-stone-500">
                    Already have an account?
                    <Link
                        :href="route('login')"
                        class="rounded font-medium text-teal-700 hover:text-teal-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        Log in
                    </Link>
                </p>
            </form>
        </div>
    </div>
</template>