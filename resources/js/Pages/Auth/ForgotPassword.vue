<script setup lang="ts">
import InputError from "@/Components/InputError.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";

defineProps<{ status?: string }>();

const form = useForm({ email: "" });
const submit = () => form.post(route("password.email"));
</script>

<template>
    <Head title="Forgot Password" />
    <div class="flex min-h-[100dvh] items-center justify-center bg-stone-100 px-4 py-8 sm:px-6">
        <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl ring-1 ring-stone-900/5 sm:p-10">
            <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">Reset your password</h1>
            <p class="mt-2 text-sm text-stone-500">Enter your email and we will send you a secure password-reset link.</p>
            <p v-if="status" class="mt-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">{{ status }}</p>
            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
                    <input id="email" v-model="form.email" type="email" required autofocus autocomplete="email" placeholder="you@example.com" class="mt-1.5 block w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm" />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>
                <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">{{ form.processing ? "Sending link…" : "Email reset link" }}</button>
                <p class="text-center text-sm text-stone-500">Remembered your password? <Link :href="route('login')" class="font-medium text-teal-700 hover:text-teal-900">Log in</Link></p>
            </form>
        </div>
    </div>
</template>
