import type { Route } from 'vue-router';
import * as utils from './utils';

/** Mapping of route name to key type */
export enum FragmentType {
  viewer = 'v',
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
    .filter((frag) => frag) // remove empty parts
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

export default {
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
  push(frag: Fragment) {
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
      _m.router.replace({
        path: _m.route.path,
        query: _m.route.query,
        hash: hash,
      });

      return;
    }

    // If the fragment is already in the list,
    // we can't touch it. This should never happen.
    if (list.find((f) => f.type === frag.type)) {
      console.error('[BUG] Fragment already in route', frag.type);
    }

    // Add fragment to route
    list.push(frag);
    _m.router.push({
      path: _m.route.path,
      query: _m.route.query,
      hash: encodeFragment(list),
    });
  },

  /**
   * Remove the top fragment from route.
   * @param type Fragment identifier
   */
  pop(type: FragmentType) {
    // Get the index of this fragment from the end
    const frag = this.get(type);
    if (!frag) return;

    // Go back in history
    _m.router.go(-frag.index! - 1);

    // Check if the fragment still exists
    // In that case, replace the route to remove the fragment
    const sfrag = this.get(type);
    if (sfrag) {
      _m.router.replace({
        path: _m.route.path,
        query: _m.route.query,
        hash: encodeFragment(this.list.slice(0, -sfrag.index! - 1)),
      });
    }
  },

  get viewer() {
    return this.get(FragmentType.viewer);
  },

  changeTrigger(to: Route, from: Route) {
    const toF = decodeFragment(to.hash);
    const fromF = decodeFragment(from.hash);

    // Emit events for popped fragments
    fromF
      .filter((frag) => !toF.find((f) => f.type === frag.type))
      .forEach((frag) => {
        for (const [key, type] of Object.entries(FragmentType)) {
          const name = key as FragmentName;
          if (type === frag.type) {
            utils.bus.emit(`memories:fragment:pop:${name}`, frag);
            break;
          }
        }
      });
  },
};
