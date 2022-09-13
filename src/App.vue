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
import { NcContent, NcAppContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings} from '@nextcloud/vue'

import Timeline from './components/Timeline.vue'
import Settings from './components/Settings.vue'

export default {
    name: 'App',
    components: {
        NcContent,
        NcAppContent,
        NcAppNavigation,
        NcAppNavigationItem,
        NcAppNavigationSettings,

        Timeline,
        Settings,
    },
    data() {
        return {
            loading: false,
            show: true,
            starred: false,
        }
    },
    methods: {
        close() {
            this.show = false
            console.debug(arguments)
        },
        newButtonAction() {
            console.debug(arguments)
        },
        log() {
            console.debug(arguments)
        },
    },
}
</script>
