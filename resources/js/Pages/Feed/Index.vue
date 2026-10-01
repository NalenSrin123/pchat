<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ posts: any; reactionTypes: string[]; saved?: boolean }>();
const posts = ref<any[]>(props.posts.data);
const next = ref(props.posts.next_page_url);
const composer = ref(false), content = ref(''), privacy = ref('friends'), files = ref<File[]>([]), busy = ref(false), error = ref('');
const draft = ref<Record<number, string>>({}), lightboxPost = ref<any | null>(null), lightboxIndex = ref(0), loadingMore = ref(false);
const sharePost = ref<any | null>(null), shareCaption = ref(''), sharePrivacy = ref('friends'), shareBusy = ref(false), shareError = ref('');

/* ---------- Reactions ---------- */
const defaultReactions = ['👍', '❤️', '😂', '😮', '😢', '😡'];
const reactions = computed(() => (props.reactionTypes?.length ? props.reactionTypes : defaultReactions));
const labels: Record<string, string> = { '👍': 'Like', '❤️': 'Love', '😂': 'Haha', '😮': 'Wow', '😢': 'Sad', '😡': 'Angry', '🔥': 'Fire', '🎉': 'Celebrate', '🙏': 'Thanks' };
const labelOf = (r: string) => labels[r] || 'Reacted';
const colorOf = (r: string) => ({ '👍': 'text-indigo-600', '❤️': 'text-rose-600', '😡': 'text-orange-600' } as Record<string, string>)[r] || 'text-amber-600';

const pickerFor = ref<number | null>(null);
let showTimer: any, hideTimer: any, pressTimer: any, longPressed = false;
const openPicker = (id: number) => { clearTimeout(hideTimer); showTimer = setTimeout(() => (pickerFor.value = id), 250); };
const closePicker = () => { clearTimeout(showTimer); hideTimer = setTimeout(() => (pickerFor.value = null), 300); };
const keepPicker = () => clearTimeout(hideTimer);
const pressStart = (id: number) => { longPressed = false; pressTimer = setTimeout(() => { longPressed = true; pickerFor.value = id; }, 400); };
const pressEnd = () => clearTimeout(pressTimer);
function quickReact(post: any) {
    if (longPressed) { longPressed = false; return; }
    pickerFor.value = null;
    react(post, post.my_reaction || '👍');
}
function pick(post: any, r: string) { pickerFor.value = null; react(post, r); }
function summary(post: any) {
    const list = Object.entries(post.reactions || {}).map(([r, n]) => [r, Number(n)] as [string, number]).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]);
    return { top: list.slice(0, 3).map(([r]) => r), total: list.reduce((s, [, n]) => s + n, 0), all: list };
}

/* ---------- Composer ---------- */
const select = (e: Event) => { files.value = [...files.value, ...Array.from((e.target as HTMLInputElement).files || [])].slice(0, 10); (e.target as HTMLInputElement).value = ''; };
const previews = ref<{ name: string; url: string; video: boolean }[]>([]);
watch(files, (list) => {
    previews.value.forEach(p => URL.revokeObjectURL(p.url));
    previews.value = list.map(f => ({ name: f.name, url: URL.createObjectURL(f), video: f.type.startsWith('video/') }));
}, { deep: true });
onBeforeUnmount(() => previews.value.forEach(p => URL.revokeObjectURL(p.url)));

const ago = (v: string) => {
    const s = Math.floor((Date.now() - new Date(v).getTime()) / 1000);
    if (s < 60) return 'Just now';
    if (s < 3600) return `${Math.floor(s / 60)}m`;
    if (s < 86400) return `${Math.floor(s / 3600)}h`;
    if (s < 604800) return `${Math.floor(s / 86400)}d`;
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(v));
};
const privacyIcon = (p: string) => ({ public: '🌐', friends: '👥', only_me: '🔒' } as Record<string, string>)[p] || '👥';
const previewMedia = (post: any) => post.media?.slice(0, 4) || [];
const remainingMediaCount = (post: any) => Math.max(0, (post.media?.length || 0) - 4);
const mediaGridClass = (post: any) => {
    const count = Math.min(post.media?.length || 0, 4);
    return count === 1
        ? 'h-[clamp(260px,56vw,520px)] grid-cols-1'
        : 'h-[clamp(260px,44vw,440px)] grid-cols-2 grid-rows-2';
};
const mediaItemClass = (post: any, index: number) => post.media.length === 3 && index === 0 ? 'row-span-2' : '';
const touchStartX = ref<number | null>(null);
function openLightbox(post: any, index: number) {
    lightboxPost.value = post;
    lightboxIndex.value = index;
}
function closeLightbox() { lightboxPost.value = null; }
function moveLightbox(direction: number) {
    const count = lightboxPost.value?.media?.length || 0;
    if (count) lightboxIndex.value = (lightboxIndex.value + direction + count) % count;
}
function onGalleryKeydown(event: KeyboardEvent) {
    if (!lightboxPost.value) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowLeft') moveLightbox(-1);
    if (event.key === 'ArrowRight') moveLightbox(1);
}
function startGallerySwipe(event: TouchEvent) { touchStartX.value = event.changedTouches[0]?.clientX ?? null; }
function endGallerySwipe(event: TouchEvent) {
    const start = touchStartX.value;
    const end = event.changedTouches[0]?.clientX;
    touchStartX.value = null;
    if (start === null || end === undefined || Math.abs(start - end) < 50) return;
    moveLightbox(start > end ? 1 : -1);
}
onMounted(() => window.addEventListener('keydown', onGalleryKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onGalleryKeydown));

async function create() {
    if (busy.value) return;
    if (!content.value.trim() && !files.value.length) { error.value = 'Write something or add a photo or video.'; return; }
    busy.value = true; error.value = '';
    try {
        const fd = new FormData();
        fd.append('content', content.value); fd.append('privacy', privacy.value);
        files.value.forEach(f => fd.append('media[]', f));
        const { data } = await axios.post('/posts', fd);
        posts.value.unshift(data.data); composer.value = false; content.value = ''; files.value = [];
    } catch (e: any) { error.value = e.response?.data?.message || 'Could not publish your post.'; }
    finally { busy.value = false; }
}
async function react(post: any, reaction: string) {
    if (post.reacting) return;
    const previous = post.my_reaction;
    post.reacting = true;
    post.my_reaction = previous === reaction ? null : reaction;
    try { const { data } = await axios.post(`/posts/${post.id}/reactions`, { reaction }); Object.assign(post, data.data); }
    catch { post.my_reaction = previous; }
    finally { post.reacting = false; }
}
async function submitComment(post: any, parent?: number) {
    const value = draft.value[post.id]?.trim();
    if (!value) return;
    try {
        const { data } = await axios.post(`/posts/${post.id}/comments`, { content: value, parent_id: parent });
        post.comments.unshift(data.data); post.comments_count++; draft.value[post.id] = '';
    } catch {}
}
const focusComment = (post: any) => document.getElementById(`comment-${post.id}`)?.focus();
function openShare(post: any) { sharePost.value = post; shareCaption.value = ''; sharePrivacy.value = 'friends'; shareError.value = ''; }
async function share() {
    if (!sharePost.value || shareBusy.value) return;
    shareBusy.value = true; shareError.value = '';
    try { const { data } = await axios.post(`/posts/${sharePost.value.id}/share`, { content: shareCaption.value, privacy: sharePrivacy.value }); posts.value.unshift(data.data); sharePost.value = null; }
    catch (e: any) { shareError.value = e.response?.data?.message || 'Could not share this post.'; }
    finally { shareBusy.value = false; }
}
async function remove(post: any) {
    if (!confirm('Delete this post?')) return;
    await axios.delete(`/posts/${post.id}`);
    posts.value = posts.value.filter(x => x.id !== post.id);
}
async function more() {
    if (!next.value || loadingMore.value) return;
    loadingMore.value = true;
    try { const { data } = await axios.get(next.value); posts.value.push(...data.data); next.value = data.next_page_url; }
    finally { loadingMore.value = false; }
}
</script>

<template>
    <Head :title="saved ? 'Saved posts' : 'Feed'" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50" @click="pickerFor = null">
            <div class="mx-auto grid max-w-6xl gap-6 px-3 py-6 sm:px-4 lg:grid-cols-[200px_minmax(0,680px)_200px]">

                <!-- Left nav -->
                <aside class="hidden lg:block">
                    <nav class="sticky top-6 space-y-1">
                        <Link :href="route('feed')" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition" :class="!saved ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'">🏠 Home</Link>
                        <Link :href="route('saved')" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition" :class="saved ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'">🔖 Saved posts</Link>
                    </nav>
                </aside>

                <main class="min-w-0 space-y-4">
                    <!-- Composer trigger -->
                    <section v-if="!saved" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white">{{ $page.props.auth.user.name?.[0] }}</span>
                            <button @click="composer = true" class="flex-1 rounded-full bg-slate-100 px-5 py-3 text-left text-slate-500 transition hover:bg-slate-200/70">What's on your mind, {{ $page.props.auth.user.name?.split(' ')[0] }}?</button>
                        </div>
                        <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                            <button @click="composer = true" class="flex flex-1 items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">📷 Photo</button>
                            <button @click="composer = true" class="flex flex-1 items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">🎥 Video</button>
                        </div>
                    </section>
                    <h1 v-else class="text-2xl font-bold text-slate-900">Saved posts</h1>

                    <!-- Empty state -->
                    <div v-if="!posts.length" class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-slate-200/70">
                        <p class="text-4xl">{{ saved ? '🔖' : '✨' }}</p>
                        <p class="mt-3 font-semibold text-slate-800">{{ saved ? 'No saved posts yet' : 'Your feed is empty' }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ saved ? 'Posts you save will show up here.' : 'Share a post to get things started.' }}</p>
                    </div>

                    <!-- Posts -->
                    <article v-for="post in posts" :key="post.id" class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/70">
                        <header class="flex items-start gap-3 p-4 pb-3">
                            <Link :href="route('social.profile', post.author.id)" class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white">
                                <img v-if="post.author.avatar_url" :src="post.author.avatar_url" class="h-full w-full object-cover" alt="">
                                <template v-else>{{ post.author.name[0] }}</template>
                            </Link>
                            <div class="min-w-0 flex-1">
                                <Link :href="route('social.profile', post.author.id)" class="block truncate font-semibold text-slate-900 hover:underline">{{ post.author.name }}</Link>
                                <p class="text-xs text-slate-500">{{ ago(post.created_at) }} · <span :title="post.privacy">{{ privacyIcon(post.privacy) }}</span></p>
                            </div>
                            <details v-if="post.user_id === $page.props.auth.user.id" class="relative">
                                <summary class="grid h-8 w-8 cursor-pointer list-none place-items-center rounded-full text-slate-500 transition hover:bg-slate-100">•••</summary>
                                <div class="absolute right-0 z-10 mt-1 w-36 rounded-xl bg-white p-1 shadow-lg ring-1 ring-slate-200">
                                    <button @click="remove(post)" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">Delete post</button>
                                </div>
                            </details>
                        </header>

                        <div v-if="post.content" class="whitespace-pre-wrap break-words px-4 pb-3 leading-relaxed text-slate-800">{{ post.content }}</div>

                        <div v-if="post.media?.length" class="grid gap-0.5 overflow-hidden bg-slate-100" :class="mediaGridClass(post)">
                            <button v-for="(media, index) in previewMedia(post)" :key="media.id" type="button" @click="openLightbox(post, index)"
                                class="group relative min-w-0 overflow-hidden bg-slate-900 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500"
                                :class="mediaItemClass(post, index)">
                                <img v-if="media.type === 'image'" :src="media.url" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]" alt="Post image">
                                <video v-else :src="media.url" preload="metadata" class="h-full w-full object-cover" />
                                <span v-if="media.type === 'video'" class="absolute inset-0 grid place-items-center bg-black/20 text-3xl text-white drop-shadow">▶</span>
                                <span v-if="index === 3 && remainingMediaCount(post)" class="absolute inset-0 grid place-items-center bg-black/60 text-3xl font-semibold text-white">+{{ remainingMediaCount(post) }}</span>
                            </button>
                        </div>

                        <div v-if="post.shared_post" class="mx-4 mb-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50/50">
                            <p class="p-3 text-sm"><b class="text-slate-900">{{ post.shared_post.author.name }}</b><span class="mt-1 block text-slate-700">{{ post.shared_post.content }}</span></p>
                            <img v-if="post.shared_post.media?.[0]?.type === 'image'" :src="post.shared_post.media[0].url" class="max-h-80 w-full object-cover" alt="">
                        </div>

                        <!-- Counts -->
                        <div class="flex items-center justify-between px-4 py-3 text-sm text-slate-500">
                            <div v-if="summary(post).total" class="group relative flex items-center gap-1.5">
                                <span class="flex -space-x-1">
                                    <span v-for="r in summary(post).top" :key="r" class="grid h-6 w-6 place-items-center rounded-full bg-white text-sm ring-2 ring-white">{{ r }}</span>
                                </span>
                                <span>{{ summary(post).total }}</span>
                                <div class="pointer-events-none absolute bottom-full left-0 z-10 mb-1 hidden rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-lg group-hover:block">
                                    <p v-for="[r, n] in summary(post).all" :key="r" class="whitespace-nowrap">{{ r }} {{ n }}</p>
                                </div>
                            </div>
                            <span v-else>Be the first to react</span>
                            <span>{{ post.comments_count }} comments · {{ post.shares_count }} shares</span>
                        </div>

                        <!-- Actions -->
                        <div class="mx-4 grid grid-cols-3 gap-1 border-y border-slate-100 py-1">
                            <div class="relative" @mouseenter="openPicker(post.id)" @mouseleave="closePicker" @click.stop>
                                <!-- Picker -->
                                <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                                    <div v-if="pickerFor === post.id" @mouseenter="keepPicker" class="absolute bottom-full left-0 z-20 mb-2 flex gap-1 rounded-full bg-white px-2 py-1.5 shadow-xl ring-1 ring-slate-200">
                                        <button v-for="r in reactions" :key="r" type="button" @click="pick(post, r)" :title="labelOf(r)" :aria-label="labelOf(r)"
                                            class="grid h-10 w-10 place-items-center rounded-full text-2xl transition-transform duration-150 hover:-translate-y-1 hover:scale-125 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                            :class="post.my_reaction === r ? 'bg-indigo-50' : ''">{{ r }}</button>
                                    </div>
                                </Transition>
                                <button type="button" @click="quickReact(post)" @touchstart.passive="pressStart(post.id)" @touchend="pressEnd" @touchmove="pressEnd" @contextmenu.prevent
                                    class="flex w-full items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                    :class="post.my_reaction ? colorOf(post.my_reaction) : 'text-slate-600'">
                                    <span class="text-lg leading-none">{{ post.my_reaction || '👍' }}</span>{{ post.my_reaction ? labelOf(post.my_reaction) : 'Like' }}
                                </button>
                            </div>
                            <button type="button" @click="focusComment(post)" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">💬 Comment</button>
                            <button type="button" @click="openShare(post)" class="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">↗ Share</button>
                        </div>

                        <!-- Comments -->
                        <div class="space-y-3 p-4">
                            <div v-for="c in post.comments" :key="c.id" class="flex gap-2">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-200 text-xs font-bold text-slate-600">{{ c.user.name[0] }}</span>
                                <div class="min-w-0 rounded-2xl bg-slate-100 px-3 py-2 text-sm">
                                    <b class="text-slate-900">{{ c.user.name }}</b>
                                    <p class="break-words text-slate-700">{{ c.content }}</p>
                                    <div v-for="reply in c.replies" :key="reply.id" class="mt-2 border-l-2 border-slate-300 pl-2"><b>{{ reply.user.name }}</b> {{ reply.content }}</div>
                                </div>
                            </div>
                            <form @submit.prevent="submitComment(post)" class="flex items-center gap-2">
                                <input :id="`comment-${post.id}`" v-model="draft[post.id]" class="min-w-0 flex-1 rounded-full border-transparent bg-slate-100 px-4 text-sm focus:border-indigo-300 focus:bg-white focus:ring-indigo-300" placeholder="Write a comment…">
                                <button :disabled="!draft[post.id]?.trim()" class="rounded-full px-3 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 disabled:opacity-40 disabled:hover:bg-transparent">Post</button>
                            </form>
                        </div>
                    </article>

                    <button v-if="next" @click="more" :disabled="loadingMore" class="w-full rounded-xl bg-white py-3 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200/70 transition hover:bg-slate-50 disabled:opacity-60">{{ loadingMore ? 'Loading…' : 'Load more' }}</button>
                </main>

                <!-- Right column -->
                <aside class="hidden text-sm text-slate-500 lg:block">
                    <div class="sticky top-6 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
                        <p class="font-semibold text-slate-800">PCHAT Social</p>
                        <p class="mt-2">Share ideas and keep up with people you know.</p>
                    </div>
                </aside>
            </div>
        </div>

        <!-- Create post modal -->
        <div v-if="composer" class="fixed inset-0 z-30 grid place-items-center bg-slate-900/50 p-4 backdrop-blur-sm" @click.self="composer = false">
            <form @submit.prevent="create" class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">Create post</h2>
                    <button type="button" @click="composer = false" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Close">✕</button>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white">{{ $page.props.auth.user.name?.[0] }}</span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ $page.props.auth.user.name }}</p>
                        <select v-model="privacy" class="mt-0.5 rounded-md border-slate-200 py-0.5 pl-2 pr-7 text-xs">
                            <option value="public">🌐 Public</option><option value="friends">👥 Friends</option><option value="only_me">🔒 Only me</option>
                        </select>
                    </div>
                </div>
                <textarea v-model="content" class="mt-4 w-full resize-none rounded-xl border-slate-200 focus:border-indigo-300 focus:ring-indigo-300" rows="5" placeholder="What's on your mind?" />
                <label class="mt-3 block cursor-pointer rounded-xl border-2 border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    📷 Add photos or video
                    <input @change="select" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple class="hidden">
                </label>
                <div v-if="previews.length" class="mt-3 grid grid-cols-3 gap-2">
                    <div v-for="(p, i) in previews" :key="p.url" class="relative">
                        <img v-if="!p.video" :src="p.url" class="h-24 w-full rounded-lg object-cover" alt="">
                        <span v-else class="grid h-24 place-items-center rounded-lg bg-slate-100 p-2 text-center text-xs text-slate-600">🎥 {{ p.name }}</span>
                        <button type="button" @click="files.splice(i, 1)" class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-slate-900/70 text-xs text-white hover:bg-slate-900" aria-label="Remove">✕</button>
                    </div>
                </div>
                <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
                <button :disabled="busy" class="mt-4 w-full rounded-xl bg-indigo-600 py-3 font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">{{ busy ? 'Publishing…' : 'Publish' }}</button>
            </form>
        </div>

        <!-- Share modal -->
        <div v-if="sharePost" class="fixed inset-0 z-30 grid place-items-center bg-slate-900/50 p-4 backdrop-blur-sm" @click.self="sharePost = null">
            <form @submit.prevent="share" class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">Share post</h2>
                    <button type="button" @click="sharePost = null" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Close">✕</button>
                </div>
                <textarea v-model="shareCaption" class="mt-4 w-full resize-none rounded-xl border-slate-200 focus:border-indigo-300 focus:ring-indigo-300" rows="3" placeholder="Say something about this…" />
                <select v-model="sharePrivacy" class="mt-2 rounded-lg border-slate-200 text-sm">
                    <option value="public">🌐 Public</option><option value="friends">👥 Friends</option><option value="only_me">🔒 Only me</option>
                </select>
                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200">
                    <div class="flex items-center gap-2 p-3 text-sm">
                        <span class="grid h-8 w-8 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white">
                            <img v-if="sharePost.author.avatar_url" :src="sharePost.author.avatar_url" class="h-full w-full object-cover" alt="">
                            <template v-else>{{ sharePost.author.name[0] }}</template>
                        </span>
                        <b>{{ sharePost.author.name }}</b>
                    </div>
                    <p v-if="sharePost.content" class="whitespace-pre-wrap px-3 pb-3 text-sm text-slate-700">{{ sharePost.content }}</p>
                    <img v-if="sharePost.media?.[0]?.type === 'image'" :src="sharePost.media[0].url" class="max-h-72 w-full object-cover" alt="">
                    <video v-else-if="sharePost.media?.[0]?.type === 'video'" :src="sharePost.media[0].url" controls preload="metadata" class="max-h-72 w-full bg-black" />
                </div>
                <p v-if="shareError" class="mt-3 text-sm text-red-600">{{ shareError }}</p>
                <button :disabled="shareBusy" class="mt-4 w-full rounded-xl bg-indigo-600 py-3 font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">{{ shareBusy ? 'Sharing…' : 'Share now' }}</button>
            </form>
        </div>

        <!-- Lightbox -->
        <div v-if="lightboxPost" @click.self="closeLightbox" @touchstart.passive="startGallerySwipe" @touchend="endGallerySwipe" class="fixed inset-0 z-40 grid place-items-center bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="Post media">
            <button type="button" @click="closeLightbox" class="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-full bg-white/15 text-xl text-white hover:bg-white/25" aria-label="Close">✕</button>
            <div class="relative flex max-h-[85vh] max-w-full items-center justify-center">
                <img v-if="lightboxPost.media[lightboxIndex].type === 'image'" :src="lightboxPost.media[lightboxIndex].url" class="max-h-[85vh] max-w-full rounded-lg object-contain" alt="Post image">
                <video v-else :src="lightboxPost.media[lightboxIndex].url" controls autoplay class="max-h-[85vh] max-w-full rounded-lg" />
                <button v-if="lightboxPost.media.length > 1" type="button" @click.stop="moveLightbox(-1)" class="absolute left-3 top-1/2 z-10 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-black/55 text-white shadow-lg transition hover:bg-black/75 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label="Previous media" title="Previous media">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <button v-if="lightboxPost.media.length > 1" type="button" @click.stop="moveLightbox(1)" class="absolute right-3 top-1/2 z-10 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-black/55 text-white shadow-lg transition hover:bg-black/75 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label="Next media" title="Next media">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                </button>
                <p v-if="lightboxPost.media.length > 1" class="absolute bottom-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-black/60 px-3 py-1 text-sm text-white">{{ lightboxIndex + 1 }} / {{ lightboxPost.media.length }} <span class="hidden sm:inline">· Use arrow keys or swipe</span></p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
