import { computed, type ComputedRef } from 'vue';

export function useWindowWidth(): ComputedRef<number> {
  return computed(() => _m.window.innerWidth);
}

export function useWindowHeight(): ComputedRef<number> {
  return computed(() => _m.window.innerHeight);
}

export function useWindowWidthIsMobile(): ComputedRef<boolean> {
  return computed(() => _m.window.isMobile);
}

export function useWindowDims(): ComputedRef<{ width: number; height: number }> {
  return computed(() => ({
    width: _m.window.innerWidth,
    height: _m.window.innerHeight,
  }));
}
