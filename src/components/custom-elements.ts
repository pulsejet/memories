import { defineCustomElement } from 'vue';
import ViewerControls from '@components/viewer/VideoControls.vue';

const ViewerControlsE = defineCustomElement(ViewerControls, { shadowRoot: false });
export type ViewerControlsCE = InstanceType<typeof ViewerControlsE>;
customElements.define('viewer-controls', ViewerControlsE);
