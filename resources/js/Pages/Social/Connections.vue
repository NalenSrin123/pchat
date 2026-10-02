<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
defineProps<{ profileUser: any; type: 'followers' | 'following'; people: any[] }>();
</script>

<template>
    <AuthenticatedLayout><main class="mx-auto max-w-3xl p-4 sm:p-6"><Link :href="route('social.profile', profileUser.id)" class="text-sm font-semibold text-blue-600 hover:underline">← {{ profileUser.name }}</Link><section class="mt-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"><h1 class="text-2xl font-bold">{{ type === 'followers' ? 'Followers' : 'Following' }}</h1><div v-if="people.length" class="mt-4 divide-y"><Link v-for="person in people" :key="person.id" :href="route('social.profile', person.id)" class="flex items-center gap-3 py-3 hover:bg-slate-50"><div class="grid h-11 w-11 place-items-center rounded-full bg-indigo-600 font-bold text-white">{{ person.name?.[0] }}</div><div><p class="font-semibold">{{ person.name }}</p><p class="text-sm text-slate-500">@{{ person.username }}</p></div></Link></div><p v-else class="mt-4 text-sm text-slate-500">No {{ type }} yet.</p></section></main></AuthenticatedLayout>
</template>
