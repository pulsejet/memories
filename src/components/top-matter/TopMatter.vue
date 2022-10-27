<template>
    <div class="top-matter" v-if="type">
        <FolderTopMatter v-if="type === 1" />
        <TagTopMatter v-else-if="type === 2" />
        <FaceTopMatter v-else-if="type === 3" />
        <AlbumTopMatter v-else-if="type === 4" />
    </div>
</template>

<script lang="ts">
import { Component, Mixins, Watch } from 'vue-property-decorator';
import FolderTopMatter from "./FolderTopMatter.vue";
import TagTopMatter from "./TagTopMatter.vue";
import FaceTopMatter from "./FaceTopMatter.vue";
import AlbumTopMatter from "./AlbumTopMatter.vue";

import GlobalMixin from '../../mixins/GlobalMixin';
import { TopMatterType } from '../../types';

@Component({
    components: {
        FolderTopMatter,
        TagTopMatter,
        FaceTopMatter,
        AlbumTopMatter,
    },
})
export default class TopMatter extends Mixins(GlobalMixin) {
    public type: TopMatterType = TopMatterType.NONE;

    @Watch('$route')
    async routeChange(from: any, to: any) {
        this.setTopMatter();
    }

    mounted() {
        this.setTopMatter();
    }

    /** Create top matter */
    setTopMatter() {
        this.type = (() => {
            switch (this.$route.name) {
                case 'folders': return TopMatterType.FOLDER;
                case 'tags': return this.$route.params.name ? TopMatterType.TAG : TopMatterType.NONE;
                case 'people': return this.$route.params.name ? TopMatterType.FACE : TopMatterType.NONE;
                case 'albums': return TopMatterType.ALBUM;
                default: return TopMatterType.NONE;
            }
        })();
    }
}
</script>