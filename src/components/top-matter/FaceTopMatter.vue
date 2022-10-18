<template>
    <div v-if="name" class="face-top-matter">
        <NcActions>
			<NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
				{{ t('memories', 'Back') }}
                <template #icon> <BackIcon :size="20" /> </template>
			</NcActionButton>
		</NcActions>

        <div class="name">{{ name }}</div>

        <div class="right-actions">
            <NcActions :inline="1">
                <NcActionButton :aria-label="t('memories', 'Rename person')" @click="showEditModal=true" close-after-click>
                    {{ t('memories', 'Rename person') }}
                    <template #icon> <EditIcon :size="20" /> </template>
                </NcActionButton>
                <NcActionButton :aria-label="t('memories', 'Merge with different person')" @click="showMergeModal=true" close-after-click>
                    {{ t('memories', 'Merge with different person') }}
                    <template #icon> <MergeIcon :size="20" /> </template>
                </NcActionButton>
                <NcActionCheckbox :aria-label="t('memories', 'Mark person in preview')" :checked.sync="config_showFaceRect" @change="changeShowFaceRect">
                    {{ t('memories', 'Mark person in preview') }}
                    <template #icon> <MergeIcon :size="20" /> </template>
                </NcActionCheckbox>
                <NcActionButton :aria-label="t('memories', 'Remove person')" @click="showDeleteModal=true" close-after-click>
                    {{ t('memories', 'Remove person') }}
                    <template #icon> <DeleteIcon :size="20" /> </template>
                </NcActionButton>
            </NcActions>
        </div>

        <FaceEditModal v-if="showEditModal" @close="showEditModal=false" />
        <FaceDeleteModal v-if="showDeleteModal" @close="showDeleteModal=false" />
        <FaceMergeModal v-if="showMergeModal" @close="showMergeModal=false" />
    </div>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from 'vue-property-decorator';
import GlobalMixin from '../../mixins/GlobalMixin';
import UserConfig from "../../mixins/UserConfig";

import { NcActions, NcActionButton, NcActionCheckbox } from '@nextcloud/vue';
import FaceEditModal from '../modal/FaceEditModal.vue';
import FaceDeleteModal from '../modal/FaceDeleteModal.vue';
import FaceMergeModal from '../modal/FaceMergeModal.vue';
import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/Close.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';

@Component({
    components: {
        NcActions,
        NcActionButton,
        NcActionCheckbox,
        FaceEditModal,
        FaceDeleteModal,
        FaceMergeModal,
        BackIcon,
        EditIcon,
        DeleteIcon,
        MergeIcon,
    },
})
export default class FaceTopMatter extends Mixins(GlobalMixin, UserConfig) {
    private name: string = '';
    private showEditModal: boolean = false;
    private showDeleteModal: boolean = false;
    private showMergeModal: boolean = false;

    @Watch('$route')
    async routeChange(from: any, to: any) {
        this.createMatter();
    }

    mounted() {
        this.createMatter();
    }

    createMatter() {
        this.name = this.$route.params.name || '';
    }

    back() {
        this.$router.push({ name: 'people' });
    }

    changeShowFaceRect() {
        localStorage.setItem('memories_showFaceRect', this.config_showFaceRect ? '1' : '0');
        setTimeout(() => {
            this.$router.go(0); // refresh page
        }, 500);
    }
}
</script>

<style lang="scss" scoped>
.face-top-matter {
    display: flex;
    vertical-align: middle;

    .name {
        font-size: 1.3em;
        font-weight: 400;
        line-height: 40px;
        padding-left: 3px;
        flex-grow: 1;
    }

    .right-actions {
        margin-right: 40px;
        z-index: 50;
        @media (max-width: 768px) {
            margin-right: 10px;
        }
    }
}
</style>