<template>
    <Modal @close="close" v-if="show">
        <template #title>
            {{ t('memories', 'Remove Album') }}
        </template>

        <span>
            {{ t('memories', 'Are you sure you want to permanently remove album "{name}"?', { name }) }}
        </span>

        <template #buttons>
            <NcButton @click="save" class="button" type="error">
                {{ t('memories', 'Delete') }}
            </NcButton>
        </template>
    </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Watch } from 'vue-property-decorator';
import { NcButton, NcTextField } from '@nextcloud/vue';
import { showError } from '@nextcloud/dialogs';
import { getCurrentUser } from '@nextcloud/auth';
import Modal from './Modal.vue';
import GlobalMixin from '../../mixins/GlobalMixin';
import client from '../../services/DavClient';

@Component({
    components: {
        NcButton,
        NcTextField,
        Modal,
    }
})
export default class AlbumDeleteModal extends Mixins(GlobalMixin) {
    private user: string = "";
    private name: string = "";
    private show = false;

    @Emit('close')
    public close() {
        this.show = false;
    }

    public open() {
        const user = this.$route.params.user || '';
        if (this.$route.params.user !== getCurrentUser().uid) {
            showError(this.t('memories', 'Only user "{user}" can delete this album', { user }));
            return;
        }
        this.show = true;
    }

    @Watch('$route')
    async routeChange(from: any, to: any) {
        this.refreshParams();
    }

    mounted() {
        this.refreshParams();
    }

    public refreshParams() {
        this.user = this.$route.params.user || '';
        this.name = this.$route.params.name || '';
    }

    public async save() {
		try {
			await client.deleteFile(`/photos/${this.user}/albums/${this.name}`)
            this.$router.push({ name: 'albums' });
            this.close();
		} catch (error) {
            console.log(error);
			showError(this.t('photos', 'Failed to delete {name}.', {
                name: this.name,
            }));
		}
    }
}
</script>