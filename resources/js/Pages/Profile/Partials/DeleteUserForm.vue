<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value?.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => {
            form.reset();
        },
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-bold text-rose-900">Delete account</h2>
            <p class="mt-2 text-sm leading-6 text-rose-800">This permanently removes your account, conversations, and data. This cannot be undone.</p>
        </header>

        <button type="button" @click="confirmUserDeletion" class="mt-5 rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-rose-600">Delete account</button>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6 sm:p-7">
                <h2 class="text-xl font-bold text-stone-900">Delete your account?</h2>

                <p class="mt-2 text-sm leading-6 text-stone-500">This cannot be undone. Enter your password to permanently remove your account and its data.</p>

                <div class="mt-6">
                    <label for="password" class="text-sm font-medium text-stone-700">Password</label>
                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1.5 block w-full rounded-xl border-stone-200 bg-stone-50 px-3.5 py-2.5 text-sm text-stone-900 shadow-none focus:border-rose-600 focus:ring-2 focus:ring-rose-600/20"
                        placeholder="Password"
                        @keyup.enter="deleteUser"
                    >

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="button" @click="closeModal" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-stone-600 hover:bg-stone-100">Cancel</button>
                    <button type="button" :disabled="form.processing" @click="deleteUser" class="ml-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50">{{ form.processing ? 'Deleting…' : 'Delete account' }}</button>
                </div>
            </div>
        </Modal>
    </section>
</template>
