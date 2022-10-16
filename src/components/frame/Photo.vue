<template>
    <div class="p-outer fill-block"
        :class="{
            'selected': (data.flag & c.FLAG_SELECTED),
            'p-loading': !(data.flag & c.FLAG_LOADED),
            'leaving': (data.flag & c.FLAG_LEAVING),
            'exit-left': (data.flag & c.FLAG_EXIT_LEFT),
            'enter-right': (data.flag & c.FLAG_ENTER_RIGHT),
            'error': (data.flag & c.FLAG_LOAD_FAIL),
        }">

        <Check :size="15" class="select"
             v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
             @click="toggleSelect" />

        <Video :size="20" v-if="data.flag & c.FLAG_IS_VIDEO" />
        <Star :size="20" v-if="data.flag & c.FLAG_IS_FAVORITE" />

        <div class="img-outer fill-block"
            @click="click"
            @contextmenu="contextmenu"
            @touchstart="touchstart"
            @touchmove="touchend"
            @touchend="touchend"
            @touchcancel="touchend" >
            <img
                class="fill-block"
                :src="src()"
                :key="data.fileid"

                @error="error"
                @load="load" />
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Prop, Emit, Mixins } from 'vue-property-decorator';
import { IDay, IPhoto } from "../../types";
import { subscribe, unsubscribe } from '@nextcloud/event-bus';

import { showError } from '@nextcloud/dialogs'
import { getPreviewUrl } from "../../services/FileUtils";
import * as dav from "../../services/DavRequests";
import errorsvg from "../../assets/error.svg";
import GlobalMixin from '../../mixins/GlobalMixin';

import Check from 'vue-material-design-icons/Check.vue';
import Video from 'vue-material-design-icons/Video.vue';
import Star from 'vue-material-design-icons/Star.vue';

@Component({
    components: {
        Check,
        Video,
        Star,
    },
})
export default class Photo extends Mixins(GlobalMixin) {
    private touchTimer = 0;

    @Prop() data: IPhoto;
    @Prop() day: IDay;

    @Emit('delete') emitDelete(remPhotos: IPhoto[]) {}
    @Emit('select') emitSelect(data: IPhoto) {}
    @Emit('clickImg') emitClickImg(component: any) {}

    /** Get src for image to show */
    src() {
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
            return undefined;
        } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
            return errorsvg;
        } else if (this.data.flag & this.c.FLAG_FORCE_RELOAD) {
            this.data.flag &= ~this.c.FLAG_FORCE_RELOAD;
            return undefined;
        } else {
            return this.url;
        }
    }

    /** Get url of the photo */
    get url() {
        let size = 256;
        if (this.data.w && this.data.h) {
            size = Math.floor(size * Math.max(this.data.w, this.data.h) / Math.min(this.data.w, this.data.h));
        }
        return getPreviewUrl(this.data.fileid, this.data.etag, false, size)
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
            showError(this.t('memories', 'Cannot find this photo anymore!'));
            return;
        }

        // Key to store sidebar state
        const SIDEBAR_KEY = 'memories:sidebar-open';

        // Subscribe to delete events
        const deleteHandler = ({ fileid }) => {
            const photo = this.day.detail.find(p => p.fileid === fileid);
            this.emitDelete([photo]);
        };
        subscribe('files:file:deleted', deleteHandler);

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
                unsubscribe('files:file:deleted', deleteHandler);
            },
        });

        // Restore sidebar state
        if (localStorage.getItem(SIDEBAR_KEY) === '1') {
            globalThis.OCA.Files.Sidebar.open(photo.filename);
        }
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
    &.leaving {
        transition: all 0.2s ease-in;
        transform: scale(0.9);
        opacity: 0;
    }
    &.exit-left {
        transition: all 0.2s ease-in;
        transform: translateX(-20%);
        opacity: 0.8;
    }
    &.enter-right {
        animation: enter-right 0.2s ease-out forwards;
    }
}

@keyframes enter-right {
    from { transform: translateX(20%); opacity: 0.8; }
    to { transform: translateX(0); opacity: 1; }
}

// Distance of icon from border
$icon-dist: min(10px, 6%);

/* Extra icons */
.check-icon.select {
    position: absolute;
    top: $icon-dist; left: $icon-dist;
    z-index: 100;
    background-color: var(--color-main-background);
    border-radius: 50%;
    cursor: pointer;
    display: none;

    .p-outer:hover > & { display: flex; }
    .selected > & { display: flex; filter: invert(1); }
}
.video-icon, .star-icon {
    position: absolute;
    z-index: 100;
    pointer-events: none;
    filter: invert(1) brightness(100);
}
.video-icon {
    top: $icon-dist; right: $icon-dist;
}
.star-icon {
    bottom: $icon-dist; left: $icon-dist;
}

/* Actual image */
div.img-outer {
    padding: 2px;
    @media (max-width: 768px) { padding: 1px; }

    transition: padding 0.1s ease;
    background-clip: content-box, padding-box;
    background-color: var(--color-background-dark);

    .selected > & { padding: calc($icon-dist - 2px); }

    > img {
        background-clip: content-box;
        object-fit: cover;
        cursor: pointer;

        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        user-select: none;
        transition: box-shadow 0.1s ease;

        .selected > & { box-shadow: 0 0 4px 2px var(--color-primary); }
        .p-outer.p-loading > & { display: none; }
        .p-outer.error & { object-fit: contain; }
    }
}
</style>