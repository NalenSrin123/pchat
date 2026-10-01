<script setup lang="ts">
import SeoHead from '@/Components/SeoHead.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineProps<{ title: string; description: string; canonicalPath: string; heading: string; intro: string; sections: { title: string; body: string }[] }>();
</script>

<template>
    <SeoHead :title="title" :description="description" :canonical-path="canonicalPath" robots="index, follow" />
    <PublicLayout>
        <article class="mx-auto max-w-3xl px-5 py-16 sm:py-24">
            <h1 class="text-4xl font-black tracking-tight text-stone-900">{{ heading }}</h1>
            <p class="mt-5 text-lg leading-8 text-stone-600">{{ intro }}</p>
            <section v-for="section in sections" :key="section.title" class="mt-10">
                <h2 class="text-xl font-bold text-stone-900">{{ section.title }}</h2>
                <p class="mt-3 leading-7 text-stone-600">{{ section.body }}</p>
            </section>
        </article>
    </PublicLayout>
</template>
