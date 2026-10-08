import { ref } from 'vue';

/** Renewing timer that resets when set again. */
export class RenewingTimeout {
  private timer = ref(0);

  /** Whether a timer is currently pending */
  get pending(): boolean {
    return this.timer.value !== 0;
  }

  set(callback: (() => void) | null, delay: number, immediate?: boolean) {
    // Call immediately if no timeout exists
    if (immediate && !this.timer.value) {
      callback?.();
      callback = null;
    }

    // Clear existing timeout and set a new one
    if (this.timer.value) window.clearTimeout(this.timer.value);
    this.timer.value = window.setTimeout(() => {
      this.timer.value = 0;
      callback?.();
    }, delay);
  }

  clear() {
    if (this.timer.value) window.clearTimeout(this.timer.value);
    this.timer.value = 0;
  }
}
