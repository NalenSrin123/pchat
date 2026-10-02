<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, ref, watch } from 'vue';

const content = ref('');
const privacy = ref('friends');
const files = ref<File[]>([]);
const previews = ref<{ name: string; url: string; video: boolean }[]>([]);
const busy = ref(false);
const error = ref('');

function select(event: Event) {
    files.value = [...files.value, ...Array.from((event.target as HTMLInputElement).files || [])].slice(0, 10);
    (event.target as HTMLInputElement).value = '';
}
watch(files, (list) => {
    previews.value.forEach(preview => URL.revokeObjectURL(preview.url));
    previews.value = list.map(file => ({ name: file.name, url: URL.createObjectURL(file), video: file.type.startsWith('video/') }));
}, { deep: true });
onBeforeUnmount(() => previews.value.forEach(preview => URL.revokeObjectURL(preview.url)));

async function create() {
    if (busy.value) return;
    if (!content.value.trim() && !files.value.length) {
        error.value = 'Write something or add a photo or video.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const form = new FormData();
        form.append('content', content.value);
        form.append('privacy', privacy.value);
        files.value.forEach(file => form.append('media[]', file));
        await axios.post('/posts', form);
        router.visit(route('feed'));
    } catch (exception: any) {
        error.value = exception.response?.data?.message || 'Could not publish your post.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Create post" />
    <AuthenticatedLayout>
        <main class="min-h-screen bg-[#f0f2f5] px-4 py-6 sm:py-10">
            <form @submit.prevent="create" class="mx-auto w-full max-w-2xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70 sm:p-7">
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 pb-4">
                    <div>
                        <h1 class="text-xl font-bold text-slate-900">Create post</h1>
                        <p class="mt-1 text-sm text-slate-500">Share an update with your friends.</p>
                    </div>
                    <Link :href="route('feed')" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg text-slate-600 hover:bg-slate-200" aria-label="Cancel">✕</Link>
                </div>

                <div class="mt-5 flex items-center gap-3">
                    <span class="grid h-11 w-11 place-items-center overflow-hidden rounded-full bg-blue-600 font-bold text-white">
                        <img v-if="$page.props.auth.user.avatar_url" :src="$page.props.auth.user.avatar_url" :alt="$page.props.auth.user.name" class="h-full w-full object-cover">
                        <template v-else>{{ $page.props.auth.user.name?.[0] }}</template>
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ $page.props.auth.user.name }}</p>
                        <select v-model="privacy" class="mt-1 rounded-md border-slate-200 py-1 pl-2 pr-7 text-xs">
                            <option value="public">🌐 Public</option>
                            <option value="friends">👥 Friends</option>
                            <option value="only_me">🔒 Only me</option>
                        </select>
                    </div>
                </div>

                <textarea v-model="content" autofocus class="mt-5 w-full resize-y rounded-xl border-slate-200 text-base focus:border-indigo-300 focus:ring-indigo-300" rows="7" placeholder="What's on your mind?" />
                <label class="mt-4 block cursor-pointer rounded-xl border-2 border-dashed border-slate-200 p-6 text-center text-sm text-slate-500 transition hover:border-indigo-300 hover:bg-indigo-50/40">
                    📷 Add photos or video
                    <input @change="select" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple class="hidden">
                </label>
                <div v-if="previews.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div v-for="(preview, index) in previews" :key="preview.url" class="relative">
                        <video v-if="preview.video" :src="preview.url" class="aspect-square w-full rounded-lg bg-black object-cover" controls />
                        <img v-else :src="preview.url" class="aspect-square w-full rounded-lg object-cover" :alt="preview.name">
                        <button type="button" @click="files.splice(index, 1)" class="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full bg-slate-900/70 text-sm text-white hover:bg-slate-900" aria-label="Remove media">✕</button>
                    </div>
                </div>
                <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <Link :href="route('feed')" class="rounded-xl bg-slate-100 px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-slate-200">Cancel</Link>
                    <button :disabled="busy" class="rounded-xl bg-indigo-600 px-7 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">{{ busy ? 'Publishing…' : 'Publish post' }}</button>
                </div>
            </form>
        </main>
    </AuthenticatedLayout>
</template>
