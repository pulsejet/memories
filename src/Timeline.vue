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
            <div v-else class="user">
                {{ item.name }}
            </div>
        </RecycleScroller>
    </div>
</template>

<script>
export default {
    data() {
        const list = [];
        for (let i = 0; i < 1000; i++) {
            list.push({
                id: i,
                name: 'bla' + i,
                size: 32,
            })
        }
        return {
            list: list,
            count: list.length,
        }
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
    },
}
</script>

<style scoped>
.scroller {
    height: 300px;
}

.user {
    height: 32px;
}
.head {
    height: 64px;
    font-size: 20px;
    font-weight: bold;
}
</style>