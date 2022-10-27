<template>
    <Modal @close="close" size="normal" v-if="show">
        <template #title>
            {{ t('memories', 'Add to album') }}
        </template>

        <div class="outer">
            <AlbumPicker @select="selectAlbum" />
        </div>
    </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop } from 'vue-property-decorator';
import { showError } from '@nextcloud/dialogs';
import { IAlbum, IPhoto, ITag } from '../../types';
import AlbumPicker from './AlbumPicker.vue';

import Modal from './Modal.vue';
import GlobalMixin from '../../mixins/GlobalMixin';
import client from '../../services/DavClient';
import * as dav from '../../services/DavRequests';

@Component({
    components: {
        Modal,
        AlbumPicker,
    }
})
export default class AddToAlbumModal extends Mixins(GlobalMixin) {
    private show = false;
    private photos: IPhoto[] = [];

    @Prop()
    private updateLoading: (delta: number) => void;

    public open(photos: IPhoto[]) {
        if (this.photos.length) {
            // is processing
            return;
        }

        this.show = true;
        this.photos = photos;
    }

    @Emit('close')
    public close() {
        this.photos = [];
        this.show = false;
    }

    public async selectAlbum(album: IAlbum) {
        console.log('selectAlbum', album);
    }
}
</script>

<style lang="scss" scoped>
.outer {
    margin-top: 15px;
}
</style>