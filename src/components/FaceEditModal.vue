<template>
    <NcModal
        size="small"
        @close="close"
        :outTransition="true"
        :hasNext="false"
        :hasPrevious="false">

        <div class="container">
            <div class="head">
                <span>{{ t('memories', 'Rename person') }}</span>
            </div>

            <div class="fields memories__editdate__fields">
                <NcTextField :value.sync="name"
                    class="field"
                    :label="t('memories', 'Name')" :label-visible="true"
                    :placeholder="t('memories', 'Name')"
                    @keypress.enter="save()" />
            </div>

            <div class="buttons">
                <NcButton @click="save" class="button" type="error">
                    {{ t('memories', 'Update') }}
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
export default class EditDate extends Mixins(GlobalMixin) {
    private user: string = "";
    private name: string = "";
    private oldName: string = "";

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
        this.oldName = this.$route.params.name || '';
    }

    public async save() {
		try {
			await client.moveFile(
				`/recognize/${this.user}/faces/${this.oldName}`,
				`/recognize/${this.user}/faces/${this.name}`,
			);
            this.$router.push({ name: 'people', params: { user: this.user, name: this.name } });
            this.close();
		} catch (error) {
            console.log(error);
			showError(this.t('photos', 'Failed to rename {oldName} to {name}.', {
                oldName: this.oldName,
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