<template>
    <div>
        <button @click="genList()">oka.</button><br/>
        <RecycleScroller
            class="scroller"
            :items="list"
            size-field="size"
            key-field="id"
            v-slot="{ item }"
            :emit-update="true"
            @update="scrollChange"
        >
            <h1 v-if="item.head" class="head">Vue is awesome!</h1>
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
            ncols: 5,
        }
    },

    mounted() {
        this.fetchList();
    },

    methods: {
        genList() {
            this.count++;
            this.list[0].name = 'bloop' + this.count;
            return;
            this.list.splice(50, 0, {
                id: this.count,
                name: 'bla' + this.count,
                size: 64,
                head: true,
            })
        },

        scrollChange(startIndex, endIndex) {
            console.log('scrollChange', startIndex, endIndex);
        },

        async fetchList() {
            const res = await fetch('/apps/betterphotos/api/list');
            const data = await res.json();
            const nrows = Math.ceil(data.length / this.ncols) + 5;

            this.list.push({
                id: -1,
                size: 64,
                head: true,
            });

            // Add n rows to the list
            for (let i = 0; i < nrows; i++) {
                this.list.push({
                    id: i,
                    photos: [],
                    size: 100,
                })
            }

            const headIdx = 0;
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
.scroller {
    height: 300px;
}

.photo {
    height: 100px;
}
.photo img {
    height: 100px;
    width: 100px;
    object-fit: cover;
}
.head {
    height: 64px;
    font-size: 20px;
    font-weight: bold;
}
</style>