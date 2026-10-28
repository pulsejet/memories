import { defineCustomElement } from 'vue';
import ViewerControls from '@components/viewer/video-controls/VideoControls.vue';
import ViewerVideoNativeHole from '@components/viewer/ViewerVideoNativeHole.vue';

const ViewerControlsE = defineCustomElement(ViewerControls, { shadowRoot: false });
export type ViewerControlsCE = InstanceType<typeof ViewerControlsE>;
customElements.define('viewer-controls', ViewerControlsE);

const ViewerVideoNativeHoleE = defineCustomElement(ViewerVideoNativeHole, { shadowRoot: false });
export type ViewerVideoNativeHoleCE = InstanceType<typeof ViewerVideoNativeHoleE>;
customElements.define('viewer-video-native-hole', ViewerVideoNativeHoleE);
