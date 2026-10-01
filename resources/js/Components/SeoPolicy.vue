<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Fail closed: only the explicitly public Inertia pages are crawlable.
const page = usePage();
const isPublic = computed(() => ['Welcome', 'Public/Static'].includes(page.component));
</script>

<template>
    <Head v-if="!isPublic">
        <meta head-key="robots" name="robots" content="noindex, nofollow" />
    </Head>
</template>
