import { defineCustomElement } from 'vue';
import ViewerControls from '@components/viewer/video-controls/VideoControls.vue';

const ViewerControlsE = defineCustomElement(ViewerControls, { shadowRoot: false });
export type ViewerControlsCE = InstanceType<typeof ViewerControlsE>;
customElements.define('viewer-controls', ViewerControlsE);
