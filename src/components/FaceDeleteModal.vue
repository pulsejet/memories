<template>
    <NcModal
        size="small"
        @close="close"
        :outTransition="true">

        <div class="container">
            <div class="head">
                <span>{{ t('memories', 'Remove person') }}</span>
            </div>

            <span>{{ t('memories', 'Are you sure you want to remove {name}', { name }) }}</span>

            <div class="buttons">
                <NcButton @click="save" class="button" type="error">
                    {{ t('memories', 'Delete') }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from 'vue-property-decorator';
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue';
import { showError } from '@nextcloud/dialogs'
import GlobalMixin from '../mixins/GlobalMixin';
import client from '../services/DavClient';

@Component({
    components: {
        NcButton,
        NcModal,
        NcTextField,
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

<style scoped lang="scss">
.container {
	margin: 20px;

    .head {
        font-weight: 500;
    }
}

.buttons {
    margin-top: 10px;
    text-align: right;

    button {
        display: inline-block;
    }
}
</style>