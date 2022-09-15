<template>
    <NcContent app-name="memories">
        <NcAppNavigation>
            <template id="app-memories-navigation" #list>
                <NcAppNavigationItem :to="{name: 'timeline'}"
                    :title="t('timeline', 'Timeline')"
                    icon="icon-yourmemories"
                    exact>
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'folders'}"
                    :title="t('folders', 'Folders')"
                    icon="icon-files-dark">
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'favorites'}"
                    :title="t('favorites', 'Favorites')"
                    icon="icon-favorite">
                </NcAppNavigationItem>
                <NcAppNavigationItem :to="{name: 'videos'}"
                    :title="t('videos', 'Videos')"
                    icon="icon-video">
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
import NcContent from '@nextcloud/vue/dist/Components/NcContent';
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent';
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation';
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem';
import NcAppNavigationSettings from '@nextcloud/vue/dist/Components/NcAppNavigationSettings';

import Timeline from './components/Timeline.vue'
import Settings from './components/Settings.vue'
import GlobalMixin from './mixins/GlobalMixin';

@Component({
    components: {
        NcContent,
        NcAppContent,
        NcAppNavigation,
        NcAppNavigationItem,
        NcAppNavigationSettings,

        Timeline,
        Settings,
    },
})
export default class App extends Mixins(GlobalMixin) {
    // Outer element
}
</script>
