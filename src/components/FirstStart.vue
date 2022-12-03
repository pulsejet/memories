<template>
  <NcContent app-name="memories">
    <NcAppContent>
      <div class="outer fill-block" :class="{ show }">
        <div class="title">
          <img :src="banner" />
        </div>

        <div class="text">
          {{ t("memories", "A better photos experience awaits you") }} <br />
          {{
            t("memories", "Choose the root folder of your timeline to begin")
          }}
        </div>

        <div class="admin-text" v-if="isAdmin">
          {{ t("memories", "If you just installed Memories, run:") }}
          <br />
          <code>occ memories:index</code>
        </div>

        <div class="error" v-if="error">
          {{ error }}
        </div>

        <div class="info" v-if="info">
          {{ info }} <br />

          <NcButton @click="finish" class="button" type="primary">
            {{ t("memories", "Continue to Memories") }}
          </NcButton>
        </div>

        <NcButton @click="begin" class="button" v-if="info">
          {{ t("memories", "Choose again") }}
        </NcButton>
        <NcButton @click="begin" class="button" type="primary" v-else>
          {{ t("memories", "Click here to start") }}
        </NcButton>

        <div class="footer">
          {{ t("memories", "You can always change this later in settings") }}
        </div>
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script lang="ts">
import { Component, Mixins } from "vue-property-decorator";

import NcContent from "@nextcloud/vue/dist/Components/NcContent";
import NcAppContent from "@nextcloud/vue/dist/Components/NcAppContent";
import NcButton from "@nextcloud/vue/dist/Components/NcButton";

import { getFilePickerBuilder } from "@nextcloud/dialogs";
import { getCurrentUser } from "@nextcloud/auth";
import axios from "@nextcloud/axios";

import GlobalMixin from "../mixins/GlobalMixin";
import UserConfig from "../mixins/UserConfig";

import banner from "../assets/banner.svg";
import { IDay } from "../types";
import { API } from "../services/API";

@Component({
  components: {
    NcContent,
    NcAppContent,
    NcButton,
  },
})
export default class FirstStart extends Mixins(GlobalMixin, UserConfig) {
  banner = banner;
  error = "";
  info = "";
  show = false;
  chosenPath = "";

  mounted() {
    window.setTimeout(() => {
      this.show = true;
    }, 300);
  }

  get isAdmin() {
    return getCurrentUser().isAdmin;
  }

  async begin() {
    const path = await this.chooseFolder(
      this.t("memories", "Choose the root of your timeline"),
      "/"
    );

    // Get folder days
    this.error = "";
    this.info = "";
    const query = new URLSearchParams();
    query.set("timelinePath", path);
    let url = API.Q(API.DAYS(), query);
    const res = await axios.get<IDay[]>(url);

    // Check response
    if (res.status !== 200) {
      this.error = this.t(
        "memories",
        "The selected folder does not seem to be valid. Try again."
      );
      return;
    }

    // Count total photos
    const n = res.data.reduce((acc, day) => acc + day.count, 0);
    this.info = this.n(
      "memories",
      "Found {n} item in {path}",
      "Found {n} items in {path}",
      n,
      {
        n,
        path,
      }
    );
    this.chosenPath = path;
  }

  async finish() {
    this.show = false;
    await new Promise((resolve) => setTimeout(resolve, 500));
    this.config_timelinePath = this.chosenPath;
    await this.updateSetting("timelinePath");
  }

  async chooseFolder(title: string, initial: string) {
    const picker = getFilePickerBuilder(title)
      .setMultiSelect(false)
      .setModal(true)
      .setType(1)
      .addMimeTypeFilter("httpd/unix-directory")
      .allowDirectories()
      .startAt(initial)
      .build();

    return await picker.pick();
  }
}
</script>

<style lang="scss" scoped>
.outer {
  padding: 20px;
  text-align: center;

  transition: opacity 1s ease;
  opacity: 0;
  &.show {
    opacity: 1;
  }

  .title {
    font-size: 2.8em;
    line-height: 1.1em;
    font-family: cursive;
    font-weight: 500;
    margin-top: 10px;
    margin-bottom: 20px;
    width: 100%;
    filter: var(--background-invert-if-dark);

    > img {
      max-width: calc(100vw - 40px);
    }
  }

  .admin-text {
    margin-top: 10px;
  }

  .error {
    color: red;
  }

  .info {
    margin-top: 10px;
    font-weight: bold;
  }

  .button {
    display: inline-block;
    margin: 15px;
  }

  .footer {
    font-size: 0.8em;
  }
}
</style>