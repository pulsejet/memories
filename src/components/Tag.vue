<template>
    <div class="tag" v-bind:class="{
        hasPreview: previews.length > 0,
        onePreview: previews.length === 1,
        hasError: error,
    }"
        @click="openTag(data)"
        v-bind:style="{
            width: rowHeight + 'px',
            height: rowHeight + 'px',
        }">
        <div class="big-icon">
            <div class="name">{{ data.name }}</div>
        </div>

        <div class="previews">
            <div class="img-outer" v-for="info of previews" :key="info.fileid">
                <img
                    :key="'fpreview-' + info.fileid"
                    :src="getPreviewUrl(info.fileid, info.etag)"
                    :class="{
                        'p-loading': !(info.flag & c.FLAG_LOADED),
                        'p-load-fail': info.flag & c.FLAG_LOAD_FAIL,
                    }"
                    @load="info.flag |= c.FLAG_LOADED"
                    @error="info.flag |= c.FLAG_LOAD_FAIL" />
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Prop, Watch, Mixins } from 'vue-property-decorator';
import { IPhoto, ITag } from '../types';
import { generateUrl } from '@nextcloud/router'
import { getPreviewUrl } from "../services/FileUtils";

import axios from '@nextcloud/axios'
import GlobalMixin from '../mixins/GlobalMixin';

@Component({})
export default class Tag extends Mixins(GlobalMixin) {
    @Prop() data: ITag;
    @Prop() rowHeight: number;

    // Separate property because the one on data isn't reactive
    private previews: IPhoto[] = [];

    // Error occured fetching thumbs
    private error = false;

    /** Passthrough */
    private getPreviewUrl = getPreviewUrl;

    mounted() {
        this.refreshPreviews();
    }

    @Watch('data')
    dataChanged() {
        this.refreshPreviews();
    }

    /** Refresh previews */
    async refreshPreviews() {
        // Reset state
        this.error = false;

        // Get previews
        const url = `/apps/memories/api/days/*?limit=4&tag=${this.data.name}`;
        try {
            const res = await axios.get<IPhoto[]>(generateUrl(url));
            if (res.data.length < 4) {
                res.data = res.data.slice(0, 1);
            }
            res.data.forEach((p) => p.flag = 0);
            this.previews = res.data;
        } catch (e) {
            this.error = true;
            console.error(e);
        }
    }

    /** Open tag */
    openTag(tag: ITag) {
        this.$router.push({ name: 'tags', params: { name: tag.name }});
    }
}
</script>

<style lang="scss" scoped>
.tag {
    cursor: pointer;
}

.big-icon {
    cursor: pointer;
    z-index: 100;
    position: absolute;
    top: 45%; width: 100%;
    transform: translateY(-50%);

    > .name {
        cursor: pointer;
        color: white;
        width: 100%;
        padding: 0 5%;
        text-align: center;
        font-size: 1.08em;
        word-wrap: break-word;
        text-overflow: ellipsis;
        max-height: 35%;
        line-height: 1em;
        position: absolute;
    }
}

.previews {
    z-index: 3;
    line-height: 0;
    position: absolute;
    height: calc(100% - 4px);
    width: calc(100% - 4px);
    top: 2px; left: 2px;

    > .img-outer {
        background-color: var(--color-background-dark);
        padding: 0;
        margin: 0;
        width: 50%;
        height: 50%;
        display: inline-block;

        .tag.onePreview > & {
            width: 100%; height: 100%;
        }

        > img {
            padding: 0;
            width: 100%;
            height: 100%;
            filter: brightness(50%);
            cursor: pointer;

            transition: filter 0.2s ease-in-out;
            will-change: filter;
            transform: translateZ(0);

            &.p-loading, &.p-load-fail {
                display: none;
            }

            .tag:hover & {
                filter: brightness(100%);
            }
        }
    }
}
</style>