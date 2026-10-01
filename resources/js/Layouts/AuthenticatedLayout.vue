<script setup lang="ts">
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div class="min-h-screen bg-stone-50 text-stone-900">
        <header class="border-b border-stone-200 bg-white">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4" aria-label="Primary navigation">
                <Link href="/" class="text-xl font-black tracking-tight text-teal-800">PCHAT</Link>

                <div class="hidden items-center gap-1 text-sm font-semibold sm:flex">
                    <Link :href="route('feed')" class="rounded-lg px-3 py-2 text-stone-600 hover:bg-teal-50 hover:text-teal-800" :class="route().current('feed') ? 'bg-teal-50 text-teal-800' : ''">Feed</Link>
                    <Link :href="route('dashboard')" class="rounded-lg px-3 py-2 text-stone-600 hover:bg-teal-50 hover:text-teal-800" :class="route().current('dashboard') ? 'bg-teal-50 text-teal-800' : ''">Messenger</Link>
                    <Link :href="route('saved')" class="rounded-lg px-3 py-2 text-stone-600 hover:bg-teal-50 hover:text-teal-800" :class="route().current('saved') ? 'bg-teal-50 text-teal-800' : ''">Saved</Link>
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button type="button" class="ml-2 inline-flex items-center rounded-lg bg-teal-700 px-3 py-2 text-sm font-bold text-white hover:bg-teal-800">
                                {{ $page.props.auth.user.name }}
                                <svg class="ml-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.09 1.03l-4.25 4.5a.75.75 0 0 1-1.09 0l-4.25-4.5a.75.75 0 0 1 .02-1.05Z" clip-rule="evenodd" /></svg>
                            </button>
                        </template>
                        <template #content>
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                        </template>
                    </Dropdown>
                </div>

                <button type="button" class="grid h-10 w-10 place-items-center rounded-lg text-teal-800 hover:bg-teal-50 sm:hidden" aria-label="Toggle navigation" @click="showingNavigationDropdown = !showingNavigationDropdown">
                    <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" /></svg>
                </button>
            </nav>

            <div v-if="showingNavigationDropdown" class="border-t border-stone-100 px-5 py-3 sm:hidden">
                <div class="mx-auto grid max-w-6xl gap-1 text-sm font-semibold">
                    <Link :href="route('feed')" class="rounded-lg px-3 py-2 hover:bg-teal-50 hover:text-teal-800">Feed</Link>
                    <Link :href="route('dashboard')" class="rounded-lg px-3 py-2 hover:bg-teal-50 hover:text-teal-800">Messenger</Link>
                    <Link :href="route('saved')" class="rounded-lg px-3 py-2 hover:bg-teal-50 hover:text-teal-800">Saved</Link>
                    <Link :href="route('profile.edit')" class="rounded-lg px-3 py-2 hover:bg-teal-50 hover:text-teal-800">Profile</Link>
                    <Link :href="route('logout')" method="post" as="button" class="rounded-lg px-3 py-2 text-left hover:bg-teal-50 hover:text-teal-800">Log Out</Link>
                </div>
            </div>
        </header>

        <header v-if="$slots.header" class="border-b border-stone-200 bg-white">
            <div class="mx-auto max-w-6xl px-5 py-6"><slot name="header" /></div>
        </header>

        <main><slot /></main>
    </div>
</template>
