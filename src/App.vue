<template>
    <NcContent app-name="memories">
        <NcAppNavigation>
            <template id="app-memories-navigation" #list>
                <NcAppNavigationItem :to="{name: 'timeline'}"
                    :title="t('memories', 'Timeline')"
                    exact>
                    <ImageMultiple slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'folders'}"
                    :title="t('memories', 'Folders')">
                    <FolderIcon slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'favorites'}"
                    :title="t('memories', 'Favorites')">
                    <Star slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'videos'}"
                    :title="t('memories', 'Videos')">
                    <Video slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'people'}"
                    :title="t('memories', 'People')">
                    <PeopleIcon slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'archive'}"
                    :title="t('memories', 'Archive')">
                    <ArchiveIcon slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'thisday'}"
                    :title="t('memories', 'On this day')">
                    <CalendarIcon slot="icon" :size="20" />
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'tags'}" v-if="config_tagsEnabled"
                    :title="t('memories', 'Tags')">
                    <TagsIcon slot="icon" :size="20" />
                </NcAppNavigationItem>
            </template>
            <template #footer>
                <NcAppNavigationSettings :title="t('memories', 'Settings')">
                    <Settings />
                </NcAppNavigationSettings>
            </template>
        </NcAppNavigation>

        <NcAppContent>
            <div class="outer">
                <router-view />
            </div>
        </NcAppContent>
    </NcContent>
</template>

<script lang="ts">
import { Component, Mixins } from 'vue-property-decorator';
import {
    NcContent, NcAppContent, NcAppNavigation,
    NcAppNavigationItem, NcAppNavigationSettings,
} from '@nextcloud/vue';
import { generateUrl } from '@nextcloud/router'

import Timeline from './components/Timeline.vue'
import Settings from './components/Settings.vue'
import GlobalMixin from './mixins/GlobalMixin';
import UserConfig from './mixins/UserConfig';

import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Video from 'vue-material-design-icons/Video.vue'
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import CalendarIcon from 'vue-material-design-icons/Calendar.vue';
import PeopleIcon from 'vue-material-design-icons/AccountBoxMultiple.vue';
import TagsIcon from 'vue-material-design-icons/Tag.vue';

@Component({
    components: {
        NcContent,
        NcAppContent,
        NcAppNavigation,
        NcAppNavigationItem,
        NcAppNavigationSettings,

        Timeline,
        Settings,

        ImageMultiple,
        FolderIcon,
        Star,
        Video,
        ArchiveIcon,
        CalendarIcon,
        PeopleIcon,
        TagsIcon,
    },
})
export default class App extends Mixins(GlobalMixin, UserConfig) {
    // Outer element

    public mounted() {
        // Get the content-vue element and add nextcloud version as a class to it
        const contentVue = document.querySelector('#content-vue');
        if (contentVue) {
            const version = (<any>window.OC).config.version.split('.');
            contentVue.classList.add('nextcloud-major-' + version[0]);
        }
    }

    async beforeMount() {
		if ('serviceWorker' in navigator) {
			// Use the window load event to keep the page load performant
			window.addEventListener('load', async () => {
				try {
					const url = generateUrl('/apps/memories/service-worker.js');
					const registration = await navigator.serviceWorker.register(url, { scope: generateUrl('/apps/memories') });
					console.log('SW registered: ', registration);
				} catch (error) {
					console.error('SW registration failed: ', error);
				}
			})
		} else {
			console.debug('Service Worker is not enabled on this browser.')
		}
	}
}
</script>

<style scoped lang="scss">
.outer {
    padding: 0 0 0 44px;
    height: 100%;
    width: 100%;
}

@media (max-width: 768px) {
    .outer {
        padding: 0px;

        // Get rid of padding on img-outer (1px on mobile)
        // Also need to make sure we don't end up with a scrollbar -- see below
        margin-left: -1px;
        width: calc(100% + 3px); // 1px extra here because ... reasons
    }
}
</style>

<style lang="scss">
body {
    overflow: hidden;
}

// Nextcloud 25: get rid of gap and border radius at right
#content-vue.nextcloud-major-25 {
    // was var(--body-container-radius)
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;

    width: calc(100% - var(--body-container-margin)*1); // was *2
}

// Hide horizontal scrollbar on mobile
// For the padding removal above
#app-content-vue {
    overflow-x: hidden;
}

// Fill all available space
.fill-block {
    width: 100%;
    height: 100%;
    display: block;
}
</style>
