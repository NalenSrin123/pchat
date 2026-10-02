<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CommentSection from '@/Components/Feed/CommentSection.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ posts: any; stories?: any[]; contacts?: any[]; reactionTypes: string[]; saved?: boolean }>();
const posts = ref<any[]>(props.posts.data);
const storyGroups = ref<any[]>(props.stories || []);
const contacts = computed(() => props.contacts || []);
const next = ref(props.posts.next_page_url);
const lightboxPost = ref<any | null>(null), lightboxIndex = ref(0), loadingMore = ref(false);
const sharePost = ref<any | null>(null), shareCaption = ref(''), sharePrivacy = ref('friends'), shareBusy = ref(false), shareError = ref('');
const editingPost = ref<any | null>(null), editContent = ref(''), editPrivacy = ref('friends'), editBusy = ref(false), editError = ref('');
const storyComposer = ref(false), storyContent = ref(''), storyPrivacy = ref('friends'), storyFile = ref<File | null>(null), storyPreview = ref(''), storyBusy = ref(false), storyError = ref('');
const storyGroup = ref<any | null>(null), storyIndex = ref(0);
const storyDetailsOpen = ref(false);
const storyReply = ref(''), storyReplyBusy = ref(false), storyReactionBusy = ref(false), storyReplyError = ref('');
const currentUserId = Number(usePage().props.auth.user.id);

/* ---------- UI state ---------- */
const menuFor = ref<number | null>(null);
const expandedText = ref<Record<number, boolean>>({});
const sentinel = ref<HTMLElement | null>(null);
const toast = ref('');
let toastTimer: any, observer: IntersectionObserver | null = null;
function notify(message: string) {
    toast.value = message;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toast.value = ''), 3200);
}
const closeFloating = () => { pickerFor.value = null; menuFor.value = null; };

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

/* ---------- Stories ---------- */
function selectStoryFile(event: Event) {
    if (storyPreview.value) URL.revokeObjectURL(storyPreview.value);
    storyFile.value = (event.target as HTMLInputElement).files?.[0] || null;
    storyPreview.value = storyFile.value ? URL.createObjectURL(storyFile.value) : '';
}
function closeStoryComposer() {
    storyComposer.value = false; storyContent.value = ''; storyPrivacy.value = 'friends'; storyFile.value = null; storyError.value = '';
    if (storyPreview.value) URL.revokeObjectURL(storyPreview.value);
    storyPreview.value = '';
}
async function createStory() {
    if (storyBusy.value) return;
    if (!storyContent.value.trim() && !storyFile.value) { storyError.value = 'Add text, a photo, or a video to your story.'; return; }
    storyBusy.value = true; storyError.value = '';
    try {
        const fd = new FormData(); fd.append('content', storyContent.value); fd.append('privacy', storyPrivacy.value);
        if (storyFile.value) fd.append('media', storyFile.value);
        const { data } = await axios.post('/stories', fd);
        const existing = storyGroups.value.find(group => group.author.id === data.data.author.id);
        if (existing) existing.stories.unshift(data.data);
        else storyGroups.value.unshift({ author: data.data.author, stories: [data.data] });
        closeStoryComposer();
        notify('Story shared');
    } catch (e: any) { storyError.value = e.response?.data?.message || 'Could not publish your story.'; }
    finally { storyBusy.value = false; }
}
function openStory(group: any) { storyGroup.value = group; storyIndex.value = 0; storyDetailsOpen.value = false; storyReply.value = ''; storyReplyError.value = ''; }
function closeStory() { storyGroup.value = null; storyDetailsOpen.value = false; storyReply.value = ''; storyReplyError.value = ''; }
function moveStory(direction: number) {
    const count = storyGroup.value?.stories?.length || 0;
    if (!count) return;
    const target = storyIndex.value + direction;
    if (target < 0) return;
    if (target >= count) { closeStory(); return; }
    storyIndex.value = target;
}
const activeStory = computed(() => storyGroup.value?.stories?.[storyIndex.value] || null);
const isMyStory = computed(() => Number(activeStory.value?.author?.id) === currentUserId);
async function recordStoryView(story: any) {
    if (!story) return;
    try { const { data } = await axios.post(`/stories/${story.id}/views`); Object.assign(story, data.data); }
    catch { /* A failed view record must not interrupt playback. */ }
}
async function reactStory(reaction: string) {
    if (!activeStory.value || storyReactionBusy.value) return;
    storyReactionBusy.value = true;
    try { const { data } = await axios.post(`/stories/${activeStory.value.id}/reactions`, { reaction }); Object.assign(activeStory.value, data.data); }
    catch (e: any) { notify(e.response?.data?.message || 'Could not save your story reaction.'); }
    finally { storyReactionBusy.value = false; }
}
async function replyToStory() {
    if (!activeStory.value || !storyReply.value.trim() || storyReplyBusy.value) return;
    storyReplyBusy.value = true; storyReplyError.value = '';
    try { await axios.post(`/stories/${activeStory.value.id}/replies`, { message: storyReply.value }); storyReply.value = ''; notify('Reply sent in Messenger'); }
    catch (e: any) { storyReplyError.value = e.response?.data?.message || 'Could not send your reply.'; }
    finally { storyReplyBusy.value = false; }
}
const storyViewerNames = (story: any) => (story.viewers || []).map((viewer: any) => viewer.name).join(', ');
const storyReactionSummary = (story: any) => (story.reactions || []).map((item: any) => `${item.reaction} ${item.user?.name || ''}`).join(' · ');
watch(activeStory, story => { if (story) void recordStoryView(story); });

/* ---------- Helpers ---------- */
const ago = (v: string) => {
    const s = Math.floor((Date.now() - new Date(v).getTime()) / 1000);
    if (s < 60) return 'Just now';
    if (s < 3600) return `${Math.floor(s / 60)}m`;
    if (s < 86400) return `${Math.floor(s / 3600)}h`;
    if (s < 604800) return `${Math.floor(s / 86400)}d`;
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(v));
};
const fullDate = (v: string) => new Intl.DateTimeFormat(undefined, { dateStyle: 'full', timeStyle: 'short' }).format(new Date(v));
const privacyLabel = (p: string) => ({ public: 'Public', friends: 'Friends', only_me: 'Only me' } as Record<string, string>)[p] || 'Friends';
const privacyIcon = (p: string) => ({ public: '🌐', friends: '👥', only_me: '🔒' } as Record<string, string>)[p] || '👥';

/* Text: short text-only posts are shown larger, long ones are clamped. */
const TEXT_LIMIT = 280;
const isLong = (post: any) => (post.content?.length || 0) > TEXT_LIMIT;
const shownText = (post: any) => (isLong(post) && !expandedText.value[post.id] ? post.content.slice(0, TEXT_LIMIT).trimEnd() + '…' : post.content);
const bigText = (post: any) => post.content && post.content.length < 90 && !post.media?.length && !post.shared_post;

/* Media grid: layout depends on how many items there are. */
const previewMedia = (post: any) => post.media?.slice(0, 4) || [];
const remainingMediaCount = (post: any) => Math.max(0, (post.media?.length || 0) - 4);
const mediaWrapClass = (post: any) => {
    const n = Math.min(post.media?.length || 0, 4);
    if (n === 1) return '';
    if (n === 2) return 'grid grid-cols-2 gap-0.5 aspect-[16/10]';
    return 'grid grid-cols-2 grid-rows-2 gap-0.5 aspect-[4/3]';
};
const mediaTileClass = (post: any, index: number) => {
    const n = Math.min(post.media?.length || 0, 4);
    return n === 3 && index === 0 ? 'row-span-2' : '';
};


/* ---------- Lightbox ---------- */
const touchStartX = ref<number | null>(null);
function openLightbox(post: any, index: number) { lightboxPost.value = post; lightboxIndex.value = index; }
function closeLightbox() { lightboxPost.value = null; }
function moveLightbox(direction: number) {
    const count = lightboxPost.value?.media?.length || 0;
    if (count) lightboxIndex.value = (lightboxIndex.value + direction + count) % count;
}
function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        if (lightboxPost.value) closeLightbox();
        else if (storyGroup.value) closeStory();
        else if (sharePost.value) sharePost.value = null;
        else if (editingPost.value) editingPost.value = null;
        else if (storyComposer.value) closeStoryComposer();
        else closeFloating();
        return;
    }
    const dir = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0;
    if (!dir) return;
    if (lightboxPost.value) moveLightbox(dir);
    else if (storyGroup.value) moveStory(dir);
}
function startGallerySwipe(event: TouchEvent) { touchStartX.value = event.changedTouches[0]?.clientX ?? null; }
function endGallerySwipe(event: TouchEvent) {
    const start = touchStartX.value;
    const end = event.changedTouches[0]?.clientX;
    touchStartX.value = null;
    if (start === null || end === undefined || Math.abs(start - end) < 50) return;
    moveLightbox(start > end ? 1 : -1);
}
const anyOverlay = computed(() => !!(lightboxPost.value || storyGroup.value || sharePost.value || editingPost.value || storyComposer.value));
watch(anyOverlay, open => { document.body.style.overflow = open ? 'hidden' : ''; });

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if ('IntersectionObserver' in window && sentinel.value) {
        observer = new IntersectionObserver(entries => { if (entries[0].isIntersecting) more(); }, { rootMargin: '600px 0px' });
        observer.observe(sentinel.value);
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    observer?.disconnect();
    document.body.style.overflow = '';
    clearTimeout(toastTimer);
});

/* ---------- Actions ---------- */
async function react(post: any, reaction: string) {
    if (post.reacting) return;
    const previous = post.my_reaction;
    post.reacting = true;
    post.my_reaction = previous === reaction ? null : reaction;
    try { const { data } = await axios.post(`/posts/${post.id}/reactions`, { reaction }); Object.assign(post, data.data); }
    catch { post.my_reaction = previous; notify('Could not save your reaction. Try again.'); }
    finally { post.reacting = false; }
}
const focusComment = (post: any) => (document.querySelector(`#post-${post.id} textarea`) as HTMLTextAreaElement | null)?.focus();
function openShare(post: any) { sharePost.value = post; shareCaption.value = ''; sharePrivacy.value = 'friends'; shareError.value = ''; }
async function share() {
    if (!sharePost.value || shareBusy.value) return;
    shareBusy.value = true; shareError.value = '';
    try {
        const { data } = await axios.post(`/posts/${sharePost.value.id}/share`, { content: shareCaption.value, privacy: sharePrivacy.value });
        posts.value.unshift(data.data); sharePost.value = null; notify('Post shared');
    }
    catch (e: any) { shareError.value = e.response?.data?.message || 'Could not share this post.'; }
    finally { shareBusy.value = false; }
}
async function remove(post: any) {
    menuFor.value = null;
    if (!confirm('Delete this post? This cannot be undone.')) return;
    try {
        await axios.delete(`/posts/${post.id}`);
        posts.value = posts.value.filter(x => x.id !== post.id);
        notify('Post deleted');
    } catch { notify('Could not delete this post.'); }
}
function openEdit(post: any) {
    menuFor.value = null;
    editingPost.value = post;
    editContent.value = post.content || '';
    editPrivacy.value = post.privacy;
    editError.value = '';
}
async function updatePost() {
    if (!editingPost.value || editBusy.value) return;
    if (!editContent.value.trim() && !editingPost.value.media?.length) { editError.value = 'Write something or keep media on this post.'; return; }
    editBusy.value = true; editError.value = '';
    try {
        const { data } = await axios.put(`/posts/${editingPost.value.id}`, { content: editContent.value, privacy: editPrivacy.value });
        Object.assign(editingPost.value, data.data);
        editingPost.value = null;
        notify('Post updated');
    } catch (e: any) { editError.value = e.response?.data?.message || 'Could not update your post.'; }
    finally { editBusy.value = false; }
}
async function more() {
    if (!next.value || loadingMore.value) return;
    loadingMore.value = true;
    try { const { data } = await axios.get(next.value); posts.value.push(...data.data); next.value = data.next_page_url; }
    catch { notify('Could not load more posts.'); }
    finally { loadingMore.value = false; }
}
</script>

<template>
    <Head :title="saved ? 'Saved posts' : 'Feed'" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-[#f0f2f5]" @click="closeFloating">
            <div class="mx-auto grid max-w-[1400px] justify-center gap-6 px-3 py-5 sm:px-4 lg:grid-cols-[minmax(0,680px)_300px] xl:grid-cols-[260px_minmax(0,680px)_300px]">

                <!-- Left nav -->
                <aside class="hidden xl:block">
                    <nav class="sticky top-20 space-y-1" aria-label="Shortcuts">
                        <Link :href="route('social.profile', $page.props.auth.user.id)" class="mb-2 flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-slate-900 transition hover:bg-slate-200/70">
                            <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-blue-600 font-bold text-white">
                                <img v-if="$page.props.auth.user.avatar_url" :src="$page.props.auth.user.avatar_url" class="h-full w-full object-cover" alt="">
                                <template v-else>{{ $page.props.auth.user.name?.[0] }}</template>
                            </span>
                            <span class="truncate">{{ $page.props.auth.user.name }}</span>
                        </Link>
                        <Link :href="route('feed')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold transition" :class="!saved ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200/70' : 'text-slate-700 hover:bg-slate-200/70'"><span class="grid h-9 w-9 place-items-center rounded-full bg-blue-100 text-lg">🏠</span>Feed</Link>
                        <Link :href="route('dashboard')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-slate-700 transition hover:bg-slate-200/70"><span class="grid h-9 w-9 place-items-center rounded-full bg-sky-100 text-lg">💬</span>Messenger</Link>
                        <Link :href="route('friends')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-slate-700 transition hover:bg-slate-200/70"><span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-100 text-lg">👥</span>Friends</Link>
                        <Link :href="route('saved')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold transition" :class="saved ? 'bg-white text-blue-700 shadow-sm ring-1 ring-slate-200/70' : 'text-slate-700 hover:bg-slate-200/70'"><span class="grid h-9 w-9 place-items-center rounded-full bg-violet-100 text-lg">🔖</span>Saved</Link>
                        <Link :href="route('profile.edit')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-slate-700 transition hover:bg-slate-200/70"><span class="grid h-9 w-9 place-items-center rounded-full bg-amber-100 text-lg">⚙️</span>Settings</Link>
                    </nav>
                </aside>

                <main class="min-w-0 space-y-4">
                    <!-- Composer trigger -->
                    <section v-if="!saved" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-blue-600 font-bold text-white">
                                <img v-if="$page.props.auth.user.avatar_url" :src="$page.props.auth.user.avatar_url" class="h-full w-full object-cover" alt="">
                                <template v-else>{{ $page.props.auth.user.name?.[0] }}</template>
                            </span>
                            <Link :href="route('posts.create')" class="min-w-0 flex-1 truncate rounded-full bg-slate-100 px-5 py-3 text-left text-[15px] text-slate-500 transition hover:bg-slate-200/70">What's on your mind, {{ $page.props.auth.user.name?.split(' ')[0] }}?</Link>
                        </div>
                        <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                            <Link :href="route('posts.create')" class="flex flex-1 items-center justify-center gap-2 rounded-xl py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100">
                                <svg class="h-6 w-6 text-rose-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h7A2.5 2.5 0 0 1 16 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-7A2.5 2.5 0 0 1 4 17.5zM17.5 9.7l3.2-2.1a.8.8 0 0 1 1.3.7v7.4a.8.8 0 0 1-1.3.7l-3.2-2.1z" /></svg>
                                <span>Live video</span>
                            </Link>
                            <Link :href="route('posts.create')" class="flex flex-1 items-center justify-center gap-2 rounded-xl py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100">
                                <svg class="h-6 w-6 text-emerald-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 3h14a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3zm3.5 4.5a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6zM4 17.5h16l-4.6-6.2-3.4 4.4-2.2-2.6z" /></svg>
                                <span>Photo / video</span>
                            </Link>
                        </div>
                    </section>

                    <!-- Stories -->
                    <section v-if="!saved" class="stories-rail -mx-3 flex snap-x snap-mandatory gap-2.5 overflow-x-auto px-3 pb-1 sm:mx-0 sm:px-0" aria-label="Stories">
                        <button type="button" @click.stop="storyComposer = true" class="group relative h-52 w-32 shrink-0 snap-start overflow-hidden rounded-2xl bg-white text-left shadow-sm ring-1 ring-slate-200/70 transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <div class="h-36 w-full overflow-hidden bg-gradient-to-b from-blue-500 to-blue-700">
                                <img v-if="$page.props.auth.user.avatar_url" :src="$page.props.auth.user.avatar_url" :alt="$page.props.auth.user.name" class="h-full w-full object-cover transition duration-200 group-hover:scale-105">
                                <span v-else class="grid h-full place-items-center text-4xl font-bold text-white/80">{{ $page.props.auth.user.name?.[0] }}</span>
                            </div>
                            <span class="absolute left-1/2 top-[120px] grid h-9 w-9 -translate-x-1/2 place-items-center rounded-full border-4 border-white bg-blue-600 text-xl leading-none text-white">+</span>
                            <span class="absolute inset-x-1 bottom-3 text-center text-[13px] font-semibold text-slate-800">Create story</span>
                        </button>

                        <button v-for="group in storyGroups" :key="`story-user-${group.author.id}`" type="button" @click.stop="openStory(group)" class="group relative h-52 w-32 shrink-0 snap-start overflow-hidden rounded-2xl bg-slate-800 text-left shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                            <img v-if="group.stories[0].media_type === 'image'" :src="group.stories[0].media_url" class="h-full w-full object-cover transition duration-200 group-hover:scale-105" alt="">
                            <video v-else-if="group.stories[0].media_type === 'video'" :src="group.stories[0].media_url" muted preload="metadata" class="h-full w-full object-cover" />
                            <span v-else class="grid h-full place-items-center bg-gradient-to-br from-violet-500 to-indigo-700 p-3 text-center text-sm font-semibold leading-snug text-white">{{ group.stories[0].content }}</span>
                            <span class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-black/70 to-transparent"></span>
                            <span class="absolute left-2.5 top-2.5 grid h-10 w-10 place-items-center overflow-hidden rounded-full bg-blue-600 text-sm font-bold text-white ring-[3px] ring-blue-400 ring-offset-2 ring-offset-black/30"><img v-if="group.author.avatar_url" :src="group.author.avatar_url" class="h-full w-full object-cover" alt=""><template v-else>{{ group.author.name[0] }}</template></span>
                            <span class="absolute inset-x-2.5 bottom-2.5 truncate text-[13px] font-semibold text-white">{{ group.author.name }}</span>
                        </button>
                    </section>
                    <h1 v-else class="text-2xl font-bold text-slate-900">Saved posts</h1>

                    <!-- Empty state -->
                    <div v-if="!posts.length" class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-slate-200/70">
                        <p class="text-4xl">{{ saved ? '🔖' : '✨' }}</p>
                        <p class="mt-3 text-lg font-semibold text-slate-800">{{ saved ? 'No saved posts yet' : 'Your feed is empty' }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ saved ? 'Save a post to find it here later.' : 'Share your first post, or add friends to see theirs.' }}</p>
                        <Link v-if="!saved" :href="route('posts.create')" class="mt-5 inline-block rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Create a post</Link>
                        <Link v-else :href="route('feed')" class="mt-5 inline-block rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Back to feed</Link>
                    </div>

                    <!-- Posts -->
                    <article v-for="post in posts" :id="`post-${post.id}`" :key="post.id" class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200/70">
                        <header class="flex items-center gap-3 px-4 pb-3 pt-4">
                            <Link :href="route('social.profile', post.author.id)" class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white">
                                <img v-if="post.author.avatar_url" :src="post.author.avatar_url" class="h-full w-full object-cover" alt="">
                                <template v-else>{{ post.author.name[0] }}</template>
                            </Link>
                            <div class="min-w-0 flex-1">
                                <Link :href="route('social.profile', post.author.id)" class="block truncate text-[15px] font-semibold leading-tight text-slate-900 hover:underline">{{ post.author.name }}</Link>
                                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
                                    <time :datetime="post.created_at" :title="fullDate(post.created_at)">{{ ago(post.created_at) }}</time>
                                    <span aria-hidden="true">·</span>
                                    <span :title="privacyLabel(post.privacy)" :aria-label="privacyLabel(post.privacy)">{{ privacyIcon(post.privacy) }}</span>
                                </p>
                            </div>
                            <div v-if="post.user_id === $page.props.auth.user.id" class="relative" @click.stop>
                                <button type="button" @click="menuFor = menuFor === post.id ? null : post.id" class="grid h-9 w-9 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" aria-label="Post options" :aria-expanded="menuFor === post.id">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                                </button>
                                <Transition enter-active-class="transition duration-100 ease-out" enter-from-class="scale-95 opacity-0" leave-active-class="transition duration-75" leave-to-class="opacity-0">
                                    <div v-if="menuFor === post.id" class="absolute right-0 z-10 mt-1 w-44 origin-top-right rounded-xl bg-white p-1 shadow-lg ring-1 ring-slate-200">
                                        <button type="button" @click="openEdit(post)" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50">Edit post</button>
                                        <button type="button" @click="remove(post)" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50">Delete post</button>
                                    </div>
                                </Transition>
                            </div>
                        </header>

                        <div v-if="post.content" class="whitespace-pre-wrap break-words px-4 pb-3 text-slate-800" :class="bigText(post) ? 'py-4 text-2xl font-medium leading-snug' : 'text-[15px] leading-relaxed'">{{ shownText(post) }}<button v-if="isLong(post) && !expandedText[post.id]" type="button" @click="expandedText[post.id] = true" class="ml-1 font-semibold text-slate-500 hover:underline">See more</button></div>

                        <!-- Media: 1 = natural size, 2 = side by side, 3 = one large + two, 4+ = grid with "+N" -->
                        <div v-if="post.media?.length" class="overflow-hidden bg-slate-100" :class="mediaWrapClass(post)">
                            <button v-for="(media, index) in previewMedia(post)" :key="media.id" type="button" @click="openLightbox(post, index)"
                                class="group relative block min-w-0 overflow-hidden bg-slate-900 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500"
                                :class="[mediaTileClass(post, index), post.media.length === 1 ? 'w-full' : 'h-full w-full']"
                                :aria-label="`Open ${media.type} ${index + 1} of ${post.media.length}`">
                                <img v-if="media.type === 'image'" :src="media.url" loading="lazy" :class="post.media.length === 1 ? 'mx-auto max-h-[560px] w-full bg-slate-100 object-contain' : 'h-full w-full object-cover'" class="transition duration-200 group-hover:brightness-95" alt="Post image">
                                <video v-else :src="media.url" preload="metadata" muted :class="post.media.length === 1 ? 'aspect-video w-full object-cover' : 'h-full w-full object-cover'" />
                                <span v-if="media.type === 'video'" class="absolute inset-0 grid place-items-center">
                                    <span class="grid h-14 w-14 place-items-center rounded-full bg-black/55 text-white shadow-lg backdrop-blur-sm transition group-hover:bg-black/70">
                                        <svg class="ml-0.5 h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
                                    </span>
                                </span>
                                <span v-if="index === 3 && remainingMediaCount(post)" class="absolute inset-0 grid place-items-center bg-black/55 text-4xl font-semibold text-white">+{{ remainingMediaCount(post) }}</span>
                            </button>
                        </div>

                        <div v-if="post.shared_post" class="mx-4 mb-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50/50">
                            <p class="p-3 text-sm"><b class="text-slate-900">{{ post.shared_post.author.name }}</b><span class="mt-1 block text-slate-700">{{ post.shared_post.content }}</span></p>
                            <img v-if="post.shared_post.media?.[0]?.type === 'image'" :src="post.shared_post.media[0].url" class="max-h-80 w-full object-cover" alt="">
                        </div>

                        <!-- Counts -->
                        <div class="flex items-center justify-between px-4 py-2.5 text-[13px] text-slate-500">
                            <div v-if="summary(post).total" class="group relative flex items-center gap-1.5">
                                <span class="flex -space-x-1">
                                    <span v-for="r in summary(post).top" :key="r" class="grid h-6 w-6 place-items-center rounded-full bg-white text-sm ring-2 ring-white">{{ r }}</span>
                                </span>
                                <span class="font-medium">{{ summary(post).total }}</span>
                                <div class="pointer-events-none absolute bottom-full left-0 z-10 mb-1 hidden rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-lg group-hover:block">
                                    <p v-for="[r, n] in summary(post).all" :key="r" class="whitespace-nowrap">{{ r }} {{ n }}</p>
                                </div>
                            </div>
                            <span v-else class="text-slate-400">No reactions yet</span>
                            <span class="flex gap-3">
                                <button type="button" class="hover:underline" @click="focusComment(post)">{{ post.comments_count }} {{ post.comments_count === 1 ? 'comment' : 'comments' }}</button>
                                <span>{{ post.shares_count }} {{ post.shares_count === 1 ? 'share' : 'shares' }}</span>
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="mx-4 grid grid-cols-3 gap-1 border-y border-slate-100 py-1">
                            <div class="relative" @mouseenter="openPicker(post.id)" @mouseleave="closePicker" @click.stop>
                                <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                                    <div v-if="pickerFor === post.id" @mouseenter="keepPicker" class="absolute bottom-full left-0 z-20 mb-2 flex gap-1 rounded-full bg-white px-2 py-1.5 shadow-xl ring-1 ring-slate-200">
                                        <button v-for="r in reactions" :key="r" type="button" @click="pick(post, r)" :title="labelOf(r)" :aria-label="labelOf(r)"
                                            class="grid h-10 w-10 place-items-center rounded-full text-2xl transition-transform duration-150 hover:-translate-y-1 hover:scale-125 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                            :class="post.my_reaction === r ? 'bg-indigo-50' : ''">{{ r }}</button>
                                    </div>
                                </Transition>
                                <button type="button" @click="quickReact(post)" @touchstart.passive="pressStart(post.id)" @touchend="pressEnd" @touchmove="pressEnd" @contextmenu.prevent
                                    class="flex w-full items-center justify-center gap-2 rounded-lg py-2.5 text-sm font-semibold transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                    :class="post.my_reaction ? colorOf(post.my_reaction) : 'text-slate-600'">
                                    <span class="text-lg leading-none" :class="post.my_reaction ? '' : 'grayscale'">{{ post.my_reaction || '👍' }}</span>{{ post.my_reaction ? labelOf(post.my_reaction) : 'Like' }}
                                </button>
                            </div>
                            <button type="button" @click="focusComment(post)" class="flex items-center justify-center gap-2 rounded-lg py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 8.9 8.9 0 0 1-3.6-.8L3 20l1.2-4.6A8.1 8.1 0 0 1 3 11.5 8.4 8.4 0 0 1 11.5 3 8.4 8.4 0 0 1 21 11.5z" /></svg>Comment
                            </button>
                            <button type="button" @click="openShare(post)" class="flex items-center justify-center gap-2 rounded-lg py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5 6 6-6 6M21 11H9a6 6 0 0 0-6 6v2" /></svg>Share
                            </button>
                        </div>

                        <CommentSection :post="post" />
                    </article>

                    <!-- Infinite scroll sentinel (button stays as a fallback) -->
                    <div ref="sentinel" class="py-2 text-center">
                        <button v-if="next" type="button" @click="more" :disabled="loadingMore" class="w-full rounded-xl bg-white py-3 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200/70 transition hover:bg-slate-50 disabled:opacity-60">{{ loadingMore ? 'Loading…' : 'Load more' }}</button>
                        <p v-else-if="posts.length" class="text-sm text-slate-400">You're all caught up</p>
                    </div>
                </main>

                <!-- Right column -->
                <aside class="hidden text-sm text-slate-500 lg:block">
                    <div class="sticky top-20 space-y-4">
                        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
                            <p class="font-bold text-slate-800">Your PCHAT</p>
                            <p class="mt-1.5 leading-5">Share updates, photos, and videos with the people you know.</p>
                            <Link :href="route('posts.create')" class="mt-3 block rounded-xl bg-indigo-50 py-2 text-center font-semibold text-indigo-700 transition hover:bg-indigo-100">Create a post</Link>
                        </section>
                        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70">
                            <div class="flex items-center justify-between"><p class="font-bold text-slate-800">Contacts</p><Link :href="route('friends')" class="font-medium text-blue-600 hover:underline">See all</Link></div>
                            <Link v-for="contact in contacts" :key="contact.id" :href="route('dashboard')" class="mt-2 flex items-center gap-3 rounded-lg px-1 py-1.5 font-semibold text-slate-800 transition hover:bg-slate-100">
                                <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full bg-blue-600 text-white"><span class="grid h-full w-full place-items-center overflow-hidden rounded-full"><img v-if="contact.avatar_url" :src="contact.avatar_url" :alt="contact.name" class="h-full w-full object-cover"><template v-else>{{ contact.name?.[0] }}</template></span><i class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-emerald-500"></i></span>
                                <span class="truncate">{{ contact.name }}</span>
                            </Link>
                            <div v-if="!contacts.length" class="mt-3 rounded-xl bg-slate-50 p-3 text-center">
                                <p>No friends are active right now.</p>
                                <Link :href="route('friends')" class="mt-1 inline-block font-semibold text-blue-600 hover:underline">Find friends</Link>
                            </div>
                        </section>
                        <p class="px-1 text-xs text-slate-400">Privacy · Terms · Help · PCHAT © {{ new Date().getFullYear() }}</p>
                    </div>
                </aside>
            </div>
        </div>

        <!-- Toast -->
        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
            <div v-if="toast" role="status" class="fixed bottom-5 left-1/2 z-50 -translate-x-1/2 rounded-full bg-slate-900 px-5 py-2.5 text-sm font-medium text-white shadow-xl">{{ toast }}</div>
        </Transition>

        <!-- Edit post modal -->
        <div v-if="editingPost" class="fixed inset-0 z-30 grid place-items-center bg-slate-900/50 p-4 backdrop-blur-sm" @click.self="editingPost = null">
            <form @submit.prevent="updatePost" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-label="Edit post">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">Edit post</h2>
                    <button type="button" @click="editingPost = null" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Close">✕</button>
                </div>
                <select v-model="editPrivacy" class="mt-4 rounded-lg border-slate-200 text-sm">
                    <option value="public">🌐 Public</option><option value="friends">👥 Friends</option><option value="only_me">🔒 Only me</option>
                </select>
                <textarea v-model="editContent" class="mt-3 w-full resize-none rounded-xl border-slate-200 focus:border-indigo-300 focus:ring-indigo-300" rows="5" placeholder="What's on your mind?" />
                <p v-if="editError" class="mt-3 text-sm text-red-600">{{ editError }}</p>
                <button :disabled="editBusy" class="mt-4 w-full rounded-xl bg-indigo-600 py-3 font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">{{ editBusy ? 'Saving…' : 'Save changes' }}</button>
            </form>
        </div>

        <!-- Create story modal -->
        <div v-if="storyComposer" class="fixed inset-0 z-30 grid place-items-center bg-slate-900/50 p-4 backdrop-blur-sm" @click.self="closeStoryComposer">
            <form @submit.prevent="createStory" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-label="Create story">
                <div class="flex items-center justify-between"><h2 class="text-lg font-bold text-slate-900">Create story</h2><button type="button" @click="closeStoryComposer" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200" aria-label="Close">✕</button></div>
                <select v-model="storyPrivacy" class="mt-4 rounded-lg border-slate-200 text-sm"><option value="public">🌐 Public</option><option value="friends">👥 Friends</option><option value="only_me">🔒 Only me</option></select>
                <textarea v-model="storyContent" class="mt-3 w-full resize-none rounded-xl border-slate-200 focus:border-indigo-300 focus:ring-indigo-300" rows="4" placeholder="Share a moment…" />
                <label class="mt-3 block cursor-pointer rounded-xl border-2 border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 hover:border-indigo-300 hover:bg-indigo-50/40">📷 Add photo or video<input @change="selectStoryFile" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" class="hidden"></label>
                <img v-if="storyPreview && storyFile?.type.startsWith('image/')" :src="storyPreview" class="mt-3 max-h-52 w-full rounded-xl object-cover" alt="Story preview">
                <p v-else-if="storyFile" class="mt-3 rounded-xl bg-slate-100 p-3 text-sm text-slate-600">🎥 {{ storyFile.name }}</p>
                <p class="mt-3 text-xs text-slate-500">Stories disappear after 24 hours.</p><p v-if="storyError" class="mt-2 text-sm text-red-600">{{ storyError }}</p>
                <button :disabled="storyBusy" class="mt-4 w-full rounded-xl bg-indigo-600 py-3 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{{ storyBusy ? 'Publishing…' : 'Share to story' }}</button>
            </form>
        </div>

        <!-- Share modal -->
        <div v-if="sharePost" class="fixed inset-0 z-30 grid place-items-center bg-slate-900/50 p-4 backdrop-blur-sm" @click.self="sharePost = null">
            <form @submit.prevent="share" class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-label="Share post">
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
            <button type="button" @click="closeLightbox" class="absolute right-4 top-4 z-10 grid h-10 w-10 place-items-center rounded-full bg-white/15 text-xl text-white hover:bg-white/25" aria-label="Close">✕</button>
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

        <!-- Story viewer: tap the left or right side to move between stories. -->
        <div v-if="activeStory" @click.self="closeStory" class="fixed inset-0 z-40 grid place-items-center bg-black/90 p-4" role="dialog" aria-modal="true" aria-label="Story viewer">
            <div class="relative flex h-[min(80vh,640px)] w-full max-w-md items-center justify-center overflow-hidden rounded-2xl bg-slate-900">
                <img v-if="activeStory.media_type === 'image'" :src="activeStory.media_url" class="h-full w-full object-contain" alt="Story">
                <video v-else-if="activeStory.media_type === 'video'" :src="activeStory.media_url" controls autoplay class="h-full w-full object-contain" />
                <p v-else class="bg-gradient-to-br from-violet-500 to-indigo-700 p-10 text-center text-2xl font-semibold text-white" style="position:absolute;inset:0;display:grid;place-items:center">{{ activeStory.content }}</p>
                <button v-if="activeStory.media_type !== 'video'" type="button" class="absolute inset-y-16 left-0 w-1/3 cursor-pointer" aria-label="Previous story" @click="moveStory(-1)"></button>
                <button v-if="activeStory.media_type !== 'video'" type="button" class="absolute inset-y-16 right-0 w-1/3 cursor-pointer" aria-label="Next story" @click="moveStory(1)"></button>
                <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/60 to-transparent"></div>
                <div class="absolute inset-x-3 top-3 flex gap-1"><i v-for="(_, index) in storyGroup.stories" :key="index" class="h-1 flex-1 rounded" :class="index <= storyIndex ? 'bg-white' : 'bg-white/35'"></i></div>
                <div class="absolute left-4 right-4 top-6 flex items-center gap-2 text-sm font-semibold text-white"><span class="grid h-8 w-8 place-items-center overflow-hidden rounded-full bg-blue-600"><img v-if="storyGroup.author.avatar_url" :src="storyGroup.author.avatar_url" class="h-full w-full object-cover" alt=""><template v-else>{{ storyGroup.author.name[0] }}</template></span>{{ storyGroup.author.name }}<button type="button" @click="closeStory" class="ml-auto grid h-8 w-8 place-items-center rounded-full text-xl hover:bg-white/20" aria-label="Close">✕</button></div>
                <div v-if="isMyStory" class="absolute inset-x-4 bottom-4 rounded-xl bg-black/60 p-3 text-sm text-white backdrop-blur">
                    <p class="font-semibold">👁 {{ activeStory.views_count }} {{ activeStory.views_count === 1 ? 'view' : 'views' }}</p>
                    <p v-if="activeStory.viewers?.length" class="mt-1 truncate text-xs text-white/85">Viewed by {{ storyViewerNames(activeStory) }}</p>
                    <p v-if="activeStory.reactions?.length" class="mt-1 truncate text-xs text-white/85">{{ storyReactionSummary(activeStory) }}</p>
                    <button type="button" @click="storyDetailsOpen = true" class="mt-2 rounded-lg bg-white/15 px-2.5 py-1 text-xs font-semibold transition hover:bg-white/25">View details</button>
                </div>
                <form v-else @submit.prevent="replyToStory" class="absolute inset-x-4 bottom-4 flex gap-2">
                    <div class="flex min-w-0 flex-1 items-center rounded-full bg-white/95 px-3 shadow-lg"><input v-model="storyReply" class="min-w-0 flex-1 border-0 bg-transparent py-2 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-0" placeholder="Reply to story…" aria-label="Reply to story"><button v-for="reaction in reactions.slice(0, 3)" :key="reaction" type="button" :disabled="storyReactionBusy" @click="reactStory(reaction)" :class="activeStory.my_reaction === reaction ? 'bg-indigo-100' : ''" class="ml-1 rounded-full p-1 text-base hover:bg-slate-100 disabled:opacity-50" :aria-label="`React ${labelOf(reaction)}`">{{ reaction }}</button></div>
                    <button :disabled="!storyReply.trim() || storyReplyBusy" class="rounded-full bg-indigo-600 px-4 text-sm font-semibold text-white shadow-lg disabled:opacity-50">Send</button>
                    <p v-if="storyReplyError" class="absolute -top-7 left-0 rounded bg-red-600 px-2 py-1 text-xs text-white">{{ storyReplyError }}</p>
                </form>
                <div v-if="isMyStory && storyDetailsOpen" class="absolute inset-x-4 bottom-4 max-h-[55%] overflow-y-auto rounded-xl bg-white p-4 text-slate-900 shadow-2xl" role="dialog" aria-modal="true" aria-label="Story details">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="font-bold">Story details</h2><p class="text-xs text-slate-500">{{ activeStory.views_count }} {{ activeStory.views_count === 1 ? 'view' : 'views' }}</p></div><button type="button" @click="storyDetailsOpen = false" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-lg text-slate-600 hover:bg-slate-200" aria-label="Close details">✕</button></div>
                    <p v-if="!activeStory.viewers?.length" class="mt-4 text-sm text-slate-500">No viewers yet.</p>
                    <ul v-else class="mt-3 space-y-2">
                        <li v-for="viewer in activeStory.viewers" :key="viewer.id" class="flex items-center gap-3 rounded-lg px-1 py-1.5"><span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-full bg-indigo-100 font-semibold text-indigo-700"><img v-if="viewer.avatar_url" :src="viewer.avatar_url" :alt="viewer.name" class="h-full w-full object-cover"><template v-else>{{ viewer.name?.[0] }}</template></span><span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ viewer.name }}</span><span v-if="viewer.username" class="block truncate text-xs text-slate-500">@{{ viewer.username }}</span></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.stories-rail {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.stories-rail::-webkit-scrollbar {
    display: none;
}

@media (prefers-reduced-motion: reduce) {
    * {
        transition-duration: 0.01ms !important;
    }
}
</style>
