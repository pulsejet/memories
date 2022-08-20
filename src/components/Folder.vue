<template>
    <div class="folder"
        @click="openFolder(data.file_id)"
        v-bind:style="{
            width: rowHeight + 'px',
            height: rowHeight + 'px',
        }">
        <div class="icon-folder icon-dark"></div>
        <div class="name">{{ data.name }}</div>
    </div>
</template>

<script>

export default {
    name: 'Folder',
    props: {
        data: {
            type: Object,
            required: true
        },
        rowHeight: {
            type: Number,
            required: true,
        }
    },
    methods: {
        /** Open album folder */
        openFolder(id) {
            this.$router.push({ name: 'albums', params: { id } });
        },
    }
}
</script>

<style>
.folder {
    cursor: pointer;
}
.folder .name {
    cursor: pointer;
    width: 100%;
    text-align: center;
}
.icon-folder {
    cursor: pointer;
    background-size: 40%;
    height: 60%; width: 100%;
    background-position: bottom;
    opacity: 0.3;
}
</style>