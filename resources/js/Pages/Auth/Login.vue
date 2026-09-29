<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div
        class="flex min-h-[100dvh] items-center justify-center bg-stone-100 px-4 py-8 sm:px-6"
    >
        <div
            class="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl ring-1 ring-stone-900/5 sm:p-10"
        >
            <div class="text-center sm:text-left">
                <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">
                    Welcome back
                </h1>
                <p class="mt-2 text-sm text-stone-500">
                    Log in with your email and password to continue.
                </p>
            </div>

            <div
                v-if="status"
                class="mt-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
            >
                {{ status }}
            </div>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        inputmode="email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="you@example.com"
                        class="mt-1.5 block w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-sm font-medium text-stone-700">Password</label>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="rounded text-sm font-medium text-teal-700 hover:text-teal-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                        >
                            Forgot password?
                        </Link>
                    </div>
                    <div class="relative mt-1.5">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            class="block w-full rounded-xl border border-stone-300 bg-white py-3 pl-4 pr-16 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm"
                        />
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 rounded-r-xl px-4 text-sm font-medium text-stone-500 hover:text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            {{ showPassword ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <label class="flex cursor-pointer items-center gap-2.5">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        name="remember"
                        class="h-4 w-4 rounded border-stone-300 text-teal-700 focus:ring-teal-600"
                    />
                    <span class="text-sm text-stone-600">Keep me logged in</span>
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex w-full items-center justify-center rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ form.processing ? 'Logging in…' : 'Log in' }}
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

                <p class="text-center text-sm text-stone-600">
                    Don't have an account?
                    <Link
                        :href="route('register')"
                        class="font-semibold text-teal-700 hover:text-teal-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        Register
                    </Link>
                </p>
            </form>
        </div>
    </div>
</template>