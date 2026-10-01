<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-stone-900">Password & security</h2>
            <p class="mt-1 text-sm text-stone-500">Choose a strong password you do not use elsewhere.</p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-6">
            <div>
                <label for="current_password" class="text-sm font-medium text-stone-700">Current password</label>
                <input
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    autocomplete="current-password"
                >

                <InputError
                    :message="form.errors.current_password"
                    class="mt-2"
                />
            </div>

            <div>
                <label for="password" class="text-sm font-medium text-stone-700">New password</label>
                <input
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    autocomplete="new-password"
                >

                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <div>
                <label for="password_confirmation" class="text-sm font-medium text-stone-700">Confirm new password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                    autocomplete="new-password"
                >

                <InputError
                    :message="form.errors.password_confirmation"
                    class="mt-2"
                />
            </div>

            <div class="flex items-center gap-4">
                <button type="submit" :disabled="form.processing" class="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? 'Updating…' : 'Update password' }}</button>

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
