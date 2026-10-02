<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
const props = defineProps<{ suggestions: any }>(); const people = ref(props.suggestions.data || []);
async function add(person: any) { person.friendship = (await axios.post(`/users/${person.id}/friendships`)).data.data; }
async function dismiss(person: any) { await axios.post(`/friends/suggestions/${person.id}/dismiss`); people.value = people.value.filter((item: any) => item.id !== person.id); }
</script>
<template><Head title="People You May Know"/><AuthenticatedLayout><main class="mx-auto max-w-4xl p-4 sm:p-6"><h1 class="text-2xl font-bold">People You May Know</h1><div v-if="people.length" class="mt-5 grid gap-4 sm:grid-cols-2"><article v-for="person in people" :key="person.id" class="flex gap-3 rounded-xl bg-white p-4 shadow-sm"><div class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-indigo-600 font-bold text-white"><img v-if="person.avatar_url" :src="person.avatar_url" class="h-full w-full object-cover"><span v-else>{{person.name?.[0]}}</span></div><div class="flex-1"><Link :href="route('social.profile',person.id)" class="font-semibold">{{person.name}}</Link><p class="text-sm text-slate-500">{{person.mutual_friends_count}} mutual friends</p><button v-if="!person.friendship" @click="add(person)" class="mt-2 rounded bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white">Add Friend</button><span v-else class="text-sm font-semibold text-slate-500">Request sent</span></div><button @click="dismiss(person)" class="self-start text-slate-400">×</button></article></div><p v-else class="mt-5 rounded-xl bg-white p-10 text-center text-slate-500">No new friend suggestions right now.</p></main></AuthenticatedLayout></template>
