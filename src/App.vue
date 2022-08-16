<template>
	<Content app-name="betterphotos">
		<AppNavigation>
			<template id="app-betterphotos-navigation" #list>
				<AppNavigationItem :to="{name: 'timeline'}"
					class="app-navigation__photos"
					:title="t('timeline', 'Timeline')"
					icon="icon-yourphotos"
					exact>
				</AppNavigationItem>
			</template>
		</AppNavigation>

		<AppContent :class="{ 'icon-loading': loading }">
			<div class="outer">
				<router-view v-show="!loading" :loading.sync="loading" />
			</div>
		</AppContent>
	</Content>
</template>

<style scoped>
.outer {
    padding: 0 0 0 44px;
	height: 100%;
}
</style>

<script>
import Content from '@nextcloud/vue/dist/Components/Content'
import AppContent from '@nextcloud/vue/dist/Components/AppContent'
import AppNavigation from '@nextcloud/vue/dist/Components/AppNavigation'
import AppNavigationItem from '@nextcloud/vue/dist/Components/AppNavigationItem'
import Timeline from './components/Timeline.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'App',
	components: {
		Content,
		AppContent,
		AppNavigation,
		AppNavigationItem,
		Timeline,
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
