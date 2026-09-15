<template>
    <!-- Корень один, как у RepostButton: кнопка и модальное окно — два узла. -->
    <div>
        <button
            type="button"
            class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800"
            @click="openModal"
        >
            Добавить участника
        </button>

        <Modal :show="isModalShown" max-width="md" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">Новый групповой чат</h2>

                <input
                    v-model="title"
                    type="text"
                    maxlength="255"
                    placeholder="Название чата"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!--
                    errors.title — строка, а не массив: Inertia отдаёт первое
                    сообщение по каждому полю.
                -->
                <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>

                <!--
                    Выбранные участники отдельным списком: результаты поиска меняются
                    при каждом вводе, а выбор должен оставаться на виду.
                    Клик по нику убирает человека из выбора.
                -->
                <ul v-if="selectedProfiles.length" class="mt-4 flex flex-wrap gap-2">
                    <li v-for="profile in selectedProfiles" :key="profile.id">
                        <button
                            type="button"
                            class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-800 hover:bg-sky-200"
                            :disabled="isSending"
                            @click="toggleProfile(profile)"
                        >
                            {{ profile.nickname }} ✕
                        </button>
                    </li>
                </ul>

                <input
                    v-model="search"
                    type="search"
                    placeholder="Поиск по нику"
                    class="mt-4 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                    :disabled="isSending"
                />

                <!--
                    «Загружаю…» только при пустом списке: если показывать его на каждый
                    запрос, список будет мигать при вводе каждой буквы.
                -->
                <p v-if="!profiles.length" class="mt-2 text-sm text-gray-500">
                    {{ isLoading ? 'Загружаю…' : 'Никого не нашлось.' }}
                </p>

                <!-- max-h + overflow: двадцать строк не растягивают окно за край экрана. -->
                <ul v-else class="mt-2 max-h-60 divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="profile in profiles" :key="profile.id">
                        <!-- label вокруг чекбокса: отметить можно кликом по нику. -->
                        <label class="flex cursor-pointer items-center gap-3 py-2 text-sm text-gray-700">
                            <!--
                                :checked + @change, а не v-model: выбор хранится
                                объектами профилей (см. selectedProfiles), и переключает
                                его один метод — и здесь, и в списке выбранных.
                            -->
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-sky-700 focus:ring-sky-500"
                                :checked="selectedIds.includes(profile.id)"
                                :disabled="isSending"
                                @change="toggleProfile(profile)"
                            />
                            {{ profile.nickname }}
                        </label>
                    </li>
                </ul>

                <p v-if="errors.members" class="mt-1 text-sm text-red-600">{{ errors.members }}</p>

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
                        :disabled="!canSubmit"
                        class="rounded-lg bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-800 disabled:opacity-50"
                        @click="submit"
                    >
                        {{ isSending ? 'Создаю…' : 'Создать чат' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script>
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

export default {
    name: 'CreateGroupChat',
    components: { Modal },
    data() {
        return {
            isModalShown: false,
            title: '',
            search: '',
            // Результат последнего поиска: меняется при каждом вводе.
            profiles: [],
            // Выбранные профили целиком (id и ник), а не одни id: выбранный человек
            // может пропасть из результатов нового поиска, а его ник всё равно
            // нужно показывать в списке выбранных.
            selectedProfiles: [],
            // Ошибки валидации из onError: { title: '...', members: '...' }.
            errors: {},
            isLoading: false,
            isSending: false,
            // Таймер отложенного поиска.
            searchTimer: null,
        };
    },
    computed: {
        // id выбранных — ровно то, что уйдёт на сервер в members.
        selectedIds() {
            return this.selectedProfiles.map((profile) => profile.id);
        },
        // Проверка на клиенте — удобство, а не правило: правила живут в StoreRequest.
        canSubmit() {
            return !this.isSending && this.title.trim() !== '' && this.selectedProfiles.length > 0;
        },
    },
    watch: {
        /**
         * Поиск с задержкой (debounce): запрос уходит, когда пользователь перестал
         * печатать на 300 мс. Каждый новый символ отменяет запланированный запрос
         * и планирует новый.
         */
        search() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.loadProfiles(), 300);
        },
    },
    /**
     * После создания чата Inertia уходит на его страницу, и компонент исчезает.
     * Запланированный поиск ему уже не нужен.
     */
    beforeUnmount() {
        clearTimeout(this.searchTimer);
    },
    methods: {
        openModal() {
            this.errors = {};
            this.isModalShown = true;
            // Список грузим при каждом открытии: с прошлого раза могли
            // появиться новые профили.
            this.loadProfiles();
        },
        /**
         * Пока запрос в полёте, окно не закрываем — как у RepostButton.
         */
        closeModal() {
            if (this.isSending) {
                return;
            }

            this.isModalShown = false;
        },
        /**
         * axios, а не Inertia: нужен только список для окна, страница
         * и адрес меняться не должны.
         */
        loadProfiles() {
            this.isLoading = true;

            axios
                // params axios сам превратит в ?search=...
                .get(route('client.profiles.index'), { params: { search: this.search } })
                .then((res) => {
                    this.profiles = res.data;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        /**
         * Отметить профиль или снять отметку.
         *
         * Сравнение по id, а не по объекту: новый поиск приносит новые объекты
         * тех же профилей, и includes(profile) их бы не узнал.
         */
        toggleProfile(profile) {
            if (this.selectedIds.includes(profile.id)) {
                this.selectedProfiles = this.selectedProfiles.filter(
                    (selected) => selected.id !== profile.id,
                );

                return;
            }

            this.selectedProfiles.push(profile);
        },
        /**
         * Создание чата через Inertia: сервер ответит редиректом, и Inertia
         * сама откроет страницу нового чата.
         *
         * router.post(), а не axios: после формы нужно перейти на другую
         * страницу. Это тот же <Link method="post"> из кнопки «Написать»,
         * только вызванный из кода и с данными формы.
         *
         * preserveState писать не нужно: у post() он по умолчанию true.
         * Поэтому после ошибки валидации компонент не пересоздаётся —
         * окно остаётся открытым, название и выбор на месте.
         */
        submit() {
            this.isSending = true;
            this.errors = {};

            router.post(
                route('client.chats.store'),
                {
                    title: this.title,
                    members: this.selectedIds,
                },
                {
                    // Валидация не прошла: окно осталось открытым, показываем ошибки.
                    onError: (errors) => {
                        this.errors = errors;
                    },
                    // И после ошибки, и после успеха.
                    onFinish: () => {
                        this.isSending = false;
                    },
                },
            );
        },
    },
};
</script>
