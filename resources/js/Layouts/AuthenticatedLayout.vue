<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch, computed } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { echo } from '@laravel/echo-vue';

const user = computed(() => usePage().props.auth.user as any);
const initialOf = (name?: string) => (name?.trim()?.[0] ?? '?').toUpperCase();

const showingNavigationDropdown = ref(false);
const query = ref('');
const results = ref<any>({ users: [], posts: [] });
const searching = ref(false);
const searchOpen = ref(false);
const mobileSearchOpen = ref(false);
const unreadNotifications = ref(0);
const recentNotifications = ref<any[]>([]);
const notificationsOpen = ref(false);

const searchWrap = ref<HTMLElement | null>(null);
const mobileSearchInput = ref<HTMLInputElement | null>(null);

let timer: ReturnType<typeof setTimeout> | null = null;
let poll: ReturnType<typeof setInterval> | null = null;
let controller: AbortController | null = null;
let removeNavigateListener: (() => void) | undefined;
let notificationChannel: any = null;

const navItems = [
    { name: 'feed', label: 'Feed', icon: ['m3 11 9-7 9 7v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z', 'M9 21v-6h6v6'] },
    { name: 'dashboard', label: 'Messenger', icon: ['M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.2-.6L4 20l1.2-4A8 8 0 1 1 20 11.5Z', 'M8 11h.01M12 11h.01M16 11h.01'] },
    { name: 'saved', label: 'Saved', icon: ['M6 3h12v18l-6-4-6 4z'] },
    { name: 'friends', label: 'Friends', icon: ['M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2', 'M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z', 'M21 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'] },
];
const isActive = (name: string) => route().current(name);
const notificationsActive = computed(() => route().current('notifications'));

// The mobile bottom bar is hidden on the full-height messenger screen so it never covers the chat input.
const showBottomBar = computed(() => !isActive('dashboard'));

const hasResults = computed(() => results.value.users?.length || results.value.posts?.length);

watch(query, (value) => {
    if (timer) clearTimeout(timer);
    if (value.trim().length < 2) {
        controller?.abort();
        searching.value = false;
        results.value = { users: [], posts: [] };
        return;
    }
    searchOpen.value = true;
    searching.value = true;
    timer = setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        try {
            results.value = (await axios.get('/social/search', { params: { q: value }, signal: controller.signal })).data;
            searching.value = false;
        } catch (e: any) {
            if (e.code !== 'ERR_CANCELED') {
                results.value = { users: [], posts: [] };
                searching.value = false;
            }
        }
    }, 350);
});

const submitSearch = () => {
    const value = query.value.trim();
    if (value.length < 2) return;
    searchOpen.value = false;
    mobileSearchOpen.value = false;
    router.visit(`/search?q=${encodeURIComponent(value)}`);
};

function openMobileSearch() {
    mobileSearchOpen.value = true;
    showingNavigationDropdown.value = false;
    nextTick(() => mobileSearchInput.value?.focus());
}
function toggleMobileMenu() {
    showingNavigationDropdown.value = !showingNavigationDropdown.value;
    if (showingNavigationDropdown.value) mobileSearchOpen.value = false;
}

async function loadNotifications() {
    try {
        const { data } = await axios.get(route('notifications.data'));
        unreadNotifications.value = data.unread_count;
        recentNotifications.value = data.data;
    } catch {
        /* keep previous state */
    }
}
function onDocumentMouseDown(e: MouseEvent) {
    const target = e.target as Node;
    if (searchWrap.value && !searchWrap.value.contains(target)) searchOpen.value = false;
}
function onKeydown(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    searchOpen.value = false;
    mobileSearchOpen.value = false;
    showingNavigationDropdown.value = false;
}
function onVisibility() {
    if (document.visibilityState === 'visible') loadNotifications();
}
async function openNotification(item: any) {
    if (!item.read_at) {
        await axios.post(`/notifications/${item.id}/read`);
        item.read_at = new Date().toISOString();
        unreadNotifications.value = Math.max(0, unreadNotifications.value - 1);
    }
    notificationsOpen.value = false;
    if (item.type === 'friend_request') return router.visit(route('friends'));
    const target = item.target || {};
    if (target.post_id) return router.visit(`${route('feed')}?post=${target.post_id}${target.comment_id ? `&comment=${target.comment_id}` : ''}${target.reply_id ? `&reply=${target.reply_id}` : ''}`);
    if (item.person?.id) router.visit(route('social.profile', item.person.id));
}
async function markAllNotificationsRead() {
    await axios.post('/notifications/read-all');
    recentNotifications.value.forEach((item) => { item.read_at = item.read_at || new Date().toISOString(); });
    unreadNotifications.value = 0;
}

onMounted(() => {
    loadNotifications();
    notificationChannel = echo().private(`App.Models.User.${user.value.id}`).notification(() => loadNotifications());
    poll = setInterval(loadNotifications, 60000);
    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('mousedown', onDocumentMouseDown);
    window.addEventListener('keydown', onKeydown);
    removeNavigateListener = router.on('navigate', () => {
        showingNavigationDropdown.value = false;
        searchOpen.value = false;
        mobileSearchOpen.value = false;
        notificationsOpen.value = false;
        loadNotifications();
    });
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
    if (poll) clearInterval(poll);
    controller?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
    document.removeEventListener('mousedown', onDocumentMouseDown);
    window.removeEventListener('keydown', onKeydown);
    removeNavigateListener?.();
    if (notificationChannel) echo().leave(`private-App.Models.User.${user.value.id}`);
});
</script>

<template>
    <div class="min-h-screen bg-[#f0f2f5] text-slate-900">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-white/80">
            <nav class="grid h-14 grid-cols-[1fr_auto_1fr] items-center gap-3 px-3 sm:px-4" aria-label="Primary navigation">
                <!-- Left: logo, search, mobile buttons -->
                <div class="flex min-w-0 items-center gap-2">
                    <Link
                        :href="route('feed')"
                        class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xl font-black text-white shadow-sm transition hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2"
                        aria-label="PCHAT Feed"
                    >P</Link>

                    <!-- Desktop search -->
                    <form ref="searchWrap" class="relative hidden w-[300px] max-w-[28vw] md:block" role="search" @submit.prevent="submitSearch">
                        <label class="flex h-10 items-center gap-2 rounded-full border border-transparent bg-slate-100 px-3 text-sm text-slate-500 transition focus-within:border-blue-200 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-100">
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                            <input
                                v-model="query"
                                @focus="searchOpen = true"
                                type="search"
                                autocomplete="off"
                                class="h-full min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-sm text-slate-800 shadow-none outline-none ring-0 placeholder:text-slate-500 focus:border-0 focus:outline-none focus:ring-0 [-webkit-appearance:none]"
                                placeholder="Search PCHAT"
                                aria-label="Search PCHAT"
                            />
                        </label>

                        <Transition
                            enter-active-class="transition duration-150 ease-out"
                            enter-from-class="-translate-y-1 opacity-0"
                            enter-to-class="translate-y-0 opacity-100"
                            leave-active-class="transition duration-100 ease-in"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <div v-if="searchOpen && (query.trim().length >= 2 || searching)" class="absolute left-0 top-12 z-50 max-h-[70vh] w-96 overflow-y-auto rounded-2xl bg-white py-2 shadow-xl ring-1 ring-slate-200">
                                <p v-if="searching" class="px-4 py-4 text-sm text-slate-500">Searching…</p>
                                <template v-else>
                                    <template v-if="results.users?.length">
                                        <p class="px-4 pb-1 pt-2 text-xs font-bold text-slate-500">People</p>
                                        <Link v-for="person in results.users" :key="person.id" :href="route('social.profile', person.id)" class="flex items-center gap-3 px-4 py-2 transition hover:bg-slate-50">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white">
                                                <img v-if="person.avatar_url" :src="person.avatar_url" :alt="person.name" class="h-full w-full object-cover" />
                                                <template v-else>{{ initialOf(person.name) }}</template>
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-semibold text-slate-900">{{ person.name }}</span>
                                                <span class="block truncate text-xs text-slate-500">@{{ person.username }}</span>
                                            </span>
                                        </Link>
                                    </template>

                                    <template v-if="results.posts?.length">
                                        <p class="px-4 pb-1 pt-3 text-xs font-bold text-slate-500">Posts</p>
                                        <Link v-for="post in results.posts" :key="post.id" :href="route('social.profile', post.author.id)" class="block px-4 py-2 transition hover:bg-slate-50">
                                            <span class="block truncate text-sm text-slate-800">{{ post.content || 'Media post' }}</span>
                                            <span class="block truncate text-xs text-slate-500">by {{ post.author.name }}</span>
                                        </Link>
                                    </template>

                                    <p v-if="!hasResults" class="px-4 py-6 text-center text-sm text-slate-500">No results for “{{ query }}”. Try a different name or word.</p>

                                    <button type="submit" class="mt-2 flex w-full items-center gap-2 border-t border-slate-100 px-4 py-3 text-left text-sm font-semibold text-blue-600 transition hover:bg-slate-50">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                                        See all results for “{{ query }}”
                                    </button>
                                </template>
                            </div>
                        </Transition>
                    </form>

                    <button type="button" class="grid h-10 w-10 place-items-center rounded-full text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 md:hidden" aria-label="Search PCHAT" @click="openMobileSearch">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                    </button>
                </div>

                <!-- Center: tabs (tablet and desktop) -->
                <div class="hidden h-full items-stretch gap-1 sm:flex">
                    <Link
                        v-for="item in navItems"
                        :key="item.name"
                        :href="route(item.name)"
                        :title="item.label"
                        :aria-label="item.label"
                        :aria-current="isActive(item.name) ? 'page' : undefined"
                        class="group relative grid w-16 place-items-center transition focus-visible:outline-none md:w-20"
                        :class="isActive(item.name) ? 'text-blue-600' : 'text-slate-500 hover:text-blue-600'"
                    >
                        <span class="grid h-10 w-12 place-items-center rounded-lg transition group-focus-visible:ring-2 group-focus-visible:ring-blue-400 md:w-14" :class="isActive(item.name) ? '' : 'group-hover:bg-slate-100'">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="isActive(item.name) ? 2.4 : 2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path v-for="(d, i) in item.icon" :key="i" :d="d" />
                            </svg>
                        </span>
                        <span class="absolute inset-x-2 bottom-0 h-[3px] rounded-t-full bg-blue-600 transition" :class="isActive(item.name) ? 'opacity-100' : 'opacity-0'"></span>
                    </Link>
                </div>

                <!-- Right: notifications, user, mobile menu -->
                <div class="flex items-center justify-end gap-2">
                    <button
                        type="button"
                        @click="notificationsOpen = !notificationsOpen"
                        class="relative grid h-10 w-10 place-items-center rounded-full transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400"
                        :class="notificationsActive ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                        :aria-label="unreadNotifications ? `Notifications, ${unreadNotifications} unread` : 'Notifications'"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
                        <span v-if="unreadNotifications" class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ unreadNotifications > 9 ? '9+' : unreadNotifications }}</span>
                    </button>
                    <div v-if="notificationsOpen" class="absolute right-3 top-14 z-50 w-[min(24rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between px-4 py-3"><strong>Notifications</strong><button class="text-xs font-semibold text-blue-600" @click="markAllNotificationsRead">Mark all as read</button></div>
                        <div v-if="recentNotifications.length" class="max-h-[70vh] overflow-y-auto divide-y divide-slate-100"><button v-for="item in recentNotifications" :key="item.id" @click="openNotification(item)" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50" :class="!item.read_at ? 'bg-blue-50/70' : ''"><span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full bg-blue-600 text-sm font-bold text-white"><img v-if="item.person?.avatar_url" :src="item.person.avatar_url" class="h-full w-full object-cover"><template v-else>{{ initialOf(item.person?.name) }}</template></span><span class="min-w-0 flex-1 text-sm text-slate-800"><b>{{ item.message }}</b><small v-if="item.target?.preview" class="mt-0.5 block truncate text-slate-500">{{ item.target.preview }}</small></span><i v-if="!item.read_at" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-blue-600"></i></button></div>
                        <p v-else class="px-4 py-10 text-center text-sm text-slate-500">No notifications yet</p>
                        <Link :href="route('notifications')" class="block border-t px-4 py-3 text-center text-sm font-semibold text-blue-600">See all notifications</Link>
                    </div>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button type="button" class="grid h-10 w-10 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white ring-2 ring-transparent transition hover:ring-blue-200 focus-visible:outline-none focus-visible:ring-blue-300" :aria-label="`Account menu for ${user.name}`">
                                <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" class="h-full w-full object-cover" />
                                <span v-else>{{ initialOf(user.name) }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b border-slate-100 px-4 py-3">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ user.name }}</p>
                                <p v-if="user.email" class="truncate text-xs text-slate-500">{{ user.email }}</p>
                            </div>
                            <DropdownLink :href="route('social.profile', user.id)">View profile</DropdownLink>
                            <DropdownLink :href="route('profile.edit')">Profile settings</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </nav>

            <!-- Mobile search panel -->
            <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="mobileSearchOpen" class="border-t border-slate-100 bg-white p-3 shadow-lg md:hidden">
                    <form role="search" @submit.prevent="submitSearch">
                        <label class="flex items-center gap-2 rounded-full bg-slate-100 px-3 py-2 focus-within:bg-white focus-within:ring-2 focus-within:ring-blue-200">
                            <svg class="h-5 w-5 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path stroke-linecap="round" d="m16 16 4 4" /></svg>
                            <input ref="mobileSearchInput" v-model="query" type="search" autocomplete="off" class="min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-sm outline-none ring-0 focus:border-0 focus:outline-none focus:ring-0" placeholder="Search people and posts…" aria-label="Search PCHAT" />
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-full text-slate-500 hover:bg-slate-200" aria-label="Close search" @click="mobileSearchOpen = false">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
                            </button>
                        </label>
                    </form>

                    <div v-if="query.trim().length >= 2 || searching" class="mt-2 max-h-[60vh] overflow-y-auto rounded-xl border border-slate-200">
                        <p v-if="searching" class="p-4 text-sm text-slate-500">Searching…</p>
                        <template v-else>
                            <template v-if="results.users?.length">
                                <p class="px-3 pt-3 text-xs font-bold text-slate-500">People</p>
                                <Link v-for="person in results.users" :key="person.id" :href="route('social.profile', person.id)" class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-bold text-white">
                                        <img v-if="person.avatar_url" :src="person.avatar_url" :alt="person.name" class="h-full w-full object-cover" />
                                        <template v-else>{{ initialOf(person.name) }}</template>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold">{{ person.name }}</span>
                                        <span class="block truncate text-xs text-slate-500">@{{ person.username }}</span>
                                    </span>
                                </Link>
                            </template>
                            <template v-if="results.posts?.length">
                                <p class="px-3 pt-3 text-xs font-bold text-slate-500">Posts</p>
                                <Link v-for="post in results.posts" :key="post.id" :href="route('social.profile', post.author.id)" class="block px-3 py-2 hover:bg-slate-50">
                                    <span class="block truncate text-sm">{{ post.content || 'Media post' }}</span>
                                    <span class="block truncate text-xs text-slate-500">by {{ post.author.name }}</span>
                                </Link>
                            </template>
                            <p v-if="!hasResults" class="p-6 text-center text-sm text-slate-500">No results for “{{ query }}”. Try a different name or word.</p>
                            <button type="button" @click="submitSearch" class="w-full border-t border-slate-100 px-3 py-3 text-left text-sm font-semibold text-blue-600 hover:bg-slate-50">See all results for “{{ query }}”</button>
                        </template>
                    </div>
                </div>
            </Transition>
        </header>

        <header v-if="$slots.header" class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-6xl px-5 py-6"><slot name="header" /></div>
        </header>

        <main :class="showBottomBar ? 'pb-16 sm:pb-0' : ''"><slot /></main>

        <!-- Mobile bottom tab bar: replaces the old hamburger list so main pages are one tap away -->
        <nav v-if="showBottomBar" class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden" aria-label="Main tabs">
            <div class="grid grid-cols-4">
                <Link
                    v-for="item in navItems"
                    :key="item.name"
                    :href="route(item.name)"
                    :aria-current="isActive(item.name) ? 'page' : undefined"
                    class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-400"
                    :class="isActive(item.name) ? 'text-blue-600' : 'text-slate-500'"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="isActive(item.name) ? 2.4 : 2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path v-for="(d, i) in item.icon" :key="i" :d="d" />
                    </svg>
                    {{ item.label }}
                </Link>
            </div>
        </nav>
    </div>
</template>
