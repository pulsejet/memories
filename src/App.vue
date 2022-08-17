<template>
	<Content app-name="polaroid">
		<AppNavigation>
			<template id="app-polaroid-navigation" #list>
				<AppNavigationItem :to="{name: 'timeline'}"
					:title="t('timeline', 'Timeline')"
					icon="icon-yourphotos"
					exact>
				</AppNavigationItem>
				<AppNavigationItem :to="{name: 'albums'}"
					:title="t('albums', 'Albums')"
					icon="icon-files-dark">
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
