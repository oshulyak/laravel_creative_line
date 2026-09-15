<template>
    <!-- Корень один, как у CreateGroupChat: кнопка и модальное окно — два узла. -->
    <div>
        <button
            type="button"
            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800"
            @click="openModal"
        >
            Создать группу
        </button>

        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Новая группа</h2>

                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название группы"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!-- У Inertia ошибка по полю — строка, а не массив. -->
                <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>

                <textarea
                    v-model="description"
                    rows="3"
                    maxlength="2000"
                    placeholder="Описание (необязательно)"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100"
                        @click="closeModal"
                    >
                        Отмена
                    </button>

                    <button
                        type="button"
                        :disabled="isSending || !title.trim()"
                        class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                        @click="submit"
                    >
                        {{ isSending ? 'Создаю…' : 'Создать' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'CreateGroup',
    components: { Modal },
    data() {
        return {
            isModalShown: false,
            title: '',
            description: '',
            // Ошибки валидации из onError: { title: '...' }.
            errors: {},
            isSending: false,
        };
    },
    methods: {
        openModal() {
            this.errors = {};
            this.isModalShown = true;
        },
        /**
         * Пока запрос в полёте, окно не закрываем — как у CreateGroupChat.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        /**
         * router.post(), а не axios: после создания нужно оказаться на странице
         * новой группы. Сервер ответит редиректом, Inertia откроет её сама.
         * При ошибке валидации окно останется открытым: у post() preserveState
         * по умолчанию true.
         */
        submit() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.groups.store'),
                {
                    title: this.title,
                    description: this.description,
                },
                {
                    onError: (errors) => {
                        this.errors = errors;
                    },
                    onFinish: () => {
                        this.isSending = false;
                    },
                },
            );
        },
    },
};
</script>
