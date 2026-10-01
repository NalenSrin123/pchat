<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    title?: string;
    description?: string;
    canonicalPath?: string;
    robots?: string;
    type?: 'website';
    structuredData?: Record<string, unknown>;
}>(), {
    robots: 'noindex, nofollow',
    type: 'website',
});

const page = usePage();
const seo = computed(() => (page.props as { seo?: { siteUrl?: string | null; socialImage?: string } }).seo);
const canonical = computed(() => {
    if (!seo.value?.siteUrl || !props.canonicalPath) return null;

    return `${seo.value.siteUrl}${props.canonicalPath === '/' ? '/' : props.canonicalPath}`;
});
const image = computed(() => seo.value?.siteUrl && seo.value.socialImage
    ? `${seo.value.siteUrl}${seo.value.socialImage}`
    : null);
const jsonLd = computed(() => props.structuredData ? JSON.stringify(props.structuredData) : null);
</script>

<template>
    <Head :title="title">
        <meta head-key="robots" name="robots" :content="robots" />
        <meta v-if="description" head-key="description" name="description" :content="description" />
        <link v-if="canonical" head-key="canonical" rel="canonical" :href="canonical" />
        <meta v-if="title" head-key="og:title" property="og:title" :content="title" />
        <meta v-if="description" head-key="og:description" property="og:description" :content="description" />
        <meta v-if="image" head-key="og:image" property="og:image" :content="image" />
        <meta v-if="canonical" head-key="og:url" property="og:url" :content="canonical" />
        <meta v-if="canonical" head-key="og:type" property="og:type" :content="type" />
        <meta v-if="title" head-key="og:site_name" property="og:site_name" content="PCHAT" />
        <meta v-if="image" head-key="twitter:card" name="twitter:card" content="summary_large_image" />
        <meta v-if="title" head-key="twitter:title" name="twitter:title" :content="title" />
        <meta v-if="description" head-key="twitter:description" name="twitter:description" :content="description" />
        <meta v-if="image" head-key="twitter:image" name="twitter:image" :content="image" />
        <component :is="'script'" v-if="jsonLd" head-key="json-ld" type="application/ld+json" v-html="jsonLd" />
    </Head>
</template>
