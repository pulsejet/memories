<template>
    <Modal @close="close">
        <template #title>
            {{ t('memories', 'Remove person') }}
        </template>

        <span>{{ t('memories', 'Are you sure you want to remove {name}', { name }) }}</span>

        <template #buttons>
            <NcButton @click="save" class="button" type="error">
                {{ t('memories', 'Delete') }}
            </NcButton>
        </template>
    </Modal>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from 'vue-property-decorator';
import { NcButton, NcTextField } from '@nextcloud/vue';
import { showError } from '@nextcloud/dialogs'
import Modal from './Modal.vue';
import GlobalMixin from '../mixins/GlobalMixin';
import client from '../services/DavClient';

@Component({
    components: {
        NcButton,
        NcTextField,
        Modal,
    }
})
export default class FaceDeleteModal extends Mixins(GlobalMixin) {
    private user: string = "";
    private name: string = "";

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
			await client.deleteFile(`/recognize/${this.user}/faces/${this.name}`)
            this.$router.push({ name: 'people' });
            this.close();
		} catch (error) {
            console.log(error);
			showError(this.t('photos', 'Failed to delete {name}.', {
                name: this.name,
            }));
		}
    }

    public close() {
        this.$emit('close');
    }
}
</script>