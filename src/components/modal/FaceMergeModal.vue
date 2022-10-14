<template>
    <Modal @close="close" size="large">
        <template #title>
            {{ t('memories', 'Merge {name} with person', { name }) }}
        </template>

        <div class="outer">
            <div class="photo" v-for="photo of detail" :key="photo.fileid" >
                <Tag :data="photo" :rowHeight="115" :noNavigate="true" @open="clickFace" />
            </div>

            <div v-if="procesingTotal > 0" class="info-pad">
                {{ t('memories', 'Processing … {n}/{m}', {
                    n: processing,
                    m: procesingTotal,
                }) }}
            </div>
        </div>

        <template #buttons>
            <NcButton @click="close" class="button" type="error">
                {{ t('memories', 'Cancel') }}
            </NcButton>
        </template>
    </Modal>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Watch } from 'vue-property-decorator';
import { NcButton, NcTextField } from '@nextcloud/vue';
import { showError } from '@nextcloud/dialogs'
import { IFileInfo, IPhoto, ITag } from '../../types';
import Tag from '../frame/Tag.vue';

import Modal from './Modal.vue';
import GlobalMixin from '../../mixins/GlobalMixin';
import client from '../../services/DavClient';
import * as dav from '../../services/DavRequests';

@Component({
    components: {
        NcButton,
        NcTextField,
        Modal,
        Tag,
    }
})
export default class FaceMergeModal extends Mixins(GlobalMixin) {
    private user: string = "";
    private name: string = "";
    private detail: IPhoto[] = [];
    private processing = 0;
    private procesingTotal = 0;

    @Emit('close')
    public close() {}

    @Watch('$route')
    async routeChange(from: any, to: any) {
        this.refreshParams();
    }

    mounted() {
        this.refreshParams();
    }

    public async refreshParams() {
        this.user = this.$route.params.user || '';
        this.name = this.$route.params.name || '';
        this.detail = [];
        this.processing = 0;
        this.procesingTotal = 0;

        const data = await dav.getPeopleData();
        let detail = data[0].detail;
        detail.forEach((photo: IPhoto) => {
            photo.flag = this.c.FLAG_IS_FACE | this.c.FLAG_IS_TAG;
        });
        detail = detail.filter((photo: ITag) => {
            const pname = photo.name || photo.fileid.toString();
            return photo.user_id !== this.user || pname !== this.name;
        });

        this.detail = detail;
    }

    public async clickFace(face: ITag) {
        const newName = face.name || face.fileid.toString();
        if (!confirm(this.t('memories', 'Are you sure you want to merge {name} with {newName}?', { name: this.name, newName}))) {
            return;
        }

		try {
            // Get all files for current face
            let res = await client.getDirectoryContents(
                `/recognize/${this.user}/faces/${this.name}`, { details: true }
            ) as any;
            let data: IFileInfo[] = res.data;
            this.procesingTotal = data.length;

            // Don't try too much
            let failures = 0;

            // Create move calls
            const calls = data.map((p) => async () => {
                // Short circuit if we have too many failures
                if (failures === 10) {
                    showError(this.t('memories', 'Too many failures, aborting'));
                    failures++;
                }
                if (failures >= 10) return;

                // Move to new face with webdav
                try {
                    await client.moveFile(
                        `/recognize/${this.user}/faces/${this.name}/${p.basename}`,
                        `/recognize/${face.user_id}/faces/${newName}/${p.basename}`
                    )
                } catch (e) {
                    console.error(e);
                    showError(this.t('memories', 'Error while moving {basename}', p));
                    failures++;
                } finally {
                    this.processing++;
                }
            });
            for await (const _ of dav.runInParallel(calls, 10)) {
                // nothing to do
            }

            // Go to new face
            if (failures === 0) {
                this.$router.push({ name: 'people', params: { user: face.user_id, name: newName } });
                this.close();
            }
		} catch (error) {
            console.error(error);
			showError(this.t('photos', 'Failed to move {name}.', {
                name: this.name,
            }));
		}
    }
}
</script>

<style lang="scss" scoped>
.outer {
    margin-top: 15px;
}
.photo {
    display: inline-block;
    position: relative;
    cursor: pointer;
    vertical-align: top;
    font-size: 0.8em;
}
.info-pad {
    margin-top: 6px;
    margin-bottom: 2px;
}
</style>