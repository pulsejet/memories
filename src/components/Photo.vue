<template>
    <div class="p-outer"
        :class="{
            'selected': (data.flag & c.FLAG_SELECTED),
            'p-loading': !(data.flag & c.FLAG_LOADED),
            'leaving': (data.flag & c.FLAG_LEAVING),
            'exit-left': (data.flag & c.FLAG_EXIT_LEFT),
            'enter-right': (data.flag & c.FLAG_ENTER_RIGHT),
        }">

        <div class="icon-checkmark select"
             v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
             @click="toggleSelect"></div>

        <div v-if="data.flag & c.FLAG_IS_VIDEO" class="icon-video-white"></div>
        <div v-if="data.flag & c.FLAG_IS_FAVORITE" class="icon-starred"></div>

        <div class="img-outer" :style="{
                width: rowHeight + 'px',
                height: rowHeight + 'px',
            }">
            <img
                :src="getSrc()"
                :key="data.fileid"

                @click="click"
                @error="error"
                @load="load"

                @contextmenu="contextmenu"
                @touchstart="touchstart"
                @touchmove="touchend"
                @touchend="touchend"
                @touchcancel="touchend" />
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Prop, Emit, Mixins } from 'vue-property-decorator';
import { IDay, IPhoto } from "../types";

import * as dav from "../services/DavRequests";
import errorsvg from "../assets/error.svg";
import { getPreviewUrl } from "../services/FileUtils";
import GlobalMixin from '../mixins/GlobalMixin';

@Component({})
export default class Photo extends Mixins(GlobalMixin) {
    private touchTimer = 0;

    @Prop() data: IPhoto;
    @Prop() rowHeight: number;
    @Prop() day: IDay;
    @Prop() state: number;

    @Emit('reprocess') emitReprocess(remIds: Set<number>, updatedDays: Set<IDay>) {}
    @Emit('select') emitSelect(data: IPhoto) {}
    @Emit('clickImg') emitClickImg(component: any) {}

    /** Get src for image to show */
    getSrc() {
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
            return undefined;
        } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
            return errorsvg;
        } else if (this.data.flag & this.c.FLAG_FORCE_RELOAD) {
            this.data.flag &= ~this.c.FLAG_FORCE_RELOAD;
            return undefined;
        } else {
            return this.getUrl();
        }
    }

    /** Get url of the photo */
    getUrl() {
        return getPreviewUrl(this.data.fileid, this.data.etag);
    }

    /** Image loaded successfully */
    load() {
        this.data.flag |= this.c.FLAG_LOADED;
    }

    /** Error in loading image */
    error(e: any) {
        this.data.flag |= (this.c.FLAG_LOADED | this.c.FLAG_LOAD_FAIL);
    }

    /** Clear timers */
    beforeUnmount() {
        clearTimeout(this.touchTimer);
    }

    /** Pass to parent */
    click() {
        this.emitClickImg(this);
    }

    /** Open viewer */
    async openFile() {
        // Check if this is a placeholder
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
            return;
        }

        // Check if already loaded fileInfos or load
        let fileInfos = this.day.fileInfos;
        if (!fileInfos) {
            const ids = this.day.detail.map(p => p.fileid);
            try {
                fileInfos = await dav.getFiles(ids);
            } catch (e) {
                console.error('Failed to load fileInfos', e);
            }
            if (fileInfos.length === 0) {
                return;
            }

            // Fix sorting of the fileInfos
            const itemPositions = {};
            for (const [index, id] of ids.entries()) {
                itemPositions[id] = index;
            }
            fileInfos.sort(function (a, b) {
                return itemPositions[a.fileid] - itemPositions[b.fileid];
            });

            // Store in day with a original copy
            this.day.fileInfos = fileInfos;
            this.day.origFileIds = new Set(fileInfos.map(f => f.fileid));
        }

        // Get this photo in the fileInfos
        const photo = fileInfos.find(d => Number(d.fileid) === Number(this.data.fileid));
        if (!photo) {
            alert('Cannot find this photo anymore!');
            return;
        }

        // Key to store sidebar state
        const SIDEBAR_KEY = 'memories:sidebar-open';

        // Open viewer
        globalThis.OCA.Viewer.open({
            path: photo.filename,   // path
            list: fileInfos,        // file list
            canLoop: false,         // don't loop
            onClose: () => {        // on viewer close
                if (globalThis.OCA.Files.Sidebar.file) {
                    localStorage.setItem(SIDEBAR_KEY, '1');
                } else {
                    localStorage.removeItem(SIDEBAR_KEY);
                }
                globalThis.OCA.Files.Sidebar.close();

                // Check for any deleted files and remove them from the main view
                this.processDeleted();
            },
        });

        // Restore sidebar state
        if (localStorage.getItem(SIDEBAR_KEY) === '1') {
            globalThis.OCA.Files.Sidebar.open(photo.filename);
        }
    }

    /** Remove deleted files from main view */
    processDeleted() {
        // This is really an ugly hack, but the viewer
        // does not provide a way to get the deleted files

        // Compare new and old list of ids
        const newIds = new Set(this.day.fileInfos.map(f => f.fileid));
        const remIds = new Set([...this.day.origFileIds].filter(x => !newIds.has(x)));

        // Exit if nothing to do
        if (remIds.size === 0) {
            return;
        }
        this.day.origFileIds = newIds;

        // Remove deleted files from details
        this.emitReprocess(remIds, new Set([this.day]));
    }

    toggleSelect() {
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
            return;
        }
        this.emitSelect(this.data);
    }

    touchstart() {
        this.touchTimer = window.setTimeout(() => {
            this.toggleSelect();
            this.touchTimer = 0;
        }, 600);
    }

    contextmenu(e: Event) {
        e.preventDefault();
        e.stopPropagation();
    }

    touchend() {
        if (this.touchTimer) {
            clearTimeout(this.touchTimer);
            this.touchTimer = 0;
        }
    }
}
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
    will-change: transform, opacity;
    transform: translateZ(0);

    &.leaving {
        transition: all 0.2s ease-in;
        transform: scale(0.9);
        opacity: 0;
    }
    &.exit-left {
        transition: all 0.2s ease-in;
        transform: translateX(-20%);
        opacity: 0.4;
    }
    &.enter-right {
        animation: enter-right 0.2s ease-out forwards;
    }
}

@keyframes enter-right {
    from { transform: translateX(20%); opacity: 0.4; }
    to { transform: translateX(0); opacity: 1; }
}

/* Extra icons */
.icon-checkmark {
    position: absolute;
    top: 5%; left: 5%;
    z-index: 100;
    background-color: var(--color-main-background);
    border-radius: 50%;
    background-size: 80%;
    padding: 5px;
    cursor: pointer;
    opacity: 0;

    .p-outer:hover & { opacity: 0.7; }
    .selected & { opacity: 0.9; filter: invert(1); }
}
.icon-video-white {
    position: absolute;
    top: 5%; right: 5%;
    z-index: 100;
}
.icon-starred {
    position: absolute;
    bottom: 5%; left: 5%;
    z-index: 100;
    pointer-events: none;
}

/* Actual image */
div.img-outer {
    padding: 2px;
    will-change: padding;
    transition: padding 0.1s ease;
    background-clip: content-box, padding-box;
    background-color: var(--color-loading-light);

    .selected & { padding: 6%; }
}
img {
    background-clip: content-box;
    object-fit: cover;
    cursor: pointer;
    width: 100%; height: 100%;
    opacity: 1;
    transition: opacity 0.15s ease;
    will-change: opacity;
    transform: translateZ(0);

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;

    .selected & { box-shadow: 0 0 6px 2px var(--color-primary); }
    .p-loading & { opacity: 0; }
}
</style>