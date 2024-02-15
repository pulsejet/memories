<template>
    <Modal ref="uploadmodal" @close="onClose" v-if="opened">
        <template #title>
            {{ t('memories', 'Upload') }}
        </template>
        <div class="photos">
            <VueUploadComponent accept="image/*" :multiple="true" ref="upload" v-model="dummy" @input-file="select"
                @input-filter="filter">
                <NcButton @click="select" class="button" :disabled="processing">
                    {{ t('memories', 'Select photos') }}
                </NcButton>
            </VueUploadComponent>
            <div class="upload-path">
                Photos will be uploaded to <strong>{{ currentRouteName }}</strong>
            </div>
            <div class="upload-note">
                Navigate to desired folder to upload files there
            </div>
            <div class="previews">
                <div v-for="photo in photos">
                    <div class="preview">
                        <img :src=photo.preview />
                        <div class="deleteIcon" @click="removePhoto" :data-id=photo.id>
                            <Delete />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="subtitle" @click="showAlbums">
            <div class="icon">
                <ChevronDown :size="20" v-if="!albumsShown" />
                <ChevronUp :size="20" v-if="albumsShown" />
            </div>
            {{ t('memories', 'Add to albums') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: albumsShown }">
            <AlbumPicker hideSaveBtn @toggleAlbum="toggleAlbum" :photos="dummy" :disabled="processing" />
        </div>
        <div class="subtitle" @click="showTags">
            <div class="icon">
                <ChevronDown :size="20" v-if="!tagsShown" />
                <ChevronUp :size="20" v-if="tagsShown" />
            </div>
            {{ t('memories', 'Assign tags') }}
        </div>
        <div v-bind:class="{ spoiler: true, opened: tagsShown }">
            <EditTags ref="tags" :photos="dummy" :disabled="processing" />
        </div>
        <div class="actions">
            <div class="progress-bar" v-if="progress > 0">
                <NcProgressBar :value="progress" :error="true" />
            </div>
            <NcButton @click="upload" class="button" type="error" v-if="photos" :disabled="processing || !photos.length">
                {{ t('memories', 'Upload') }}
            </NcButton>
        </div>
    </Modal>
</template>
  
<script lang="ts">
import { defineComponent } from 'vue';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumPicker from './AlbumPicker.vue';
import EditTags from './EditTags.vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue';
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import VueUploadComponent from 'vue-upload-component';
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar');

import type { IAlbum, IPhoto } from '@typings';

interface Photo extends VUFile {
    preview: string;
}

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
        Delete,
        NcButton,
        VueUploadComponent,
        NcProgressBar,
    },
    mixins: [
        ModalMixin,
    ],

    data: () => ({
        photos: [] as Photo[],
        dummy: [] as IPhoto[],
        previews: [],
        progress: 0,
        albumsShown: false,
        tagsShown: false,
        selectedAlbums: [] as IAlbum[],
        processings: {} as { [key: string | number]: Promise<void> },
        processing: false,
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
        currentRouteName() {
            const route = this.$route.name;
            return route?.startsWith('folders') ? '/' + route.split('/').slice(1).join('/') : '/';
        }
    },

    methods: {
        open() {
        },

        onClose() {
            this.photos = [];
            this.processings = {};
            this.progress = 0;
            this.previews = [];
            this.albumsShown = false;
            this.tagsShown = false;
            this.processing = false;
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

        select(newFile: VUFile) {
            let reader = new FileReader();
            if (newFile) {
                reader.readAsDataURL(newFile.file);
                this.processings[newFile.id] = new Promise((resolve, reject) => {
                    reader.onload = (e) => {
                        this.photos.push({
                            ...newFile,
                            preview: e.target?.result?.toString() ?? "",
                        });
                        resolve();
                        delete this.processings[newFile.id];
                        this.processing = Object.keys(this.processings).length > 0;
                    };
                    reader.onerror = () => {
                        reject();
                        delete this.processings[newFile.id];
                        this.processing = Object.keys(this.processings).length > 0;
                    };
                });
            }
        },

        removePhoto(e: any) {
            // @ts-ignore
            const id = e.currentTarget?.getAttribute('data-id');
            this.photos = this.photos.filter((photo) => photo.id != id);
        },

        filter() {
        },

        async upload() {
            this.progress = 0;
            this.processing = true;
            // Tags may be created which might throw
            let tagsResult: { add: number[]; remove: number[] } | null = null;
            try {
                tagsResult = (await this.refs.tags?.result?.()) ?? null;

                console.log({ tagsResult, albums: this.selectedAlbums, photos: this.photos });
            } catch (e) {
            } finally {
                this.progress = 0;
                this.processing = false;
            }
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
    flex-direction: column;
    align-items: flex-end;
}

.progress-bar {
    width: 100%;
}

.previews {
    display: flex;
    flex-wrap: wrap;
}

.preview {
    position: relative;
    margin: 5px;
    width: 50px;
    height: 50px;

    img {
        width: 100%;
        height: 100%;
    }
}

.deleteIcon {
    top: calc(50% - 20px);
    position: absolute;
    left: calc(50% - 15px);
    background: #ffffff99;
    border-radius: 50%;
    padding: 5px;

    &,
    & svg {
        cursor: pointer;
    }
}

.upload-note {
    color: #00000088;
    font-style: italic;
    font-size: small;
}
</style>
  