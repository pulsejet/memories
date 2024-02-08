<template>
    <Modal ref="uploadmodal" @close="onClose" v-if="opened">
        <template #title>
            {{ t('memories', 'Upload') }}
        </template>
        <div class="photos">
            <NcButton @click="select" class="button" :disabled="processing">
                {{ t('memories', 'Select photos') }}
            </NcButton>
        </div>
        <div class="subtitle" @click="showAlbums">
            <div class="icon">
                <ChevronDown :size="20" v-if="!albumsShown" />
                <ChevronUp :size="20" v-if="albumsShown" />
            </div>
            {{ t('memories', 'Add to albums') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: albumsShown }">
            <AlbumPicker hideSaveBtn @toggleAlbum="toggleAlbum" :photos="photos" :disabled="processing" />
        </div>
        <div class="subtitle" @click="showTags">
            <div class="icon">
                <ChevronDown :size="20" v-if="!tagsShown" />
                <ChevronUp :size="20" v-if="tagsShown" />
            </div>
            {{ t('memories', 'Assign tags') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: tagsShown }">
            <EditTags ref="tags" :photos="photos" :disabled="processing" />
        </div>
        <div class="actions">
            <NcButton @click="upload" class="button" type="error" v-if="photos" :disabled="processing">
                {{ t('memories', 'Upload') }}
            </NcButton>
        </div>
    </Modal>
</template>
  
<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumPicker from './AlbumPicker.vue';
import EditTags from './EditTags.vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue';

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
        EditTags,
        ChevronDown,
        ChevronUp,
        NcButton,
    },
    mixins: [
        ModalMixin,
    ],

    data: () => ({
        photos: [] as IPhoto[],
        opsTotal: 0,
        albumsShown: true,
        tagsShown: true,
        processing: false,
        selectedAlbums: [] as IAlbum[],
    }),

    mounted() {
    },

    created() {
        console.assert(!_m.modals.updateAlbums, 'AddToAlbumModal created twice');
        _m.modals.updateAlbums = this.open;
    },

    computed: {
        refs() {
            return this.$refs as {
                tags?: InstanceType<typeof EditTags>;
            };
        },
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
            this.selectedAlbums = selectedAlbums;
        },

        showAlbums() {
            this.albumsShown = !this.albumsShown;
        },

        showTags() {
            this.tagsShown = !this.tagsShown;
        },

        select() {

        },

        async upload() {
            // Tags may be created which might throw
            let tagsResult: { add: number[]; remove: number[] } | null = null;
            try {
                tagsResult = (await this.refs.tags?.result?.()) ?? null;
            } catch (e) {
            }

            console.log({ tagsResult, albums: this.selectedAlbums });
        },
    }
});
</script>

<style lang="scss" scoped>
.subtitle {
    font-weight: 700;
    margin: 1rem 0 0.5rem;
    cursor: pointer;
    display: flex;
}

.spoiler {
    overflow: hidden;
    height: 0;
    transition: height 0.3s ease-in-out;

    &.opened {
        height: auto;
    }
}

.actions {
    display: flex;
    justify-content: flex-end;
    padding: 0.5rem 0 0;
}
</style>
  