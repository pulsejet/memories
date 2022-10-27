<template>
    <Modal @close="close" size="normal" v-if="show">
        <template #title>
            {{ t('memories', 'Add to album') }}
        </template>

        <div class="outer">
            <AlbumPicker @select="selectAlbum" />

            <div v-if="processing" class="info-pad">
                {{ t('memories', 'Processing … {n}/{m}', {
                    n: photosDone,
                    m: photos.length,
                }) }}
            </div>
        </div>
    </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins } from 'vue-property-decorator';
import GlobalMixin from '../../mixins/GlobalMixin';

import * as dav from '../../services/DavRequests';
import { showInfo } from '@nextcloud/dialogs';
import { IAlbum, IPhoto } from '../../types';

import AlbumPicker from './AlbumPicker.vue';
import Modal from './Modal.vue';

@Component({
    components: {
        Modal,
        AlbumPicker,
    }
})
export default class AddToAlbumModal extends Mixins(GlobalMixin) {
    private show = false;
    private photos: IPhoto[] = [];
    private photosDone: number = 0;
    private processing: boolean = false;

    public open(photos: IPhoto[]) {
        this.photosDone = 0;
        this.processing = false;
        this.show = true;
        this.photos = photos;
    }

    @Emit('added')
    public added(photos: IPhoto[]) {}

    @Emit('close')
    public close() {
        this.photos = [];
        this.processing = false;
        this.show = false;
    }

    public async selectAlbum(album: IAlbum) {
        const name = album.name || album.album_id.toString();
        const gen = dav.addToAlbum(album.user, name, this.photos.map(p => p.fileid));
        this.processing = true;

        for await (const fids of gen) {
            this.photosDone += fids.filter(f => f).length;
            this.added(this.photos.filter(p => fids.includes(p.fileid)));
        }

        showInfo(this.t('memories', '{n} photos added to album', { n: this.photosDone }));
        this.close();
    }
}
</script>

<style lang="scss" scoped>
.outer {
    margin-top: 15px;
}

.info-pad {
    margin-top: 6px;
}
</style>