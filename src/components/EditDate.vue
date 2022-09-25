<template>
    <NcModal
        v-if="photos.length > 0"
        size="small"
        @close="close"
        :outTransition="true"
        :hasNext="false"
        :hasPrevious="false">

        <div class="container">
            <div class="head">
                <span>{{ t('memories', 'Edit Date/Time') }}</span>
            </div>

            <div v-if="photos.length === 1 && longDateStr">
                {{ longDateStr }}

                <div class="fields">
                    <NcTextField :value.sync="year"
                        class="field"
                        :label="t('memories', 'Year')" :label-visible="true"
                        :placeholder="t('memories', 'Year')" />
                    <NcTextField :value.sync="month"
                        class="field"
                        :label="t('memories', 'Month')" :label-visible="true"
                        :placeholder="t('memories', 'Month')" />
                    <NcTextField :value.sync="day"
                        class="field"
                        :label="t('memories', 'Day')" :label-visible="true"
                        :placeholder="t('memories', 'Day')" />
                    <NcTextField :value.sync="hour"
                        class="field"
                        :label="t('memories', 'Time')" :label-visible="true"
                        :placeholder="t('memories', 'Hour')" />
                    <NcTextField :value.sync="minute"
                        class="field"
                        :label="t('memories', 'Minute')"
                        :placeholder="t('memories', 'Minute')" />
                </div>

                <div class="buttons">
                    <NcButton @click="save" class="button" type="primary">
                        {{ t('memories', 'Save') }}
                    </NcButton>
                </div>
            </div>
        </div>
    </NcModal>
</template>

<script lang="ts">
import { Component, Mixins } from 'vue-property-decorator';
import GlobalMixin from '../mixins/GlobalMixin';
import { IPhoto } from '../types';

import { NcButton, NcModal, NcTextField } from '@nextcloud/vue';
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import * as utils from '../services/Utils';

const INFO_API_URL = '/apps/memories/api/info/{id}';
const EDIT_API_URL = '/apps/memories/api/edit/{id}';

@Component({
    components: {
        NcButton,
        NcModal,
        NcTextField,
    }
})
export default class EditDate extends Mixins(GlobalMixin) {
    private photos: IPhoto[] = [];

    private longDateStr: string = '';
    private year: string = "0";
    private month: string = "0";
    private day: string = "0";
    private hour: string = "0";
    private minute: string = "0";
    private second: string = "0";

    public async open(photos: IPhoto[]) {
        this.photos = photos;
        if (photos.length === 0) {
            return;
        }

        const res = await axios.get<any>(generateUrl(INFO_API_URL, { id: this.photos[0].fileid }));
        if (typeof res.data.datetaken !== "string") {
            console.error("Invalid date");
            return;
        }

        const utcEpoch = Date.parse(res.data.datetaken + " UTC");
        const date = new Date(utcEpoch);
        this.year = date.getUTCFullYear().toString();
        this.month = (date.getUTCMonth() + 1).toString();
        this.day = date.getUTCDate().toString();
        this.hour = date.getUTCHours().toString();
        this.minute = date.getUTCMinutes().toString();
        this.second = date.getUTCSeconds().toString();

        this.longDateStr = utils.getLongDateStr(date, false, true);
    }

    public close() {
        this.photos = [];
    }

    public async save() {
        // Pad zeros to the left
        this.year = this.year.padStart(4, '0');
        this.month = this.month.padStart(2, '0');
        this.day = this.day.padStart(2, '0');
        this.hour = this.hour.padStart(2, '0');
        this.minute = this.minute.padStart(2, '0');
        this.second = this.second.padStart(2, '0');

        // Make PATCH request to update date
        try {
            const res = await axios.patch<any>(generateUrl(EDIT_API_URL, { id: this.photos[0].fileid }), {
                date: `${this.year}:${this.month}:${this.day} ${this.hour}:${this.minute}:${this.second}`,
            });
            this.close();
        } catch (e) {
            if (e.response?.data?.message) {
                showError(e.response.data.message);
            }
        }
    }
}
</script>

<style scoped lang="scss">
.container {
	margin: 20px;

    .head {
        font-weight: 500;
    }
}

.fields {
    margin-top: 5px;
    .field {
        width: 4.1em;
        display: inline-block;
    }
}

.buttons {
    margin-top: 10px;
    text-align: right;

    button {
        display: inline-block;
    }
}
</style>