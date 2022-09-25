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

            <div v-if="longDateStr">

                <span v-if="photos.length > 1">
                    [{{ t('memories', 'Newest') }}]
                </span>
                {{ longDateStr }}

                <div class="fields memories__editdate__fields">
                    <NcTextField :value.sync="year"
                        class="field"
                        @input="newestChange()"
                        :label="t('memories', 'Year')" :label-visible="true"
                        :placeholder="t('memories', 'Year')" />
                    <NcTextField :value.sync="month"
                        class="field"
                        @input="newestChange()"
                        :label="t('memories', 'Month')" :label-visible="true"
                        :placeholder="t('memories', 'Month')" />
                    <NcTextField :value.sync="day"
                        class="field"
                        @input="newestChange()"
                        :label="t('memories', 'Day')" :label-visible="true"
                        :placeholder="t('memories', 'Day')" />
                    <NcTextField :value.sync="hour"
                        class="field"
                        @input="newestChange(true)"
                        :label="t('memories', 'Time')" :label-visible="true"
                        :placeholder="t('memories', 'Hour')" />
                    <NcTextField :value.sync="minute"
                        class="field"
                        @input="newestChange(true)"
                        :label="t('memories', 'Minute')"
                        :placeholder="t('memories', 'Minute')" />
                </div>

                <div v-if="photos.length > 1" class="oldest">
                    <span>
                        [{{ t('memories', 'Oldest') }}]
                    </span>
                    {{ longDateStrLast }}

                    <div class="fields memories__editdate__fields">
                        <NcTextField :value.sync="yearLast"
                            class="field"
                            :label="t('memories', 'Year')" :label-visible="true"
                            :placeholder="t('memories', 'Year')" />
                        <NcTextField :value.sync="monthLast"
                            class="field"
                            :label="t('memories', 'Month')" :label-visible="true"
                            :placeholder="t('memories', 'Month')" />
                        <NcTextField :value.sync="dayLast"
                            class="field"
                            :label="t('memories', 'Day')" :label-visible="true"
                            :placeholder="t('memories', 'Day')" />
                        <NcTextField :value.sync="hourLast"
                            class="field"
                            :label="t('memories', 'Time')" :label-visible="true"
                            :placeholder="t('memories', 'Hour')" />
                        <NcTextField :value.sync="minuteLast"
                            class="field"
                            :label="t('memories', 'Minute')"
                            :placeholder="t('memories', 'Minute')" />
                    </div>
                </div>

                <div v-if="processing">
                    {{ t('memories', 'Processing ... {n}/{m}', {
                        n: photosDone,
                        m: photos.length,
                    }) }}
                </div>

                <div class="buttons">
                    <NcButton @click="save" class="button" type="primary">
                        {{ t('memories', 'Save') }}
                    </NcButton>
                </div>
            </div>

            <div v-else>
                {{ t('memories', 'Loading data ... {n}/{m}', {
                    n: photosDone,
                    m: photos.length,
                }) }}
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
import * as dav from "../services/DavRequests";

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
    private photosDone: number = 0;
    private processing: boolean = false;

    private longDateStr: string = '';
    private year: string = "0";
    private month: string = "0";
    private day: string = "0";
    private hour: string = "0";
    private minute: string = "0";
    private second: string = "0";

    private longDateStrLast: string = '';
    private yearLast: string = "0";
    private monthLast: string = "0";
    private dayLast: string = "0";
    private hourLast: string = "0";
    private minuteLast: string = "0";
    private secondLast: string = "0";

    public async open(photos: IPhoto[]) {
        this.photos = photos;
        if (photos.length === 0) {
            return;
        }
        this.photosDone = 0;
        this.longDateStr = '';

        const calls = photos.map((p) => async () => {
            try {
                const res = await axios.get<any>(generateUrl(INFO_API_URL, { id: p.fileid }));
                if (typeof res.data.datetaken !== "string") {
                    console.error("Invalid date for", p.fileid);
                    return;
                }
                p.datetaken = Date.parse(res.data.datetaken + " UTC");
            } catch (error) {
                console.error('Failed to get date info for', p.fileid, error);
            } finally {
                this.photosDone++;
            }
        });

        for await (const _ of dav.runInParallel(calls, 10)) {
            // nothing to do
        }

        // Remove photos without datetaken
        this.photos = this.photos.filter((p) => p.datetaken !== undefined);

        // Sort photos by datetaken descending
        this.photos.sort((a, b) => b.datetaken - a.datetaken);

        // Get date of newest photo
        let date = new Date(this.photos[0].datetaken);
        this.year = date.getUTCFullYear().toString();
        this.month = (date.getUTCMonth() + 1).toString();
        this.day = date.getUTCDate().toString();
        this.hour = date.getUTCHours().toString();
        this.minute = date.getUTCMinutes().toString();
        this.second = date.getUTCSeconds().toString();
        this.longDateStr = utils.getLongDateStr(date, false, true);

        // Get date of oldest photo
        if (this.photos.length > 1) {
            date = new Date(this.photos[this.photos.length - 1].datetaken);
            this.yearLast = date.getUTCFullYear().toString();
            this.monthLast = (date.getUTCMonth() + 1).toString();
            this.dayLast = date.getUTCDate().toString();
            this.hourLast = date.getUTCHours().toString();
            this.minuteLast = date.getUTCMinutes().toString();
            this.secondLast = date.getUTCSeconds().toString();
            this.longDateStrLast = utils.getLongDateStr(date, false, true);
        }
    }

    public newestChange(time=false) {
        if (this.photos.length === 0) {
            return;
        }

        // Set the last date to have the same offset to newest date
        try {
            const date = new Date(this.photos[0].datetaken);
            const dateLast = new Date(this.photos[this.photos.length - 1].datetaken);

            const dateNew = this.getDate();
            const offset = dateNew.getTime() - date.getTime();
            const dateLastNew = new Date(dateLast.getTime() + offset);

            this.yearLast = dateLastNew.getUTCFullYear().toString();
            this.monthLast = (dateLastNew.getUTCMonth() + 1).toString();
            this.dayLast = dateLastNew.getUTCDate().toString();

            if (time) {
                this.hourLast = dateLastNew.getUTCHours().toString();
                this.minuteLast = dateLastNew.getUTCMinutes().toString();
                this.secondLast = dateLastNew.getUTCSeconds().toString();
            }
        } catch (error) {}
    }

    public close() {
        this.photos = [];
    }

    public async saveOne() {
        // Make PATCH request to update date
        try {
            this.processing = true;
            const res = await axios.patch<any>(generateUrl(EDIT_API_URL, { id: this.photos[0].fileid }), {
                date: this.getExifFormat(this.getDate()),
            });
            this.$emit('refresh', true);
            this.close();
        } catch (e) {
            if (e.response?.data?.message) {
                showError(e.response.data.message);
            }
        } finally {
            this.processing = false;
        }
    }

    public async saveMany() {
        if (this.processing) {
            return;
        }

        // Get difference between newest and oldest date
        const date = new Date(this.photos[0].datetaken);
        const dateLast = new Date(this.photos[this.photos.length - 1].datetaken);
        const diff = date.getTime() - dateLast.getTime();

        // Get new difference between newest and oldest date
        const dateNew = this.getDate();
        const dateLastNew = this.getDateLast();
        const diffNew = dateNew.getTime() - dateLastNew.getTime();

        // Validate if the old is still old
        if (diffNew < 0) {
            showError("The newest date must be newer than the oldest date");
            return;
        }

        // Mark processing
        this.processing = true;
        this.photosDone = 0;

        // Create PATCH requests
        const calls = this.photos.map((p) => async () => {
            try {
                const pDate = new Date(p.datetaken);
                const offset = date.getTime() - pDate.getTime();
                const pDateNew = new Date(dateNew.getTime() - offset * (diffNew / diff));
                const res = await axios.patch<any>(generateUrl(EDIT_API_URL, { id: p.fileid }), {
                    date: this.getExifFormat(pDateNew),
                });
            } catch (e) {
                if (e.response?.data?.message) {
                    showError(e.response.data.message);
                }
            } finally {
                this.photosDone++;
            }
        });

        for await (const _ of dav.runInParallel(calls, 10)) {
            // nothing to do
        }
        this.processing = false;
        this.$emit('refresh', true);
        this.close();
    }

    public async save() {
        if (this.photos.length === 0) {
            return;
        }

        if (this.photos.length === 1) {
            return await this.saveOne();
        }

        return await this.saveMany();
    }

    private getExifFormat(date: Date) {
        const year = date.getUTCFullYear().toString().padStart(4, "0");
        const month = (date.getUTCMonth() + 1).toString().padStart(2, "0");
        const day = date.getUTCDate().toString().padStart(2, "0");
        const hour = date.getUTCHours().toString().padStart(2, "0");
        const minute = date.getUTCMinutes().toString().padStart(2, "0");
        const second = date.getUTCSeconds().toString().padStart(2, "0");
        return `${year}:${month}:${day} ${hour}:${minute}:${second}`;
    }

    public getDate() {
        const dateNew = new Date();
        dateNew.setUTCFullYear(parseInt(this.year));
        dateNew.setUTCMonth(parseInt(this.month) - 1);
        dateNew.setUTCDate(parseInt(this.day));
        dateNew.setUTCHours(parseInt(this.hour));
        dateNew.setUTCMinutes(parseInt(this.minute));
        dateNew.setUTCSeconds(parseInt(this.second));
        return dateNew;
    }

    public getDateLast() {
        const dateLast = new Date();
        dateLast.setUTCFullYear(parseInt(this.yearLast));
        dateLast.setUTCMonth(parseInt(this.monthLast) - 1);
        dateLast.setUTCDate(parseInt(this.dayLast));
        dateLast.setUTCHours(parseInt(this.hourLast));
        dateLast.setUTCMinutes(parseInt(this.minuteLast));
        dateLast.setUTCSeconds(parseInt(this.secondLast));
        return dateLast;
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
    .field {
        width: 4.1em;
        display: inline-block;
    }
}

.oldest {
    margin-top: 10px;
}

.buttons {
    margin-top: 10px;
    text-align: right;

    button {
        display: inline-block;
    }
}
</style>

<style lang="scss">
.memories__editdate__fields label {
    font-size: 0.8em;
    padding: 0 !important;
    padding-left: 3px !important;
}
</style>