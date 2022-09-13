import { Component, Vue } from 'vue-property-decorator';
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

@Component
export default class GlobalMixin extends Vue {
    public readonly t = t;
    public readonly n = n;

    public readonly c = {
        FLAG_PLACEHOLDER:   1 << 0,
        FLAG_LOADED:        1 << 1,
        FLAG_LOAD_FAIL:     1 << 2,
        FLAG_IS_VIDEO:      1 << 3,
        FLAG_IS_FAVORITE:   1 << 4,
        FLAG_SELECTED:      1 << 5,
        FLAG_LEAVING:       1 << 6,
        FLAG_EXIT_LEFT:     1 << 7,
        FLAG_ENTER_RIGHT:   1 << 8,
        FLAG_FORCE_RELOAD:  1 << 9,
    }
}