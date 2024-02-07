<template>
    <Modal ref="uploadmodal" @close="onClose" v-if="opened">
        <template #title>
            {{ t('memories', 'Upload') }}
        </template>
        <div class="subtitle" @click="showAlbums">
            {{ t('memories', 'Add to albums') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: albumsShown }">
            <AlbumPicker hideSaveBtn @toggleAlbum="toggleAlbum" :photos="photos" :disabled="!!opsTotal" />
        </div>
        <div class="subtitle" @click="showTags">
            {{ t('memories', 'Assign tags') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: tagsShown }">
            TAGS
        </div>
    </Modal>
</template>
  
<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumPicker from './AlbumPicker.vue';
import type { IAlbum, IPhoto } from '@typings';

export default defineComponent({
    name: 'UploadModal',
    props: {
        opened: {
            type: Boolean,
            required: true,
        }
    },

    emits: {
        onClose: () => true,
    },

    components: {
        Modal,
        AlbumPicker,
    },
    mixins: [
        ModalMixin,
    ],

    data: () => ({
        photos: [] as IPhoto[],
        opsTotal: 0,
        albumsShown: false,
        tagsShown: false,
    }),

    mounted() {
    },

    created() {
        console.assert(!_m.modals.updateAlbums, 'AddToAlbumModal created twice');
        _m.modals.updateAlbums = this.open;
    },

    computed: {
    },

    methods: {
        open(photos: IPhoto[]) {
            this.photos = photos;
            this.opsTotal = 0;
        },

        onClose() {
            this.$emit('onClose');
        },

        toggleAlbum(selectedAlbums: IAlbum[]) {
            console.log({ selectedAlbums });
        },

        showAlbums() {
            this.albumsShown = !this.albumsShown;
        },

        showTags() {
            this.tagsShown = !this.tagsShown;

        },
    },
});
</script>

<style lang="scss" scoped>
.subtitle {
    font-weight: 700;
    margin: 1rem 0 0.5rem;

    &::after {
        content: 'TOGGLE';
        cursor: pointer;
    }
}

.spoiler {
    overflow: hidden;
    height: 0;
    transition: height 0.3s ease-in-out;

    &.opened {
        height: auto;
    }
}
</style>
  