
<template>
	<div style="display: none;">
		<slot v-if="ready" />
	</div>
</template>

<script>
import 'leaflet.heat/dist/leaflet-heat.js'
import { findRealParent, propsBinder } from 'vue2-leaflet'
import { DomEvent } from 'leaflet'

const props = {
	initialPoints: {
		type: Array,
		required: false,
		default() { return [] },
	},
	options: {
		type: Object,
		default() { return {} },
	},
}

export default {
	props,
	data() {
		return {
			points: null,
			ready: false,
		}
	},
	watch: {
		options: {
			handler(newOptions) {
				this.mapObject.setOptions(newOptions)
			},
			deep: true,
		},
		points: {
			handler(newPoints) {
				this.mapObject.setLatLngs(newPoints)
			},
			deep: true,
		},
	},
	mounted() {
		this.points = this.initialPoints
		this.mapObject = L.heatLayer(this.points, this.options)
		DomEvent.on(this.mapObject, this.$listeners)
		propsBinder(this, this.mapObject, props)
		this.ready = true
		this.parentContainer = findRealParent(this.$parent)
		this.parentContainer.addLayer(this)
		this.$nextTick(() => {
			this.$emit('ready', this.mapObject)
		})
	},
	beforeDestroy() {
		this.parentContainer.removeLayer(this)
	},
	methods: {
		addLayer(layer, alreadyAdded) {
			if (!alreadyAdded) {
				this.mapObject.addLayer(layer.mapObject)
			}
		},
		removeLayer(layer, alreadyRemoved) {
			if (!alreadyRemoved) {
				this.mapObject.removeLayer(layer.mapObject)
			}
		},
		addLatLng(latlng) {
			this.mapObject.addLatLng(latlng)
		},
		setLatLngs(latlngs) {
			this.mapObject.setLatLngs(latlngs)
		},
		redraw() {
			this.mapObject.redraw()
		},
	},
}
</script>
