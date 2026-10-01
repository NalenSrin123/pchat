<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    mustVerifyEmail?: Boolean;
    status?: String;
}>();

const user = usePage().props.auth.user;

const avatarPreview = ref<string | null>(user.avatar_url ?? null);
const avatarInput = ref<HTMLInputElement | null>(null);
const coverInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    name: user.name,
    username: user.username,
    email: user.email,
    avatar: null as File | null,
    cover_photo: null as File | null,
    _method: "patch",
});

const chooseAvatar = () => avatarInput.value?.click();
const setAvatar = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    form.avatar = file;
    avatarPreview.value = URL.createObjectURL(file);
};
const setCover = (event: Event) => { const file = (event.target as HTMLInputElement).files?.[0]; if (file) form.cover_photo = file; };
const submit = () => form.post(route("profile.update"), { forceFormData: true });
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-stone-900">Profile information</h2>
            <p class="mt-1 text-sm text-stone-500">This is how you appear to people in Pulse.</p>
        </header>

        <form
            @submit.prevent="submit"
            class="mt-6 space-y-6"
        >
            <div class="flex items-center gap-4">
                <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-full bg-teal-100 text-xl font-semibold text-teal-700">
                    <img v-if="avatarPreview" :src="avatarPreview" alt="Profile photo preview" class="h-full w-full object-cover" />
                    <span v-else>{{ user.name?.slice(0, 1).toUpperCase() }}</span>
                </div>
                <div>
                    <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="setAvatar" />
                    <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600" @click="chooseAvatar">Choose profile photo</button>
                    <p class="mt-1.5 text-xs text-stone-500">JPG, PNG, or WebP. Maximum 5 MB.</p>
                    <InputError class="mt-2" :message="form.errors.avatar" />
                </div>
            </div>
            <div>
                <input ref="coverInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="setCover" />
                <button type="button" class="rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50" @click="coverInput?.click()">Choose cover photo</button>
                <p class="mt-1.5 text-xs text-stone-500">JPG, PNG, or WebP. Maximum 10 MB.</p>
                <InputError class="mt-2" :message="form.errors.cover_photo" />
            </div>

            <div>
                <label for="name" class="text-sm font-medium text-stone-700">Name</label>
                <input id="name" type="text" class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                >

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <label for="username" class="text-sm font-medium text-stone-700">Username</label>
                <input id="username" type="text" class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    v-model="form.username"
                    required
                    autocomplete="username"
                >

                <InputError class="mt-2" :message="form.errors.username" />
            </div>

            <div>
                <label for="email" class="text-sm font-medium text-stone-700">Email address</label>
                <input id="email" type="email" class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none placeholder:text-stone-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    v-model="form.email"
                    required
                    autocomplete="username"
                >

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="rounded-xl bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                    Your email address is not verified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="font-semibold underline underline-offset-2 hover:text-amber-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-600"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-emerald-700"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Save changes' }}</button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm font-medium text-teal-700"
                    >
                        Saved.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
