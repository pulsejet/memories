<template>
	<Content app-name="memories">
		<AppNavigation>
			<template id="app-memories-navigation" #list>
				<AppNavigationItem :to="{name: 'timeline'}"
					:title="t('timeline', 'Timeline')"
					icon="icon-yourmemories"
					exact>
				</AppNavigationItem>
				<AppNavigationItem :to="{name: 'albums'}"
					:title="t('albums', 'Albums')"
					icon="icon-files-dark">
				</AppNavigationItem>
			</template>
			<template #footer>
				<AppNavigationSettings :title="t('memories', 'Settings')">
					<Settings />
				</AppNavigationSettings>
			</template>
		</AppNavigation>

		<AppContent>
			<div class="outer">
				<router-view />
			</div>
		</AppContent>
	</Content>
</template>

<style scoped>
.outer {
    padding: 0 0 0 44px;
	height: 100%;
}

@media (max-width: 768px) {
	.outer {
		padding-left: 5px;
	}
}
</style>

<script>
import Content from '@nextcloud/vue/dist/Components/Content'
import AppContent from '@nextcloud/vue/dist/Components/AppContent'
import AppNavigation from '@nextcloud/vue/dist/Components/AppNavigation'
import AppNavigationItem from '@nextcloud/vue/dist/Components/AppNavigationItem'
import AppNavigationSettings from '@nextcloud/vue/dist/Components/AppNavigationSettings'

import Timeline from './components/Timeline.vue'
import Settings from './components/Settings.vue'

export default {
	name: 'App',
	components: {
		Content,
		AppContent,
		AppNavigation,
		AppNavigationItem,
		AppNavigationSettings,

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
