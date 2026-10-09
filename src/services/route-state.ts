import { lru } from 'tiny-lru';
import { getCurrentInstance, nextTick, onScopeDispose, ref, toRaw, toValue, watch } from 'vue';
import type { Ref, ShallowRef } from 'vue';
import { useRoute, type RouteLocationNormalized } from 'vue-router';

/** Template refs may contain a component, a native element, or no mounted target. */
type ScrollTarget = VueHTMLComponent | HTMLElement | null | undefined;

/** State that can be stored */
type InnerState = Record<string, Ref<unknown>>;
type InnerStateVals<T extends InnerState> = {
  [K in keyof T]: NoInfer<T[K]['value']>;
};

/** State and named scroll containers belonging to one component on a route. */
type RouteState<T extends InnerState> = {
  /** Stable namespace; defaults to the component's explicit or inferred SFC name. */
  instance?: string;
  /** Route identity; defaults to path and query without the hash. */
  key?: (route: RouteLocationNormalized) => string;
  /** Named refs whose raw values are cloned and saved on navigation. */
  state?: T;
  /** Named shallow template refs whose scroll offsets are saved and restored after the state. */
  scroll?: Record<string, ShallowRef<ScrollTarget>>;
};

/** The shared cache holds snapshots from composable instances with different T types. */
type Snapshot = {
  state?: Record<string, unknown>;
  scroll?: Record<string, Pick<HTMLElement, 'scrollTop' | 'scrollLeft'>>;
};

// Keep snapshots across component remounts, evicting the least recently used entries.
const states = lru<Snapshot>(500);

// Flag on start of key to disable.
const FLAG_DISABLE = '__disable_persistence__';

/** Resolve the scrolling element, ignoring unmounted refs and non-HTML component roots. */
function getElement(target: ScrollTarget): HTMLElement | null {
  if (!target) return null;
  const el = '$el' in target ? target.$el : target;
  return el instanceof HTMLElement ? el : null;
}

/** Deep-clone the state inside the route state object */
function cloneState<T extends InnerState>(state: T | undefined) {
  if (state === undefined) return undefined;
  return Object.fromEntries(
    Object.entries(state).map(([name, ref]) => {
      return [name, structuredClone(toRaw(toValue(ref)))];
    }),
  ) as InnerStateVals<T>;
}

/** Compute the key to use for storing the state */
export function routerStatePath(route: RouteLocationNormalized) {
  return route.fullPath.split('#')[0];
}

/** Wrapper for key to disable state persistence */
export function disableRouterStatePersistence(key: string): string {
  return `${FLAG_DISABLE}/${key}`;
}

/**
 * Restore refs during setup and on route changes, using defaults for missing keys.
 * After one render tick, restore the mounted scroll targets to their saved offsets.
 * Navigation guards save the current ref values and scroll offsets before leaving the route.
 * Use an explicit instance when multiple copies of the same component share a route.
 * Returns a restoring ref, true until state and scroll offsets have been applied.
 */
export function useRouteState<T extends InnerState>(options: RouteState<T>) {
  const route = useRoute();
  const restoring = ref(false);
  const component = getCurrentInstance()?.type;
  const instance = options.instance ?? component?.name ?? component?.__name;

  // Clone the initial values of the state for defaults.
  const defaults = cloneState(options.state);

  // Queries distinguish views; viewer hashes share the underlying page's state.
  const key = (route: RouteLocationNormalized) => {
    const k = options.key ? options.key(route) : routerStatePath(route);
    return `${k}#${instance}`;
  };

  /** Capture ref values and mounted scroll targets before route-driven watchers run. */
  function preserve(routeKey: string) {
    // Check if explicitly disabled.
    if (routeKey.includes(FLAG_DISABLE)) return;

    // Preserve old offsets for gone targets (v-if).
    const scroll = states.get(routeKey)?.scroll ?? {};

    // Capture the current offsets of mounted targets.
    for (const [name, ref] of Object.entries(options.scroll ?? {})) {
      const element = getElement(ref.value);
      if (element) {
        scroll[name] = {
          scrollTop: element.scrollTop,
          scrollLeft: element.scrollLeft,
        };
      }
    }

    // Preserve copies of the state refs values.
    states.set(routeKey, {
      state: cloneState(options.state),
      scroll: scroll,
    });
  }

  // Restore the state after the route changes.
  watch(
    () => key(route),
    async (routeKey, oldKey, onCleanup) => {
      // Cancel pending scrolling if route changes.
      let active = true;
      onCleanup(() => (active = false));
      restoring.value = true;

      try {
        // Save the old route's state; guards miss reused instances.
        if (oldKey && oldKey !== routeKey) preserve(oldKey);

        // Get the saved state for this route and instance.
        const saved = states.get(routeKey);

        // Restore the saved state values, or defaults.
        if (options.state) {
          for (const [name, ref] of Object.entries(options.state)) {
            if (saved && Object.hasOwn(saved.state ?? {}, name)) {
              ref.value = structuredClone(saved.state![name]);
            } else if (defaults) {
              ref.value = structuredClone(defaults[name]);
            }
          }
        }

        // Restore the saved scroll offsets.
        if (options.scroll) {
          // Wait for the components to render.
          await nextTick();
          if (!active || routeKey !== key(route)) return;

          // Restore scroll positions of mounted elements.
          for (const [name, ref] of Object.entries(options.scroll)) {
            const element = getElement(ref.value);
            if (element) {
              element.scrollTop = saved?.scroll?.[name]?.scrollTop ?? 0;
              element.scrollLeft = saved?.scroll?.[name]?.scrollLeft ?? 0;
            }
          }
        }
      } finally {
        if (active) {
          restoring.value = false;
        }
      }
    },
    { immediate: true, flush: 'sync' },
  );

  onScopeDispose(() => (restoring.value = false));

  return { restoring };
}
