<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { echo } from '@laravel/echo-vue';

type User = { id: number; name: string; username: string; email: string; avatar_url: string | null; is_online?: boolean };
type Reaction = { emoji: string; user_id: number };
type ReadReceipt = { user_id: number; message_id: number | null; read_at: string | null };
type Message = {
    id: number; conversation_id: number; sender_id: number; message: string | null;
    type: 'text' | 'image' | 'file'; file_name?: string; file_size?: number; file_url?: string;
    created_at: string; edited_at?: string | null; deleted_at?: string | null; sender: User;
    reply_to?: { id: number; message: string; sender: string } | null; reactions: Reaction[];
};
type Conversation = {
    id: number; type: 'private' | 'group'; name: string; avatar_url: string | null;
    members: User[]; last_message: Message | null; unread_count: number;
};

const props = defineProps<{ conversations: Conversation[]; currentUser: User }>();

const conversations = ref<Conversation[]>(props.conversations);
const active = ref<Conversation | null>(null);
const messages = ref<Message[]>([]);
const draft = ref('');
const filter = ref('');          // filters the conversation list in the sidebar
const query = ref('');           // searches users inside the modal
const people = ref<User[]>([]);
const showSearch = ref(false);
const showGroup = ref(false);
const groupName = ref('');
const selected = ref<User[]>([]);
const attachment = ref<File | null>(null);
const reply = ref<Message | null>(null);
const busy = ref(false);
const readReceipts = ref<ReadReceipt[]>([]);
const notification = ref<{ conversation: Conversation; message: Message } | null>(null);
const loading = ref(false);
const chat = ref<HTMLElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);

const palette = ['bg-teal-100 text-teal-800', 'bg-amber-100 text-amber-800', 'bg-sky-100 text-sky-800', 'bg-rose-100 text-rose-800', 'bg-violet-100 text-violet-800', 'bg-lime-100 text-lime-800'];
const initials = (name: string) => name.split(' ').filter(Boolean).map(x => x[0]).join('').slice(0, 2).toUpperCase();
const tone = (seed: string) => palette[[...seed].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length];
const time = (v?: string) => v ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(v)) : '';
const dayLabel = (v: string) => {
    const d = new Date(v), today = new Date(), y = new Date();
    y.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(d);
};
const peer = (c: Conversation) => c.type === 'private' ? c.members.find(u => u.id !== props.currentUser.id) : undefined;
const preview = (c: Conversation) => {
    const m = c.last_message;
    if (!m) return 'No messages yet';
    if (m.deleted_at) return 'Message deleted';
    const body = m.type === 'image' ? 'Photo' : m.type === 'file' ? (m.file_name ?? 'File') : (m.message ?? '');
    return (m.sender_id === props.currentUser.id ? 'You: ' : '') + body;
};
const fileSize = (n?: number) => !n ? '' : n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

const filtered = computed(() => {
    const q = filter.value.trim().toLowerCase();
    return q ? conversations.value.filter(c => c.name.toLowerCase().includes(q)) : conversations.value;
});
const status = computed(() => {
    if (!active.value) return '';
    if (active.value.type === 'group') return `${active.value.members.length} members`;
    return peer(active.value)?.is_online ? 'Online' : 'Offline';
});
const isOnline = computed(() => !!active.value && active.value.type === 'private' && !!peer(active.value)?.is_online);

// messages with a day marker and grouping info
const rows = computed(() => messages.value.map((m, i) => {
    const prev = messages.value[i - 1];
    const newDay = !prev || new Date(prev.created_at).toDateString() !== new Date(m.created_at).toDateString();
    const first = newDay || prev.sender_id !== m.sender_id;
    return { m, newDay, first, mine: m.sender_id === props.currentUser.id };
}));
const grouped = (m: Message) => {
    const map = new Map<string, number>();
    m.reactions.forEach(r => map.set(r.emoji, (map.get(r.emoji) ?? 0) + 1));
    return [...map].map(([emoji, count]) => ({ emoji, count, mine: m.reactions.some(r => r.emoji === emoji && r.user_id === props.currentUser.id) }));
};
const readStatus = (message: Message) => {
    if (message.sender_id !== props.currentUser.id) return null;
    const seen = readReceipts.value.filter(receipt => receipt.user_id !== props.currentUser.id && (receipt.message_id ?? 0) >= message.id);
    if (!seen.length) return 'Delivered';
    const latest = seen.reduce((value, receipt) => !value || (receipt.read_at ?? '') > (value.read_at ?? '') ? receipt : value, seen[0]);
    return active.value?.type === 'group' ? `Seen by ${seen.length} · ${time(latest.read_at ?? undefined)}` : `Seen · ${time(latest.read_at ?? undefined)}`;
};
let notificationTimer: number | undefined;
function notify(conversation: Conversation, message: Message) {
    notification.value = { conversation, message };
    window.clearTimeout(notificationTimer);
    notificationTimer = window.setTimeout(() => notification.value = null, 5000);
    if ('Notification' in window && Notification.permission === 'granted') new Notification(conversation.name, { body: message.message || (message.type === 'image' ? 'Sent a photo' : 'Sent a file') });
}


let searchTimer: number | undefined;
watch(query, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(async () => {
        if (!query.value.trim()) { people.value = []; return; }
        people.value = (await axios.get('/messenger/users/search', { params: { q: query.value } })).data.data
            .filter((user: User) => user.id !== props.currentUser.id);
    }, 250);
});
watch(draft, () => nextTick(fit));
function fit() {
    const el = composer.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 128) + 'px';
}
const scrollDown = (smooth = false) => nextTick(() => chat.value?.scrollTo({ top: chat.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }));

const listeningIds = new Set<number>();
function subscribe(c: Conversation) {
    if (listeningIds.has(c.id)) return;
    listeningIds.add(c.id);
    echo().private('conversation.' + c.id).listen('.message.sent', (event: { message: Message }) => {
        if (event.message.sender_id !== props.currentUser.id) {
            c.last_message = event.message;
            if (active.value?.id === c.id) {
                if (!messages.value.some(m => m.id === event.message.id)) messages.value.push(event.message);
                axios.post(`/messenger/conversations/${c.id}/read`, { message_id: event.message.id });
                scrollDown(true);
            } else {
                c.unread_count++;
                notify(c, event.message);
            }
        }
    }).listen('.message.read', (event: ReadReceipt) => {
        if (active.value?.id !== c.id) return;
        const index = readReceipts.value.findIndex(receipt => receipt.user_id === event.user_id);
        if (index < 0) readReceipts.value.push(event);
        else if ((event.message_id ?? 0) > (readReceipts.value[index].message_id ?? 0)) readReceipts.value[index] = event;
    });
}
async function open(c: Conversation) {
    subscribe(c);
    active.value = c;
    messages.value = [];
    reply.value = null;
    attachment.value = null;
    loading.value = true;
    try {
        const { data } = await axios.get(`/messenger/conversations/${c.id}/messages`);
        messages.value = data.data;
        c.unread_count = 0;
        readReceipts.value = data.read_receipts;
        await axios.post(`/messenger/conversations/${c.id}/read`, { message_id: messages.value.at(-1)?.id });
    } finally { loading.value = false; }
    scrollDown();
}
function closeModal() {
    showSearch.value = false; showGroup.value = false;
    query.value = ''; people.value = []; groupName.value = ''; selected.value = [];
}
async function start(user: User) {
    const { data } = await axios.post('/messenger/conversations/private', { user_id: user.id });
    let c = conversations.value.find(x => x.id === data.data.id);
    if (!c) { c = data.data as Conversation; conversations.value.unshift(c); }
    closeModal();
    await open(c);
}
async function send() {
    if (!active.value || busy.value || (!draft.value.trim() && !attachment.value)) return;
    busy.value = true;
    const form = new FormData();
    form.append('message', draft.value.trim());
    if (reply.value) form.append('reply_to_id', String(reply.value.id));
    if (attachment.value) form.append('attachment', attachment.value);
    try {
        const { data } = await axios.post(`/messenger/conversations/${active.value.id}/messages`, form);
        messages.value.push(data.data);
        active.value.last_message = data.data;
        draft.value = ''; attachment.value = null; reply.value = null;
        if (fileInput.value) fileInput.value.value = '';
        scrollDown(true);
    } finally { busy.value = false; }
}
async function edit(m: Message) {
    const value = window.prompt('Edit message', m.message ?? '');
    if (value?.trim()) {
        const { data } = await axios.put(`/messenger/messages/${m.id}`, { message: value });
        Object.assign(m, data.data);
    }
}
async function remove(m: Message) {
    if (window.confirm('Delete this message?')) {
        await axios.delete(`/messenger/messages/${m.id}`);
        m.deleted_at = new Date().toISOString();
        m.message = null;
    }
}
async function react(m: Message, emoji: string) {
    const { data } = await axios.post(`/messenger/messages/${m.id}/reactions`, { emoji });
    Object.assign(m, data.data);
}
const toggle = (user: User) => {
    selected.value = selected.value.some(x => x.id === user.id) ? selected.value.filter(x => x.id !== user.id) : [...selected.value, user];
};
async function createGroup() {
    if (!groupName.value.trim() || !selected.value.length) return;
    const { data } = await axios.post('/messenger/groups', { name: groupName.value, members: selected.value.map(x => x.id) });
    conversations.value.unshift(data.data);
    const created = data.data as Conversation;
    closeModal();
    await open(created);
}
function onEnter(e: KeyboardEvent) {
    if (e.isComposing) return;   // don't send while typing with an IME (e.g. Khmer, Chinese)
    e.preventDefault();
    send();
}
const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && (showSearch.value || showGroup.value)) closeModal(); };

onMounted(() => {
    window.addEventListener('keydown', onKey);
    conversations.value.forEach(subscribe);
    // on wide screens open the first chat; on phones start at the list
    if (conversations.value[0] && window.matchMedia('(min-width: 768px)').matches) open(conversations.value[0]);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    window.clearTimeout(searchTimer);
    window.clearTimeout(notificationTimer);
    listeningIds.forEach(id => echo().leave('conversation.' + id));
});
</script>

<template>
    <div class="bg-stone-200/70 md:p-4 lg:p-6">
        <button v-if="notification" @click="open(notification.conversation); notification = null"
            class="fixed right-4 top-4 z-50 max-w-sm rounded-2xl bg-stone-900 px-4 py-3 text-left text-sm text-white shadow-xl ring-1 ring-white/20">
            <b class="block">New message from {{ notification.message.sender.name }}</b>
            <span class="mt-0.5 block truncate text-stone-300">{{ notification.message.message || (notification.message.type === 'image' ? 'Sent a photo' : 'Sent a file') }}</span>
        </button>
        <main
            class="mx-auto flex h-[100dvh] w-full max-w-[1400px] overflow-hidden bg-white md:h-[calc(100dvh-2rem)] md:rounded-3xl md:shadow-xl md:ring-1 md:ring-stone-900/5 lg:h-[calc(100dvh-3rem)]">

            <!-- Conversation list -->
            <aside :class="active ? 'hidden md:flex' : 'flex'"
                class="w-full shrink-0 flex-col border-r border-stone-200 bg-stone-50 md:w-80 lg:w-96">
                <div class="px-4 pb-3 pt-[max(1rem,env(safe-area-inset-top))] sm:px-5">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <span class="grid h-9 w-9 place-items-center rounded-xl bg-teal-700 text-lg font-black text-white">P</span>
                            <h1 class="text-xl font-bold tracking-tight text-stone-900">Pulse</h1>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button @click="showGroup = true" title="New group" aria-label="New group"
                                class="grid h-10 w-10 place-items-center rounded-xl text-stone-600 hover:bg-stone-200/70 focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="9" cy="8" r="3.2" /><path d="M3 19c.6-3 3-4.6 6-4.6s5.4 1.6 6 4.6M17 5.5a3 3 0 0 1 0 5.6M18.5 14.6c1.6.6 2.5 2 2.8 4" /></svg>
                            </button>
                            <button @click="showSearch = true"
                                class="h-10 rounded-xl bg-teal-700 px-4 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">
                                New chat
                            </button>
                        </div>
                    </div>
                    <input v-model="filter" type="search" placeholder="Search conversations"
                        class="mt-4 w-full rounded-xl border border-stone-200 bg-white px-4 py-2.5 text-sm outline-none placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20">
                </div>

                <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
                    <button v-for="c in filtered" :key="c.id" @click="open(c)"
                        :class="active?.id === c.id ? 'bg-teal-50 ring-1 ring-teal-200' : 'hover:bg-stone-100'"
                        class="flex w-full items-center gap-3 rounded-2xl p-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-teal-600">
                        <div class="relative shrink-0">
                            <div :class="tone(c.name)" class="grid h-12 w-12 place-items-center overflow-hidden rounded-full text-sm font-bold">
                                <img v-if="c.avatar_url" :src="c.avatar_url" :alt="c.name" class="h-full w-full object-cover">
                                <span v-else>{{ initials(c.name) }}</span>
                            </div>
                            <span v-if="peer(c)?.is_online" class="absolute bottom-0 right-0 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-500" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-stone-900">{{ c.name }}</span>
                                <span class="shrink-0 text-[11px] text-stone-400">{{ time(c.last_message?.created_at) }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center justify-between gap-2">
                                <p :class="c.unread_count ? 'font-medium text-stone-700' : 'text-stone-500'" class="truncate text-[13px]">{{ preview(c) }}</p>
                                <span v-if="c.unread_count" class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-teal-700 px-1.5 text-[11px] font-bold text-white">{{ c.unread_count }}</span>
                            </div>
                        </div>
                    </button>
                    <p v-if="!conversations.length" class="px-6 py-12 text-center text-sm text-stone-500">No conversations yet. Choose <b>New chat</b> to message someone.</p>
                    <p v-else-if="!filtered.length" class="px-6 py-12 text-center text-sm text-stone-500">No conversation matches “{{ filter }}”.</p>
                </div>

                <a href="/profile" class="border-t border-stone-200 px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4 text-sm font-medium text-stone-600 hover:text-teal-800">Profile settings</a>
            </aside>

            <!-- Conversation -->
            <section :class="active ? 'flex' : 'hidden md:flex'" class="min-w-0 flex-1 flex-col bg-white">
                <template v-if="active">
                    <header class="flex items-center gap-3 border-b border-stone-200 px-3 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] sm:px-5">
                        <button @click="active = null" aria-label="Back to conversations"
                            class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-stone-600 hover:bg-stone-100 md:hidden">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>
                        </button>
                        <div :class="tone(active.name)" class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full text-sm font-bold">
                            <img v-if="active.avatar_url" :src="active.avatar_url" :alt="active.name" class="h-full w-full object-cover">
                            <span v-else>{{ initials(active.name) }}</span>
                        </div>
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold text-stone-900">{{ active.name }}</h2>
                            <p :class="isOnline ? 'text-emerald-600' : 'text-stone-500'" class="text-xs">{{ status }}</p>
                        </div>
                    </header>

                    <div ref="chat" class="flex-1 overflow-y-auto overscroll-contain bg-stone-50 px-3 py-4 sm:px-6">
                        <p v-if="loading" class="mt-16 text-center text-sm text-stone-400">Loading messages…</p>
                        <p v-else-if="!messages.length" class="mt-16 text-center text-sm text-stone-500">No messages yet. Say hello.</p>

                        <template v-for="{ m, newDay, first, mine } in rows" :key="m.id">
                            <div v-if="newDay" class="my-4 flex justify-center">
                                <span class="rounded-full bg-stone-200/70 px-3 py-1 text-[11px] font-medium text-stone-600">{{ dayLabel(m.created_at) }}</span>
                            </div>

                            <div :class="[mine ? 'items-end' : 'items-start', first ? 'mt-3' : 'mt-0.5']" class="group flex flex-col">
                                <span v-if="!mine && active.type === 'group' && first" class="mb-1 ml-1 text-xs font-medium text-stone-500">{{ m.sender.name }}</span>

                                <div :class="mine ? 'flex-row-reverse' : ''" class="flex max-w-[88%] items-center gap-1 sm:max-w-[75%] lg:max-w-[65%]">
                                    <div :class="[
                                        mine ? 'rounded-2xl rounded-br-md bg-teal-700 text-white' : 'rounded-2xl rounded-bl-md bg-white text-stone-800 shadow-sm ring-1 ring-stone-200',
                                        m.deleted_at ? 'opacity-70' : '']"
                                        class="min-w-0 px-3.5 py-2 text-sm leading-relaxed">
                                        <p v-if="m.reply_to" class="mb-1.5 line-clamp-2 border-l-2 border-current/40 pl-2 text-xs opacity-75">
                                            <b>{{ m.reply_to.sender }}</b>: {{ m.reply_to.message }}
                                        </p>
                                        <p v-if="m.deleted_at" class="italic">This message was deleted</p>
                                        <img v-else-if="m.type === 'image'" :src="m.file_url" :alt="m.file_name || 'Image'" loading="lazy" class="max-h-72 w-full rounded-lg object-cover">
                                        <a v-else-if="m.type === 'file'" :href="m.file_url" target="_blank" rel="noopener"
                                            class="flex items-center gap-2 break-all underline decoration-current/40 underline-offset-2">
                                            <span aria-hidden="true">📄</span>
                                            <span>{{ m.file_name }}<small v-if="m.file_size" class="ml-1 opacity-70">{{ fileSize(m.file_size) }}</small></span>
                                        </a>
                                        <p v-else class="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ m.message }}</p>
                                        <p class="mt-0.5 text-right text-[10px] opacity-70">{{ time(m.created_at) }}<span v-if="m.edited_at"> · edited</span><span v-if="mine && readStatus(m)"> · {{ readStatus(m) }}</span></p>
                                    </div>

                                    <!-- actions: always reachable on touch, on hover for desktop -->
                                    <div v-if="!m.deleted_at"
                                        class="flex shrink-0 items-center gap-0.5 rounded-full bg-white/90 px-1 text-xs text-stone-500 opacity-60 ring-1 ring-stone-200 transition-opacity focus-within:opacity-100 md:opacity-0 md:group-hover:opacity-100">
                                        <button v-for="emoji in ['👍', '❤️', '😂']" :key="emoji" @click="react(m, emoji)" :aria-label="'React ' + emoji" class="h-7 w-7 rounded-full hover:bg-stone-100">{{ emoji }}</button>
                                        <button @click="reply = m; composer?.focus()" class="h-7 rounded-full px-2 hover:bg-stone-100">Reply</button>
                                        <template v-if="mine && m.type === 'text'">
                                            <button @click="edit(m)" class="h-7 rounded-full px-2 hover:bg-stone-100">Edit</button>
                                        </template>
                                        <button v-if="mine" @click="remove(m)" class="h-7 rounded-full px-2 text-rose-500 hover:bg-rose-50">Delete</button>
                                    </div>
                                </div>

                                <div v-if="m.reactions.length" :class="mine ? 'mr-1' : 'ml-1'" class="-mt-1 flex flex-wrap gap-1">
                                    <button v-for="r in grouped(m)" :key="r.emoji" @click="react(m, r.emoji)"
                                        :class="r.mine ? 'border-teal-300 bg-teal-50' : 'border-stone-200 bg-white'"
                                        class="rounded-full border px-1.5 py-0.5 text-xs">{{ r.emoji }} {{ r.count }}</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <footer class="border-t border-stone-200 bg-white px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 sm:px-5">
                        <div v-if="reply" class="mb-2 flex items-center justify-between gap-3 rounded-xl bg-teal-50 px-3 py-2 text-xs text-teal-900">
                            <span class="min-w-0 truncate">Replying to <b>{{ reply.sender.name }}</b>: {{ reply.message }}</span>
                            <button @click="reply = null" aria-label="Cancel reply" class="shrink-0 text-base leading-none">×</button>
                        </div>
                        <div v-if="attachment" class="mb-2 flex items-center justify-between gap-3 rounded-xl bg-stone-100 px-3 py-2 text-xs text-stone-600">
                            <span class="min-w-0 truncate">📎 {{ attachment.name }} · {{ fileSize(attachment.size) }}</span>
                            <button @click="attachment = null; fileInput && (fileInput.value = '')" aria-label="Remove attachment" class="shrink-0 text-base leading-none">×</button>
                        </div>
                        <div class="flex items-end gap-2">
                            <input ref="fileInput" type="file" class="hidden" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                                @change="attachment = ($event.target as HTMLInputElement).files?.[0] || null">
                            <button @click="fileInput?.click()" title="Attach file" aria-label="Attach file"
                                class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-stone-500 hover:bg-stone-100">
                                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5l-8 8a5 5 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L9.5 17a1.7 1.7 0 0 1-2.4-2.4L14 7.7" /></svg>
                            </button>
                            <textarea ref="composer" v-model="draft" @keydown.enter.exact="onEnter" placeholder="Write a message" rows="1"
                                class="max-h-32 min-h-11 flex-1 resize-none rounded-2xl bg-stone-100 px-4 py-2.5 text-base outline-none placeholder:text-stone-400 focus:ring-2 focus:ring-teal-600/30 sm:text-sm" />
                            <button @click="send" :disabled="busy || (!draft.trim() && !attachment)"
                                class="h-11 shrink-0 rounded-xl bg-teal-700 px-4 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-40">
                                {{ busy ? 'Sending' : 'Send' }}
                            </button>
                        </div>
                    </footer>
                </template>

                <div v-else class="m-auto max-w-xs px-6 text-center">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-teal-50 text-2xl text-teal-700">✦</div>
                    <h2 class="mt-4 text-lg font-semibold text-stone-900">Select a conversation</h2>
                    <p class="mt-1.5 text-sm text-stone-500">Pick a chat from the list or start a new one.</p>
                </div>
            </section>
        </main>

        <!-- New chat / new group: bottom sheet on phones, dialog on larger screens -->
        <div v-if="showSearch || showGroup" @click.self="closeModal"
            class="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/40 sm:items-center sm:p-4" role="dialog" aria-modal="true">
            <div class="flex max-h-[90dvh] w-full flex-col rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-xl sm:max-w-md sm:rounded-3xl sm:pb-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold text-stone-900">{{ showGroup ? 'Create group' : 'New chat' }}</h2>
                    <button @click="closeModal" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full text-xl text-stone-500 hover:bg-stone-100">×</button>
                </div>

                <input v-if="showGroup" v-model="groupName" placeholder="Group name" class="mb-3 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-base outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm">
                <input v-model="query" :autofocus="!showGroup" placeholder="Search by name, username, or email" class="mb-3 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-base outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 sm:text-sm">

                <div v-if="showGroup && selected.length" class="mb-3 flex flex-wrap gap-1.5">
                    <button v-for="u in selected" :key="u.id" @click="toggle(u)" class="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-medium text-teal-800">{{ u.name }} ×</button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button v-for="u in people" :key="u.id" @click="showGroup ? toggle(u) : start(u)"
                        class="flex w-full items-center gap-3 rounded-xl p-2.5 text-left hover:bg-stone-50">
                        <span :class="tone(u.name)" class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full text-xs font-bold">
                            <img v-if="u.avatar_url" :src="u.avatar_url" :alt="u.name" class="h-full w-full object-cover">
                            <template v-else>{{ initials(u.name) }}</template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <b class="block truncate text-sm text-stone-900">{{ u.name }}</b>
                            <small class="block truncate text-stone-500">@{{ u.username }} · {{ u.email }}</small>
                        </span>
                        <span v-if="showGroup && selected.some(x => x.id === u.id)" class="text-teal-700">✓</span>
                    </button>
                    <p v-if="query && !people.length" class="py-8 text-center text-sm text-stone-500">No users found.</p>
                    <p v-else-if="!query" class="py-8 text-center text-sm text-stone-400">Type a name, username, or email to find people.</p>
                </div>

                <button v-if="showGroup" @click="createGroup" :disabled="!groupName.trim() || !selected.length"
                    class="mt-3 w-full rounded-xl bg-teal-700 py-3 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-40">
                    Create group{{ selected.length ? ` (${selected.length})` : '' }}
                </button>
            </div>
        </div>
    </div>
</template>
