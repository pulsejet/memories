<template>
    <div>
        <div v-if="data.is_video" class="icon-video-white"></div>
        <img
            @click="openFile()"
            :src="`/core/preview?fileId=${data.file_id}&c=${data.etag}&x=250&y=250&forceIcon=0&a=0`"
            :key="data.file_id"
            @load = "data.l = Math.random()"
            @error="(e) => e.target.src='img/error.svg'"
            v-bind:style="{
                width: rowHeight + 'px',
                height: rowHeight + 'px',
            }"/>
    </div>
</template>

<script>
import * as dav from "../services/DavRequests";

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
        },
        day: {
            type: Object,
            required: true,
        },
    },
    methods: {
        /** Open viewer */
        async openFile() {
            let fileInfos = this.day.fileInfos;

            if (!fileInfos) {
                const ids = this.day.detail.map(p => p.file_id);
                try {
                    this.loading = true;
                    fileInfos = await dav.getFiles(ids);
                } catch (e) {
                    console.error('Failed to load fileInfos', e);
                } finally {
                    this.loading = false;
                }
                if (fileInfos.length === 0) {
                    return;
                }
                this.day.fileInfos = fileInfos;

                // Fix sorting of the fileInfos
                const itemPositions = {};
                for (const [index, id] of ids.entries()) {
                    itemPositions[id] = index;
                }
                fileInfos.sort(function (a, b) {
                    return itemPositions[a.fileid] - itemPositions[b.fileid];
                });
            }

            const photo = fileInfos.find(d => Number(d.fileid) === Number(this.data.file_id));
            if (!photo) {
                alert('Cannot find this photo anymore!');
                return;
            }

            OCA.Viewer.open({
                path: photo.filename,
                list: fileInfos,
                canLoop: false,
            });
        }
    }
}
</script>

<style scoped>
.icon-video-white {
    position: absolute;
    top: 8px; right: 8px;
}
img {
    background-clip: content-box;
    background-color: var(--color-loading-light);
    padding: 2px;
    object-fit: cover;
    border-radius: 3%;
    cursor: pointer;
}
</style>