<template>
    <NcBreadcrumbs v-if="topMatter">
        <NcBreadcrumb title="Home" :to="{ name: 'folders' }">
            <template #icon>
                <HomeIcon :size="20" />
            </template>
        </NcBreadcrumb>
        <NcBreadcrumb v-for="folder in topMatter.list" :key="folder.path" :title="folder.text"
                     :to="{ name: 'folders', params: { path: folder.path }}" />
    </NcBreadcrumbs>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from 'vue-property-decorator';
import { TopMatterFolder, TopMatterType } from "../types";
import { NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue';
import GlobalMixin from '../mixins/GlobalMixin';
import HomeIcon from 'vue-material-design-icons/Home.vue';

@Component({
    components: {
        NcBreadcrumbs,
        NcBreadcrumb,
        HomeIcon,
    }
})
export default class FolderTopMatter extends Mixins(GlobalMixin) {
    private topMatter?: TopMatterFolder = null;

    @Watch('$route')
    async routeChange(from: any, to: any) {
        this.createMatter();
    }

    mounted() {
        this.createMatter();
    }

    createMatter() {
        if (this.$route.name === 'folders') {
            this.topMatter = {
                type: TopMatterType.FOLDER,
                list: (this.$route.params.path || '').split('/').filter(x => x).map((x, idx, arr) => {
                    return {
                        text: x,
                        path: arr.slice(0, idx + 1).join('/'),
                    }
                }),
            };
        } else {
            this.topMatter = null;
        }
    }
}
</script>