import { Component, Vue } from 'vue-property-decorator';
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { constants } from '../services/Utils';

@Component
export default class GlobalMixin extends Vue {
    public readonly t = t;
    public readonly n = n;

    public readonly c = constants.c;
    public readonly TagDayID = constants.TagDayID;
}