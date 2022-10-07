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

<style scoped>
.outer {
    padding: 0 0 0 44px;
    height: 100%;
    width: 100%;
}

@media (max-width: 768px) {
    .outer {
        padding-left: 5px;
    }
}
</style>

<script lang="ts">
import { Component, Mixins } from 'vue-property-decorator';
import {
    NcContent, NcAppContent, NcAppNavigation,
    NcAppNavigationItem, NcAppNavigationSettings,
} from '@nextcloud/vue';

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
}
</script>

<style>
body {
    overflow: hidden;
}
</style>
