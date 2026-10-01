<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen bg-stone-50 text-stone-900">
        <header class="border-b border-stone-200 bg-white">
            <nav class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4" aria-label="Primary navigation">
                <Link href="/" class="text-xl font-black tracking-tight text-teal-800">PCHAT</Link>
                <div class="flex items-center gap-4 text-sm font-medium">
                    <Link href="/features" class="text-stone-600 hover:text-teal-800">Features</Link>
                    <Link href="/about" class="text-stone-600 hover:text-teal-800">About</Link>
                    <Link :href="route('login')" class="rounded-lg px-3 py-2 text-teal-800 hover:bg-teal-50">Login</Link>
                    <Link :href="route('register')" class="rounded-lg bg-teal-700 px-3 py-2 text-white hover:bg-teal-800">Create Account</Link>
                </div>
            </nav>
        </header>
        <main><slot /></main>
        <footer class="border-t border-stone-200 bg-white">
            <nav class="mx-auto flex max-w-6xl flex-wrap gap-5 px-5 py-6 text-sm text-stone-600" aria-label="Footer navigation">
                <Link href="/about">About</Link><Link href="/features">Features</Link><Link href="/privacy">Privacy</Link><Link href="/terms">Terms</Link>
            </nav>
        </footer>
    </div>
</template>
