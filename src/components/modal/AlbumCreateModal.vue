<template>
    <Modal @close="close" size="normal" v-if="show">
        <template #title>
            {{ t('memories', 'Create new album') }}
        </template>

        <div class="outer">
            <AlbumForm
                :display-back-button="false"
                :title="t('photos', 'New album')"
                @done="done" />
        </div>
    </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins } from 'vue-property-decorator';
import AlbumForm from './AlbumForm.vue';

import Modal from './Modal.vue';
import GlobalMixin from '../../mixins/GlobalMixin';

@Component({
    components: {
        Modal,
        AlbumForm,
    }
})
export default class AlbumCreateModal extends Mixins(GlobalMixin) {
    private show = false;

    public open() {
        this.show = true;
    }

    @Emit('close')
    public close() {
        this.show = false;
    }

    public done({ album }: any) {
        const user = album.filename.split('/')[2];
        const name = album.basename;
        this.$router.push({ name: 'albums', params: { user, name } });
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