<template>
    <div class="container" ref="container">
        <RecycleScroller
            ref="scroller"
            class="scroller"
            :items="list"
            size-field="size"
            key-field="id"
            v-slot="{ item }"
            :emit-update="true"
            @update="scrollChange"
        >
            <h1 v-if="item.head" class="head">
                {{ item.name }}
            </h1>
            <div v-else class="photo">
                <img v-for="img of item.photos" :src="img.src" :key="img.file_id" />
            </div>
        </RecycleScroller>
    </div>
</template>

<script>
export default {
    data() {
        return {
            list: [],
            count: 0,
            nrows: 0,
            ncols: 5,
            heads: {},

            currentStart: 0,
            currentEnd: 0,
        }
    },

    mounted() {
        this.handleResize();
        this.fetchDays();
    },

    methods: {
        handleResize() {
            let height = this.$refs.container.clientHeight;
            this.$refs.scroller.$el.style.height = (height - 4) + 'px';
        },

        scrollChange(startIndex, endIndex) {
            if (startIndex === this.currentStart && endIndex === this.currentEnd) {
                return;
            }

            this.currentStart = startIndex;
            this.currentEnd = endIndex;
            setTimeout(() => {
                if (this.currentStart === startIndex && this.currentEnd === endIndex) {
                    this.loadChanges(startIndex, endIndex);
                }
            }, 300);
        },

        loadChanges(startIndex, endIndex) {
            for (let i = startIndex; i <= endIndex; i++) {
                let item = this.list[i];
                if (!item) {
                    continue;
                }

                let head = this.heads[item.dayId];
                if (head && !head.loaded) {
                    head.loaded = true;
                    this.fetchDay(item.dayId);
                }
            }
        },

        async fetchDays() {
            const res = await fetch('/apps/betterphotos/api/days');
            const data = await res.json();

            for (const day of data) {
                // Nothing here
                if (day.count === 0) {
                    continue;
                }

                // Make date string
                const dateTaken = new Date(Number(day.day_id)*86400*1000);
                let dateStr = dateTaken.toLocaleDateString("en-US", { dateStyle: 'full', timeZone: 'UTC' });
                if (dateTaken.getUTCFullYear() === new Date().getUTCFullYear()) {
                    // hack: remove last 6 characters of date string
                    dateStr = dateStr.substring(0, dateStr.length - 6);
                }

                // Add header to list
                const head = {
                    id: ++this.nrows,
                    name: dateStr,
                    size: 60,
                    head: true,
                    loaded: false,
                    dayId: day.day_id,
                };
                this.heads[day.day_id] = head;
                this.list.push(head);

                // Add rows
                const nrows = Math.ceil(day.count / this.ncols);
                for (let i = 0; i < nrows; i++) {
                    this.list.push({
                        id: ++this.nrows,
                        photos: [],
                        size: 100,
                        dayId: day.day_id,
                    });
                }
            }
        },

        async fetchDay(dayId) {
            const head = this.heads[dayId];
            head.loaded = true;

            let data = [];
            try {
                const res = await fetch(`/apps/betterphotos/api/days/${dayId}`);
                data = await res.json();
            } catch (e) {
                console.error(e);
                head.loaded = false;
            }

            // Get index of header O(n)
            const headIdx = this.list.findIndex(item => item.id === head.id);
            let rowIdx = headIdx + 1;

            // Add all rows
            for (const p of data) {
                // Check if we ran out of rows
                if (rowIdx >= this.list.length || this.list[rowIdx].head) {
                    this.list.splice(rowIdx, 0, {
                        id: rowIdx,
                        photos: [],
                        size: 100,
                    });
                }

                // Go to the next row
                if (this.list[rowIdx].photos.length >= this.ncols) {
                    rowIdx++;
                }

                // Add the photo to the row
                this.list[rowIdx].photos.push({
                    id: p.file_id,
                    src: `/core/preview?fileId=${p.file_id}&x=250&y=250`,
                });
            }

            // Get rid of any extra rows
            let spliceCount = 0;
            for (let i = rowIdx + 1; i < this.list.length && !this.list[i].head; i++) {
                spliceCount++;
            }
            if (spliceCount > 0) {
                this.list.splice(rowIdx + 1, spliceCount);
            }
        },
    },
}
</script>

<style scoped>
.container {
    height: 100%;
}

.scroller {
    height: 300px;
    width: 100%;
}

.photo {
    height: 100px;
}
.photo img {
    height: 100px;
    width: 100px;
    object-fit: cover;
    padding: 2px;
}
.head {
    height: 60px;
    padding-top: 25px;
    font-size: 20px;
    font-weight: lighter;
}
</style>