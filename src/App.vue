<template>
	<Content :class="{'icon-loading': loading}" app-name="betterphotos">
		<AppNavigation>
			<template id="app-betterphotos-navigation" #list>
				<AppNavigationItem icon="icon-yourphotos" title="Timeline">
				</AppNavigationItem>
			</template>
		</AppNavigation>

		<AppContent>
			<div class="outer">
				<Timeline />
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
