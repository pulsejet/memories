import { bus } from './event-bus';
import { onDOMLoaded } from './helpers';

/** Mapping of route name to key type */
enum FragmentType {
  viewer = 'v',
  selection = 's',
  modal = 'm',
  sidebar = 'i',
  editor = 'e',
  settings = 'ss',
  dialog = 'd',
}

/** Names of fragments */
export type FragmentName = keyof typeof FragmentType;

/** Data structure to encode to fragment */
export type Fragment = {
  type: FragmentType;
  args: string[];
  index?: number;
};

/**
 * Decode fragments from string.
 * @param hash Hash string
 */
function decodeFragment(hash: string): Fragment[] {
  return hash
    .substring(1) // remove # at start
    .split('&') // get all parts
    .filter(Boolean) // remove empty parts
    .map((frag, i, arr) => {
      const values = frag?.split('/');
      return {
        type: (values?.[0] ?? 'u') as FragmentType,
        args: values?.slice(1) ?? [],
        index: arr.length - i - 1,
      };
    });
}

/**
 * Encode fragments to string.
 * @param fragments Fragments to encode
 */
function encodeFragment(fragments: Fragment[]): string {
  if (!fragments.length) return '';
  return '#' + fragments.map((frag) => [frag.type, ...frag.args].join('/')).join('&');
}

/**
 * Cache for route fragments
 */
const cache = {
  hash: String(),
  list: [] as Fragment[],
};

export const fragment = {
  /**
   * List of all fragment types.
   */
  types: FragmentType,

  /**
   * Get list of all fragments in route.
   * @returns List of fragments
   */
  get list(): Fragment[] {
    if (cache.hash !== _m.route.hash) {
      cache.hash = _m.route.hash;
      cache.list = decodeFragment(cache.hash ?? String());
    }

    return [...cache.list];
  },

  /**
   * Check if route has this fragment type.
   * @param type Fragment identifier
   */
  get(type: FragmentType) {
    return this.list.find((frag) => frag.type === type);
  },

  /**
   * Add fragment to route.
   * @param frag Fragment to add to route
   */
  async push(type: FragmentType, ...args: string[]) {
    // Skip if no route (e.g. admin)
    if (!_m.route) return;

    const frag: Fragment = { type, args };
    const list = this.list;

    // Get the top fragment
    const top = list.length ? list[list.length - 1] : null;

    // Check if we are already on this fragment
    if (top?.type === frag.type) {
      // Replace the arguments
      top.args = frag.args;
      const hash = encodeFragment(list);

      // Avoid redundant route changes
      if (hash === _m.route.hash) return;

      // Replace the route with the new fragment
      await _m.router.replace({
        path: _m.route.path,
        query: _m.route.query,
        hash: hash,
      });

      return;
    }

    // If the fragment is already in the list, we can't touch it.
    if (list.find((f) => f.type === frag.type)) {
      return;
    }

    // Add fragment to route
    list.push(frag);
    await _m.router.push({
      path: _m.route.path,
      query: _m.route.query,
      hash: encodeFragment(list),
    });

    // wait for the route to change
    await new Promise((resolve) => setTimeout(resolve, 0));
  },

  /**
   * Remove the top fragment from route.
   * @param type Fragment identifier
   */
  async pop(type: FragmentType) {
    // Skip if no route (e.g. admin)
    if (!_m.route) return;

    // Get the index of this fragment from the end
    const frag = this.get(type);
    if (!frag) return;

    // Go back in history
    _m.router.go(-frag.index! - 1);

    // Check if the fragment still exists
    // In that case, replace the route to remove the fragment
    const sfrag = this.get(type);
    if (sfrag) {
      await _m.router.replace({
        path: _m.route.path,
        query: _m.route.query,
        hash: encodeFragment(this.list.slice(0, -sfrag.index! - 1)),
      });
    }

    // wait for the route to change
    await new Promise((resolve) => setTimeout(resolve, 0));
  },

  /**
   * Sync a fragment with a boolean condition.
   */
  async if(condition: boolean, type: FragmentType, ...args: string[]) {
    if (condition) await this.push(type, ...args);
    else await this.pop(type);
  },

  /**
   * Wrap a promise as a route fragment.
   * Pushes a fragment before running the promise and
   * pops it *before* the promise resolves.
   */
  async wrap<T>(promise: Promise<T>, type: FragmentType, ...args: string[]): Promise<T> {
    await this.push(type, ...args);
    try {
      const res = await promise;
      await this.pop(type);
      return res;
    } catch (e) {
      await this.pop(type);
      throw e;
    }
  },

  get viewer() {
    return this.get(FragmentType.viewer);
  },

  encode: encodeFragment,
};

onDOMLoaded(() => {
  // Skip unless in user mode
  if (_m.mode !== 'user') return;

  // On first load, we must remove any fragments and
  // push them back in, so that history.back() works.
  // The back button will still take the user back to
  // the previous page but this is fine.
  if (fragment.list.length) {
    const contextual = fragment.list.filter((frag) => frag.type === FragmentType.viewer);

    // Remove the currently present fragments
    _m.router.replace({
      path: _m.route.path,
      query: _m.route.query,
    });

    // Only contextual fragments should be present on page load
    if (contextual.length) {
      _m.router.push({
        path: _m.route.path,
        query: _m.route.query,
        hash: encodeFragment(contextual),
      });
    }
  }

  /**
   * Trigger when route changes; notify listeners of popped fragments.
   * @param to Switching to this route
   * @param from Switching from this route
   */
  _m.router.afterEach((to, from) => {
    const toF = decodeFragment(to.hash);
    const fromF = decodeFragment(from.hash);

    // Emit events for popped fragments
    fromF
      .filter((frag) => !toF.find((f) => f.type === frag.type))
      .forEach((frag) => {
        for (const [key, type] of Object.entries(FragmentType)) {
          const name = key as FragmentName;
          if (type === frag.type) {
            bus.emit(`memories:fragment:pop:${name}`, frag);
            break;
          }
        }
      });
  });
});
