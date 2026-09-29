<script setup lang="ts">
import { computed } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";

const props = defineProps<{ status?: string }>();
const form = useForm({});
const submit = () => form.post(route("verification.send"));
const verificationLinkSent = computed(() => props.status === "verification-link-sent");
</script>

<template>
    <Head title="Email Verification" />
    <div class="flex min-h-[100dvh] items-center justify-center bg-stone-100 px-4 py-8 sm:px-6">
        <div class="w-full max-w-md rounded-3xl bg-white p-6 shadow-xl ring-1 ring-stone-900/5 sm:p-10">
            <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">Verify your email</h1>
            <p class="mt-2 text-sm text-stone-500">We sent a verification link to your email. Open it to activate your account.</p>
            <p v-if="verificationLinkSent" class="mt-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">A new verification link has been sent.</p>
            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <button type="submit" :disabled="form.processing" class="flex w-full items-center justify-center rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">{{ form.processing ? "Sending…" : "Resend verification email" }}</button>
                <Link :href="route('logout')" method="post" as="button" class="w-full rounded-xl border border-stone-300 px-4 py-3 text-sm font-medium text-stone-700 transition hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">Log out</Link>
            </form>
        </div>
    </div>
</template>
