<template>
    <Modal ref="uploadmodal" @close="onClose" v-if="open">
        <template #title>
            {{ t('memories', 'Upload') }}
        </template>

    </Modal>
</template>
  
<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';


export default defineComponent({
    name: 'UploadModal',
    props: {
        open: {
            type: Boolean,
            required: true,
        }
    },

    emits: {
        onClose: () => true,
    },

    components: {
        Modal,
    },
    mixins: [
        ModalMixin,
    ],

    data: () => ({
    }),

    mounted() {
    },

    computed: {
    },

    methods: {
        onClose() {
            this.$emit('onClose');
        },
    },
});
</script>

<style lang="scss" scoped></style>
  