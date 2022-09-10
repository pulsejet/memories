<template>
    <div class="folder" v-bind:class="{
        hasPreview: previewFileInfos.length > 0,
        onePreview: previewFileInfos.length === 1,
    }"
        @click="openFolder(data.fileid)"
        v-bind:style="{
            width: rowHeight + 'px',
            height: rowHeight + 'px',
        }">
        <div class="big-icon">
            <div class="icon-folder" v-bind:class="{
                'icon-dark': previewFileInfos.length === 0,
                'icon-white': previewFileInfos.length > 0,
            }"></div>
            <div class="name">{{ data.name }}</div>
        </div>

        <div class="previews">
            <img v-for="info of previewFileInfos"
                :key="'fpreview-' + info.fileid"
                :src="getPreviewUrl(info.fileid, info.etag)" />
        </div>
    </div>
</template>

<script>
import * as dav from "../services/DavRequests";
import { getPreviewUrl } from "../services/FileUtils";

export default {
    name: 'Folder',
    props: {
        data: {
            type: Object,
            required: true
        },
        rowHeight: {
            type: Number,
            required: true,
        }
    },
    data() {
        return {
            previewFileInfos: [],
        }
    },
    mounted() {
        this.refreshPreviews();
    },
    watch: {
        data: {
            handler() {
                this.refreshPreviews();
            },
        },
    },
    methods: {
        /** Passthrough */
        getPreviewUrl: getPreviewUrl,

        /** Refresh previews */
        refreshPreviews() {
            if (!this.data.previewFileInfos) {
                const folderPath = this.data.path.split('/').slice(3).join('/');
                dav.getFolderPreviewFileIds(folderPath, 4).then(fileInfos => {
                    fileInfos = fileInfos.filter(f => f.hasPreview);
                    if (fileInfos.length > 0 && fileInfos.length < 4) {
                        fileInfos = [fileInfos[0]];
                    }
                    this.data.previewFileInfos = fileInfos;
                    this.previewFileInfos = fileInfos;
                }).catch(() => {
                    this.data.previewFileInfos = [];
                    this.previewFileInfos = [];
                });
            } else {
                this.previewFileInfos = this.data.previewFileInfos;
            }
        },

        /** Open folder */
        openFolder(id) {
            this.$router.push({ name: 'folders', params: { id } });
        },
    }
}
</script>

<style lang="scss" scoped>
.folder {
    cursor: pointer;

    .name {
        cursor: pointer;
        width: 100%;
        padding: 0 5%;
        text-align: center;
        font-size: 1.08em;
        word-wrap: break-word;
        text-overflow: ellipsis;
        max-height: 35%;
        line-height: 1em;
    }
}

.big-icon {
    cursor: pointer;
    z-index: 100;
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    transition: opacity 0.2s ease-in-out;

    .folder.hasPreview & {
        .icon-folder { opacity: 1; }
        .name { color: white; }
    }
    .folder.hasPreview:hover & { opacity: 0; }

    .icon-folder {
        cursor: pointer;
        height: 65%; width: 100%;
        opacity: 0.3;
        background-size: 40%;
        background-position: bottom;
    }
}

.previews {
    z-index: 3;
    line-height: 0;
    position: absolute;
    height: calc(100% - 4px);
    width: calc(100% - 4px);
    top: 2px; left: 2px;

    img {
        padding: 0;
        width: 50%;
        height: 50%;
        display: inline-block;
        transition: filter 0.2s ease-in-out;
        filter: brightness(50%);

        .folder:hover & {
            filter: brightness(100%);
        }

        .folder.onePreview & {
            width: 100%; height: 100%;
        }
    }
}
</style>