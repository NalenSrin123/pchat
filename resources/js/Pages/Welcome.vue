<script setup lang="ts">
import SeoHead from '@/Components/SeoHead.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const structuredData = computed(() => {
    const siteUrl = (page.props as { seo?: { siteUrl?: string | null } }).seo?.siteUrl;

    return {
        '@context': 'https://schema.org',
        '@type': 'WebApplication',
        name: 'PCHAT',
        applicationCategory: 'CommunicationApplication',
        operatingSystem: 'Web',
        ...(siteUrl ? { url: `${siteUrl}/` } : {}),
        description: 'A real-time messaging platform for private conversations, group chats, image sharing and voice messages.',
    };
});

const features = [
    ['Real-Time Chat', 'Send and receive messages instantly.'],
    ['Group Conversations', 'Create groups and communicate with multiple people.'],
    ['Voice Messages', 'Record and send voice messages.'],
    ['Image Sharing', 'Share images directly in conversations.'],
    ['Online Status', 'See when your contacts are active.'],
    ['Unread Messages', 'Never miss a conversation.'],
];
</script>

<template>
    <SeoHead title="PCHAT – Simple Real-Time Messaging" description="PCHAT is a real-time messaging platform for private conversations, group chats, image sharing and voice messages." canonical-path="/" robots="index, follow" :structured-data="structuredData" />
    <PublicLayout>
        <section class="bg-gradient-to-br from-teal-950 via-teal-800 to-teal-700 px-5 py-20 text-white sm:py-28">
            <div class="mx-auto max-w-6xl">
                <p class="text-sm font-bold uppercase tracking-[0.22em] text-teal-200">PCHAT</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-black tracking-tight sm:text-6xl">Real-Time Messaging Made Simple</h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-teal-50">Chat privately, create groups, share images and send voice messages with PCHAT.</p>
                <div class="mt-9 flex flex-wrap gap-3"><Link :href="route('register')" class="rounded-xl bg-white px-5 py-3 font-bold text-teal-900 hover:bg-teal-50">Create Account</Link><Link :href="route('login')" class="rounded-xl border border-teal-200 px-5 py-3 font-bold text-white hover:bg-white/10">Login</Link></div>
            </div>
        </section>
        <section class="mx-auto max-w-6xl px-5 py-16 sm:py-24" aria-labelledby="features-heading">
            <h2 id="features-heading" class="text-3xl font-black tracking-tight">Built for everyday conversations</h2>
            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><article v-for="feature in features" :key="feature[0]" class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm"><h3 class="text-lg font-bold">{{ feature[0] }}</h3><p class="mt-2 leading-7 text-stone-600">{{ feature[1] }}</p></article></div>
        </section>
    </PublicLayout>
</template>
