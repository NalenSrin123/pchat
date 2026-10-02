<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps<{ notifications: any; preferences: Record<string, boolean> }>();
const items = ref(props.notifications.data || []);
const preferences = ref<Record<string, boolean>>({ post_comments: true, comment_replies: true, post_reactions: true, comment_reactions: true, mentions: true, friend_requests: true, friend_accepted: true, follows: true, ...props.preferences });
const initialOf = (name?: string) => (name?.trim()?.[0] ?? 'P').toUpperCase();

function timeAgo(date: string) {
    const seconds = Math.floor((Date.now() - new Date(date).getTime()) / 1000);
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return days < 7 ? `${days}d ago` : new Date(date).toLocaleDateString();
}
async function open(item: any) {
    if (!item.read_at) {
        await axios.post(`/notifications/${item.id}/read`);
        item.read_at = new Date().toISOString();
    }
    if (item.type === 'friend_request') return window.location.href = route('friends');
    const target = item.target || {};
    window.location.href = target.post_id ? `${route('feed')}?post=${target.post_id}${target.comment_id ? `&comment=${target.comment_id}` : ''}${target.reply_id ? `&reply=${target.reply_id}` : ''}` : route('social.profile', item.person?.id);
}
async function savePreferences() { await axios.put('/notifications/preferences', { preferences: preferences.value }); }
</script>

<template>
    <Head title="Notifications" />
    <AuthenticatedLayout>
        <main class="mx-auto max-w-3xl p-4 sm:p-6">
            <header class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Notifications</h1>
                    <p class="mt-1 text-sm text-slate-500">See all activity for your account.</p>
                </div>
                <Link :href="route('friends')" class="shrink-0 text-sm font-semibold text-blue-600 hover:underline">Friend requests</Link>
            </header>

            <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div v-if="items.length" class="divide-y divide-slate-100">
                    <button v-for="item in items" :key="item.id" @click="open(item)" class="flex w-full items-start gap-3 px-4 py-4 text-left transition hover:bg-slate-50 sm:px-5" :class="!item.read_at ? 'bg-blue-50/70' : ''">
                        <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 font-bold text-white">
                            <img v-if="item.person?.avatar_url" :src="item.person.avatar_url" :alt="item.person?.name" class="h-full w-full object-cover" />
                            <template v-else>{{ initialOf(item.person?.name) }}</template>
                        </span>
                        <span class="min-w-0 flex-1"><span class="block text-sm text-slate-800" :class="!item.read_at ? 'font-semibold' : ''">{{ item.message }}</span><span class="mt-1 block text-xs" :class="!item.read_at ? 'font-semibold text-blue-600' : 'text-slate-500'">{{ timeAgo(item.created_at) }}</span></span>
                        <span v-if="!item.read_at" class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600" aria-label="Unread" />
                    </button>
                </div>
                <div v-else class="px-4 py-14 text-center"><div class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-2xl">🔔</div><p class="mt-3 font-semibold text-slate-800">No notifications yet</p><p class="mt-1 text-sm text-slate-500">We'll let you know when something happens.</p></div>
            </section>

            <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h2 class="font-bold text-slate-900">Notification settings</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <label v-for="[key, label] in [['post_comments', 'Comments on my posts'], ['comment_replies', 'Replies to my comments'], ['post_reactions', 'Reactions to my posts'], ['comment_reactions', 'Reactions to my comments'], ['mentions', 'Mentions'], ['friend_requests', 'Friend requests'], ['friend_accepted', 'Friend request accepted'], ['follows', 'New followers']]" :key="key" class="flex items-center justify-between rounded-lg px-2 py-2 text-sm hover:bg-slate-50"><span>{{ label }}</span><input v-model="preferences[key]" type="checkbox" class="rounded text-blue-600" @change="savePreferences" /></label>
                </div>
            </section>

            <nav v-if="notifications.links?.length > 3" class="mt-5 flex flex-wrap justify-center gap-1" aria-label="Notification pages">
                <Link v-for="link in notifications.links" :key="link.label" :href="link.url || '#'" class="rounded-lg px-3 py-2 text-sm" :class="link.active ? 'bg-blue-600 font-semibold text-white' : link.url ? 'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' : 'cursor-default text-slate-400'" v-html="link.label" />
            </nav>
        </main>
    </AuthenticatedLayout>
</template>
