<script setup lang="ts">
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head title="Profile" />

    <div class="min-h-[100dvh] bg-stone-100">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4 sm:px-6">
                <Link href="/messenger" class="flex items-center gap-2.5 text-stone-900">
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-teal-700 text-lg font-black text-white">P</span>
                    <span class="text-xl font-bold tracking-tight">Pulse</span>
                </Link>
                <Link href="/messenger" class="rounded-xl px-3 py-2 text-sm font-semibold text-stone-600 transition hover:bg-stone-100 hover:text-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600">
                    Back to chats
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
            <div class="mb-7">
                <p class="text-sm font-semibold text-teal-700">Account settings</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight text-stone-900">Your profile</h1>
                <p class="mt-2 text-sm text-stone-500">Manage the details people see when they chat with you.</p>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div class="space-y-5">
                    <section class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-stone-900/5 sm:p-7">
                        <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
                    </section>
                    <section class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-stone-900/5 sm:p-7">
                        <UpdatePasswordForm />
                    </section>
                </div>

                <aside class="h-fit rounded-3xl border border-rose-100 bg-rose-50/60 p-5 sm:p-6">
                    <DeleteUserForm />
                </aside>
            </div>
        </main>
    </div>
</template>
