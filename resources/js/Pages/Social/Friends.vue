<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

const props = defineProps<{ friends: any[]; requests: any[]; sent: any[]; suggestions: any[] }>();

const suggestions = ref(props.suggestions);
const activeTab = ref<'friends' | 'requests' | 'sent'>(props.requests.length ? 'requests' : 'friends');
const search = ref('');
const busy = ref<Record<string, boolean>>({});

const tabs = computed(() => [
    { key: 'friends' as const, label: 'Friends', count: props.friends.length },
    { key: 'requests' as const, label: 'Requests', count: props.requests.length, highlight: props.requests.length > 0 },
    { key: 'sent' as const, label: 'Sent', count: props.sent.length },
]);

const filteredFriends = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.friends;
    return props.friends.filter((f: any) =>
        `${f.name ?? ''} ${f.username ?? ''}`.toLowerCase().includes(q),
    );
});

const gradients = [
    'from-indigo-500 to-blue-600',
    'from-fuchsia-500 to-pink-600',
    'from-emerald-500 to-teal-600',
    'from-amber-500 to-orange-600',
    'from-sky-500 to-cyan-600',
    'from-violet-500 to-purple-600',
];
const gradientFor = (id: number) => gradients[Math.abs(Number(id) || 0) % gradients.length];
const initial = (name?: string) => (name?.trim()?.[0] ?? '?').toUpperCase();

async function withBusy(key: string, fn: () => Promise<void>) {
    if (busy.value[key]) return;
    busy.value[key] = true;
    try {
        await fn();
    } finally {
        busy.value[key] = false;
    }
}

const refresh = () => router.visit(route('friends'), { preserveScroll: true });

const respond = (id: number, action: 'accept' | 'decline') =>
    withBusy(`req-${id}`, async () => {
        await axios.post(`/friendships/${id}`, { action });
        refresh();
    });

const cancel = (id: number) =>
    withBusy(`sent-${id}`, async () => {
        await axios.delete(`/friendships/${id}`);
        refresh();
    });

const addSuggestion = (person: any) =>
    withBusy(`add-${person.id}`, async () => {
        person.friendship = (await axios.post(`/users/${person.id}/friendships`)).data.data;
    });

const dismiss = (person: any) =>
    withBusy(`dismiss-${person.id}`, async () => {
        await axios.post(`/friends/suggestions/${person.id}/dismiss`);
        suggestions.value = suggestions.value.filter((item: any) => item.id !== person.id);
    });
</script>

<template>
    <AuthenticatedLayout>
        <main class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
            <!-- Header -->
            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Friends</h1>
                    <p class="mt-1 text-sm text-slate-500">Manage your connections and discover new people on PCHAT.</p>
                </div>
                <div class="relative w-full sm:w-72">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" /></svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search your friends"
                        class="w-full rounded-full border-0 bg-white py-2.5 pl-10 pr-4 text-sm shadow-sm ring-1 ring-slate-200 placeholder:text-slate-400 focus:ring-2 focus:ring-blue-500"
                        @focus="activeTab = 'friends'"
                    />
                </div>
            </header>

            <!-- People You May Know -->
            <section v-if="suggestions.length" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">People You May Know</h2>
                    <Link :href="route('friends.suggestions')" class="text-sm font-semibold text-blue-600 hover:text-blue-700 hover:underline">See all</Link>
                </div>

                <div class="-mx-5 mt-4 flex snap-x gap-3 overflow-x-auto px-5 pb-2 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-3">
                    <article
                        v-for="person in suggestions"
                        :key="person.id"
                        class="group relative flex w-64 shrink-0 snap-start flex-col items-center rounded-xl border border-slate-200 bg-gradient-to-b from-slate-50 to-white p-4 text-center transition hover:-translate-y-0.5 hover:shadow-md sm:w-auto"
                    >
                        <button
                            @click="dismiss(person)"
                            :disabled="busy[`dismiss-${person.id}`]"
                            class="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                            aria-label="Dismiss suggestion"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                        </button>

                        <Link :href="route('social.profile', person.id)" class="flex flex-col items-center">
                            <div :class="['grid h-16 w-16 place-items-center overflow-hidden rounded-full bg-gradient-to-br text-xl font-bold text-white ring-4 ring-white shadow', gradientFor(person.id)]">
                                <img v-if="person.avatar_url" :src="person.avatar_url" :alt="person.name" class="h-full w-full object-cover" />
                                <span v-else>{{ initial(person.name) }}</span>
                            </div>
                            <p class="mt-3 max-w-full truncate font-semibold text-slate-900 group-hover:underline">{{ person.name }}</p>
                        </Link>
                        <p class="mt-0.5 text-xs text-slate-500">{{ person.mutual_friends_count }} mutual friends</p>

                        <button
                            v-if="!person.friendship"
                            @click="addSuggestion(person)"
                            :disabled="busy[`add-${person.id}`]"
                            class="mt-3 w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ busy[`add-${person.id}`] ? 'Sending…' : 'Add Friend' }}
                        </button>
                        <span v-else class="mt-3 inline-flex w-full items-center justify-center gap-1 rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-500">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                            Request sent
                        </span>
                    </article>
                </div>
            </section>

            <!-- Tabs -->
            <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-3 sm:px-5" aria-label="Friend lists">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        @click="activeTab = tab.key"
                        :class="[
                            'relative flex shrink-0 items-center gap-2 rounded-t-lg px-4 py-2.5 text-sm font-semibold transition',
                            activeTab === tab.key ? 'text-blue-600' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800',
                        ]"
                        :aria-current="activeTab === tab.key ? 'page' : undefined"
                    >
                        {{ tab.label }}
                        <span
                            :class="[
                                'min-w-[1.5rem] rounded-full px-2 py-0.5 text-xs font-bold',
                                tab.highlight ? 'bg-red-500 text-white' : activeTab === tab.key ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600',
                            ]"
                        >{{ tab.count }}</span>
                        <span v-if="activeTab === tab.key" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-blue-600"></span>
                    </button>
                </nav>

                <div class="p-4 sm:p-5">
                    <!-- Friend Requests -->
                    <template v-if="activeTab === 'requests'">
                        <div v-if="requests.length" class="grid gap-3 sm:grid-cols-2">
                            <article v-for="request in requests" :key="request.id" class="flex flex-col gap-3 rounded-xl border border-slate-200 p-3 transition hover:shadow-sm sm:flex-row sm:items-center">
                                <Link :href="route('social.profile', request.sender.id)" class="flex min-w-0 flex-1 items-center gap-3">
                                    <div :class="['grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br font-bold text-white', gradientFor(request.sender.id)]">
                                        <img v-if="request.sender.avatar_url" :src="request.sender.avatar_url" :alt="request.sender.name" class="h-full w-full object-cover" />
                                        <span v-else>{{ initial(request.sender.name) }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-900 hover:underline">{{ request.sender.name }}</p>
                                        <p class="truncate text-sm text-slate-500">@{{ request.sender.username }}</p>
                                    </div>
                                </Link>
                                <div class="flex gap-2">
                                    <button @click="respond(request.id, 'accept')" :disabled="busy[`req-${request.id}`]" class="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60 sm:flex-none">Confirm</button>
                                    <button @click="respond(request.id, 'decline')" :disabled="busy[`req-${request.id}`]" class="flex-1 rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-60 sm:flex-none">Delete</button>
                                </div>
                            </article>
                        </div>
                        <div v-else class="py-10 text-center">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-2xl">📭</div>
                            <p class="mt-3 font-semibold text-slate-800">No pending friend requests</p>
                            <p class="mt-1 text-sm text-slate-500">New requests will show up here.</p>
                        </div>
                    </template>

                    <!-- Sent Requests -->
                    <template v-else-if="activeTab === 'sent'">
                        <div v-if="sent.length" class="grid gap-3 sm:grid-cols-2">
                            <article v-for="request in sent" :key="request.id" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:shadow-sm">
                                <Link :href="route('social.profile', request.receiver.id)" class="flex min-w-0 flex-1 items-center gap-3">
                                    <div :class="['grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br font-bold text-white', gradientFor(request.receiver.id)]">
                                        <img v-if="request.receiver.avatar_url" :src="request.receiver.avatar_url" :alt="request.receiver.name" class="h-full w-full object-cover" />
                                        <span v-else>{{ initial(request.receiver.name) }}</span>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-900 hover:underline">{{ request.receiver.name }}</p>
                                        <p class="text-sm text-amber-600">Pending…</p>
                                    </div>
                                </Link>
                                <button @click="cancel(request.id)" :disabled="busy[`sent-${request.id}`]" class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-60">
                                    {{ busy[`sent-${request.id}`] ? 'Cancelling…' : 'Cancel' }}
                                </button>
                            </article>
                        </div>
                        <div v-else class="py-10 text-center">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-2xl">✈️</div>
                            <p class="mt-3 font-semibold text-slate-800">No sent requests</p>
                            <p class="mt-1 text-sm text-slate-500">Requests you send will be listed here until accepted.</p>
                        </div>
                    </template>

                    <!-- Your Friends -->
                    <template v-else>
                        <div v-if="filteredFriends.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <Link
                                v-for="friend in filteredFriends"
                                :key="friend.id"
                                :href="route('social.profile', friend.id)"
                                class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:-translate-y-0.5 hover:bg-slate-50 hover:shadow-sm"
                            >
                                <div :class="['grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br font-bold text-white', gradientFor(friend.id)]">
                                    <img v-if="friend.avatar_url" :src="friend.avatar_url" :alt="friend.name" class="h-full w-full object-cover" />
                                    <span v-else>{{ initial(friend.name) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-slate-900">{{ friend.name }}</p>
                                    <p class="truncate text-sm text-slate-500">@{{ friend.username }}</p>
                                </div>
                            </Link>
                        </div>
                        <div v-else class="py-10 text-center">
                            <div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-2xl">{{ search ? '🔍' : '👋' }}</div>
                            <p class="mt-3 font-semibold text-slate-800">{{ search ? 'No friends match your search' : 'No friends yet' }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ search ? 'Try a different name or username.' : 'Start connecting with people on PCHAT.' }}</p>
                        </div>
                    </template>
                </div>
            </section>
        </main>
    </AuthenticatedLayout>
</template>