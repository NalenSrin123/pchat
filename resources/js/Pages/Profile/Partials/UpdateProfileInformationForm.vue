<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

defineProps<{
    mustVerifyEmail?: Boolean;
    status?: String;
}>();

const user = usePage().props.auth.user as any;

const originalAvatar = user.avatar_url ?? null;
const originalCover = user.cover_photo_url ?? null;

const avatarPreview = ref<string | null>(originalAvatar);
const coverPreview = ref<string | null>(originalCover);
const avatarInput = ref<HTMLInputElement | null>(null);
const coverInput = ref<HTMLInputElement | null>(null);
const clientErrors = ref<{ avatar?: string; cover_photo?: string }>({});

const form = useForm({
    name: user.name,
    username: user.username,
    email: user.email,
    avatar: null as File | null,
    cover_photo: null as File | null,
    _method: 'patch',
});

const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_SIZE = { avatar: 5 * 1024 * 1024, cover_photo: 10 * 1024 * 1024 };

const revoke = (url: string | null) => {
    if (url?.startsWith('blob:')) URL.revokeObjectURL(url);
};

function pickFile(event: Event, kind: 'avatar' | 'cover_photo') {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    if (!ALLOWED_TYPES.includes(file.type)) {
        clientErrors.value[kind] = 'Please choose a JPG, PNG, or WebP image.';
        input.value = '';
        return;
    }
    if (file.size > MAX_SIZE[kind]) {
        clientErrors.value[kind] = `Image is too large. Maximum ${kind === 'avatar' ? '5' : '10'} MB.`;
        input.value = '';
        return;
    }

    clientErrors.value[kind] = undefined;
    form[kind] = file;

    if (kind === 'avatar') {
        revoke(avatarPreview.value);
        avatarPreview.value = URL.createObjectURL(file);
    } else {
        revoke(coverPreview.value);
        coverPreview.value = URL.createObjectURL(file);
    }
}

function discard() {
    revoke(avatarPreview.value);
    revoke(coverPreview.value);
    avatarPreview.value = originalAvatar;
    coverPreview.value = originalCover;
    clientErrors.value = {};
    if (avatarInput.value) avatarInput.value.value = '';
    if (coverInput.value) coverInput.value.value = '';
    form.reset();
    form.clearErrors();
}

const submit = () => form.post(route('profile.update'), { forceFormData: true });

onBeforeUnmount(() => {
    revoke(avatarPreview.value);
    revoke(coverPreview.value);
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-slate-900">Profile information</h2>
            <p class="mt-1 text-sm text-slate-500">This is how you appear to people on PCHAT.</p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-8">
            <!-- Cover + avatar -->
            <div>
                <div class="relative h-32 overflow-hidden rounded-2xl bg-gradient-to-br from-blue-500 via-indigo-500 to-purple-500 sm:h-44">
                    <img v-if="coverPreview" :src="coverPreview" alt="Cover photo preview" class="h-full w-full object-cover" />
                    <button
                        type="button"
                        class="absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded-lg bg-white/90 px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm backdrop-blur transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        @click="coverInput?.click()"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                        {{ coverPreview ? 'Change cover' : 'Add cover' }}
                    </button>
                    <input ref="coverInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickFile($event, 'cover_photo')" />
                </div>
                <InputError class="mt-2" :message="clientErrors.cover_photo || form.errors.cover_photo" />
                <p v-if="!clientErrors.cover_photo && !form.errors.cover_photo" class="mt-1.5 text-xs text-slate-500">Cover: JPG, PNG, or WebP, up to 10 MB.</p>

                <div class="-mt-12 flex items-end gap-4 px-4 sm:-mt-14 sm:px-6">
                    <div class="relative shrink-0">
                        <div class="grid h-24 w-24 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-3xl font-bold text-white shadow-md ring-4 ring-white sm:h-28 sm:w-28">
                            <img v-if="avatarPreview" :src="avatarPreview" alt="Profile photo preview" class="h-full w-full object-cover" />
                            <span v-else>{{ user.name?.slice(0, 1).toUpperCase() }}</span>
                        </div>
                        <button
                            type="button"
                            class="absolute bottom-0 right-0 grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-slate-700 shadow ring-2 ring-white transition hover:bg-slate-200 focus:outline-none focus-visible:ring-blue-500"
                            aria-label="Choose profile photo"
                            @click="avatarInput?.click()"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" /><circle cx="12" cy="13" r="4" /></svg>
                        </button>
                        <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickFile($event, 'avatar')" />
                    </div>
                    <div class="min-w-0 pb-1">
                        <p class="truncate text-lg font-bold text-slate-900">{{ form.name || user.name }}</p>
                        <p class="truncate text-sm text-slate-500">@{{ form.username || user.username }}</p>
                    </div>
                </div>
                <InputError class="mt-3 px-4 sm:px-6" :message="clientErrors.avatar || form.errors.avatar" />
                <p v-if="!clientErrors.avatar && !form.errors.avatar" class="mt-2 px-4 text-xs text-slate-500 sm:px-6">Profile photo: JPG, PNG, or WebP, up to 5 MB.</p>
            </div>

            <!-- Fields -->
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-semibold text-slate-700">Name</label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        class="mt-1.5 block w-full rounded-xl border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 shadow-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20"
                        :class="form.errors.name ? 'border-red-300 focus:border-red-500 focus:ring-red-500/20' : ''"
                    />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <label for="username" class="block text-sm font-semibold text-slate-700">Username</label>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-slate-400">@</span>
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            required
                            autocomplete="username"
                            class="block w-full rounded-xl border-slate-200 bg-slate-50 py-2.5 pl-8 pr-3.5 text-sm text-slate-900 shadow-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20"
                            :class="form.errors.username ? 'border-red-300 focus:border-red-500 focus:ring-red-500/20' : ''"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.username" />
                </div>

                <div class="sm:col-span-2">
                    <label for="email" class="block text-sm font-semibold text-slate-700">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        class="mt-1.5 block w-full rounded-xl border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm text-slate-900 shadow-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20"
                        :class="form.errors.email ? 'border-red-300 focus:border-red-500 focus:ring-red-500/20' : ''"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>
            </div>

            <!-- Email verification notice -->
            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.26 3.1c.77-1.33 2.69-1.33 3.46 0l6.1 10.5c.77 1.33-.19 3-1.73 3H3.9c-1.54 0-2.5-1.67-1.73-3l6.1-10.5ZM10 7a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 7Zm0 7.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" /></svg>
                    <div class="text-sm text-amber-900">
                        <p class="font-semibold">Your email address is not verified.</p>
                        <Link
                            :href="route('verification.send')"
                            method="post"
                            as="button"
                            class="mt-1 font-semibold underline underline-offset-2 hover:text-amber-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-600"
                        >
                            Re-send the verification email
                        </Link>
                        <p v-show="status === 'verification-link-sent'" class="mt-2 font-medium text-emerald-700">
                            A new verification link has been sent to your email address.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                <button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </button>

                <button
                    v-if="form.isDirty && !form.processing"
                    type="button"
                    class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                    @click="discard"
                >
                    Discard
                </button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                        Saved
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>