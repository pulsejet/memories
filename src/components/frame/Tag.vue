<template>
    <div class="tag fill-block" :class="{
        hasPreview: previews.length > 0,
        onePreview: previews.length === 1,
        hasError: error,
        isFace: isFace,
    }"
    @click="openTag(data)">

        <div class="bbl"> <NcCounterBubble> {{ data.count }} </NcCounterBubble> </div>
        <div class="name"> {{ data.name }} </div>

        <div class="previews fill-block" ref="previews">
            <div class="img-outer" v-for="info of previews" :key="info.fileid">
                <img
                    class="fill-block"
                    :class="{
                        'p-loading': !(info.flag & c.FLAG_LOADED),
                        'p-load-fail': info.flag & c.FLAG_LOAD_FAIL,
                    }"
                    :key="'fpreview-' + info.fileid"
                    :src="getPreviewUrl(info.fileid, info.etag)"
                    :style="getCoverStyle(info)"
                    @load="info.flag |= c.FLAG_LOADED"
                    @error="info.flag |= c.FLAG_LOAD_FAIL" />
            </div>
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Prop, Watch, Mixins, Emit } from 'vue-property-decorator';
import { IPhoto, ITag } from '../../types';
import { generateUrl } from '@nextcloud/router'
import { getPreviewUrl } from "../../services/FileUtils";

import { NcCounterBubble } from '@nextcloud/vue'

import GlobalMixin from '../../mixins/GlobalMixin';
import { constants } from '../../services/Utils';

interface IFaceDetection extends IPhoto {
    x: number;
    y: number;
    width: number;
    height: number;
}

@Component({
    components: {
        NcCounterBubble,
    },
})
export default class Tag extends Mixins(GlobalMixin) {
    @Prop() data: ITag;
    @Prop() noNavigate: boolean;

    // Separate property because the one on data isn't reactive
    private previews: IPhoto[] = [];

    // Error occured fetching thumbs
    private error = false;

    mounted() {
        this.refreshPreviews();
    }

    @Watch('data')
    dataChanged() {
        this.refreshPreviews();
    }

    getPreviewUrl(fileid: number, etag: string) {
        if (this.isFace) {
            return generateUrl(`/core/preview?fileId=${fileid}&c=${etag}&x=2048&y=2048&forceIcon=0&a=1`);
        }
        return getPreviewUrl(fileid, etag);
    }

    get isFace() {
        return this.data.flag & constants.c.FLAG_IS_FACE;
    }

    async refreshPreviews() {
        // Reset state
        this.error = false;

        // Look for previews
        if (!this.data.previews) {
            return;
        }

        // Reset flag
        this.data.previews.forEach((p) => p.flag = 0);

        if (this.isFace) {
            const face = this.chooseFaceDetection(this.data.previews as IFaceDetection[]);
            this.previews = [face];
        } else {
            let data = this.data.previews;
            if (data.length < 4) {
                data = data.slice(0, 1);
            }
            this.previews = data;
        }

        this.error = this.previews.length === 0;
    }

    /** Open tag */
    @Emit('open')
    openTag(tag: ITag) {
        if (this.noNavigate) {
            return;
        }

        if (this.isFace) {
            const name = this.data.name || this.data.fileid.toString();
            const user = this.data.user_id;
            this.$router.push({ name: 'people', params: { name, user  }});
        } else {
            this.$router.push({ name: 'tags', params: { name: this.data.name }});
        }
    }

    /** Choose the most appropriate face detection */
    private chooseFaceDetection(detections: IFaceDetection[]) {
        const scoreFacePosition = (faceDetection: IFaceDetection) => {
            return Math.max(0, -1 * (faceDetection.x - faceDetection.width * 0.5))
            + Math.max(0, -1 * (faceDetection.y - faceDetection.height * 0.5))
            + Math.max(0, -1 * (1 - (faceDetection.x + faceDetection.width) - faceDetection.width * 0.5))
            + Math.max(0, -1 * (1 - (faceDetection.y + faceDetection.height) - faceDetection.height * 0.5))
        }

        const scoreFace = (faceDetection: IFaceDetection) => {
            return (1 - faceDetection.width * faceDetection.height) + scoreFacePosition(faceDetection);
        }

        return detections.sort((a, b) => scoreFace(a) - scoreFace(b))[0];
    }

    /**
     * This will produce an inline style to apply to images
     * to zoom toward the detected face
     */
    getCoverStyle(photo: IPhoto) {
        if (!this.isFace) {
            return {};
        }

        // Pass the same thing
        const detection = photo as IFaceDetection;

        // Zoom into the picture so that the face fills the --photos-face-width box nicely
        // if the face is larger than the image, we don't zoom out (reason for the Math.max)
        const zoom = Math.max(1, (1 / detection.width) * 0.4)

        // Get center coordinate in percent
        const horizontalCenterOfFace = (detection.x + detection.width / 2) * 100
        const verticalCenterOfFace = (detection.y + detection.height / 2) * 100

        // Get preview element dimensions
        const elem = this.$refs.previews as HTMLElement;
        const elemWidth = elem.clientWidth;
        const elemHeight = elem.clientHeight;

        return {
            // we translate the image so that the center of the detected face is in the center
            // and add the zoom
            transform: `translate(calc(${elemWidth}px/2 - ${horizontalCenterOfFace}% ), calc(${elemHeight}px/2 - ${verticalCenterOfFace}% )) scale(${zoom})`,
            // this is necessary for the zoom to zoom toward the center of the face
            transformOrigin: `${horizontalCenterOfFace}% ${verticalCenterOfFace}%`,
        }
    }
}
</script>

<style lang="scss" scoped>
.tag, .name, .bubble, img {
    cursor: pointer;
}

.name {
    z-index: 100;
    position: absolute;
    top: 50%; width: 100%;
    transform: translateY(-50%);
    color: white;
    width: 90%;
    padding: 0 5%;
    text-align: center;
    font-size: 1.2em;
    word-wrap: break-word;
    text-overflow: ellipsis;
    line-height: 1em;

    .isFace > & {
        top: unset;
        bottom: 10%;
        transform: unset;
    }
}

.bbl {
    z-index: 100;
    position: absolute;
    top: 6px; right: 5px;
}

.previews {
    z-index: 3;
    line-height: 0;
    position: absolute;
    padding: 2px;
    @media (max-width: 768px) { padding: 1px; }

    > .img-outer {
        background-color: var(--color-background-dark);
        padding: 0;
        margin: 0;
        width: 50%;
        height: 50%;
        overflow: hidden;
        display: inline-block;
        cursor: pointer;

        .tag.onePreview > & {
            width: 100%; height: 100%;
        }

        > img {
            padding: 0;
            filter: brightness(60%);
            cursor: pointer;
            transition: filter 0.2s ease-in-out;

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