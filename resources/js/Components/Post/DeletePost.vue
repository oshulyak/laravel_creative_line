<template>
    <button
        type="button"
        :disabled="isDeleting"
        class="text-xs font-medium text-gray-400 hover:text-red-600 disabled:opacity-50"
        @click="destroyPost"
    >
        Удалить
    </button>
</template>

<script>
import axios from 'axios';

export default {
    name: 'DeletePost',
    props: {
        post: {
            type: Object,
            required: true,
        },
    },
    // Публичный контракт компонента наружу: «я умею сообщать о событии deleted».
    //
    // Объявлять не обязательно — $emit сработает и без этого списка, — но нужно
    // по двум причинам. Во-первых, это документация: по props и emits видно,
    // как компонентом пользоваться, не читая его код. Во-вторых, объявленные
    // события Vue исключает из fallthrough-атрибутов; без emits слушатель
    // onDeleted осел бы ещё и на корневом <button> как нативный обработчик
    // события deleted — оно никогда не произойдёт, но мусор в разметке останется.
    emits: ['deleted'],
    data() {
        return {
            isDeleting: false,
        };
    },
    methods: {
        destroyPost() {
            // Удаление необратимо, поэтому спрашиваем. confirm() — заглушка,
            // та же, что в админке: блокирует вкладку и выглядит по-разному
            // в разных браузерах, но честнее самодельной модалки, написанной с нуля.
            if (! confirm(`Удалить пост «${this.post.title}»?`)) {
                return;
            }

            this.isDeleting = true;

            // axios.delete(), а не post(): route() отдаёт только URL, про HTTP-метод
            // Ziggy ничего не знает — перепутать значит получить 405.
            axios
                .delete(route('client.posts.destroy', this.post.id))
                // Событие поднимаем ТОЛЬКО после успешного ответа: 403 или 500
                // означают, что пост на месте, и перезапрашивать список незачем.
                //
                // Второй аргумент $emit — полезная нагрузка события. У родителя
                // она приедет в $event или первым аргументом обработчика.
                .then(() => this.$emit('deleted', this.post.id))
                .catch((e) => {
                    console.log(e.response?.data);
                })
                .finally(() => {
                    this.isDeleting = false;
                });
        },
    },
};
</script>
