import { onScopeDispose, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { windowDims } from '@services/viewport';
import { routes } from '@services/router';

import type { Ref } from 'vue';

/** Enable route transitions. */
export function useRouteTransition(): Ref<string> {
  const route = useRoute();
  const routeTransition = ref('route-initial');

  let awaitingInitialNavigation = route.matched.length === 0;
  let lastHistoryPosition: number | null = historyPosition();

  // URL of the navigation the UA already animated (e.g. back swipe), if any.
  let uaTransitionedUrl: string | null = null;
  const onNavigate = (event: NavigateEvent) => {
    uaTransitionedUrl = null;
    if (event.hasUAVisualTransition) {
      uaTransitionedUrl = event.destination.url;
    }
  };
  window.navigation?.addEventListener('navigate', onNavigate);
  onScopeDispose(() => window.navigation?.removeEventListener('navigate', onNavigate));

  watch(
    [() => route.name, () => route.path, () => windowDims.isMobile],
    ([toName, toPath], fromVal) => {
      const fromName = fromVal?.[0];
      const fromPath = fromVal?.[1];
      if (fromPath === undefined) return; // watcher setup run

      // Check if viewport toggled without navigation
      if (toPath === fromPath && toName === fromName) {
        routeTransition.value = 'route-fade';
        return;
      }

      try {
        // UA already animated this navigation, don't double-animate.
        const uaUrl = uaTransitionedUrl;
        uaTransitionedUrl = null;
        if (uaUrl && uaUrl === window.location.href) {
          routeTransition.value = 'route-initial';
          return;
        }

        // Never animate first paint.
        if (awaitingInitialNavigation) {
          awaitingInitialNavigation = false;
          routeTransition.value = 'route-initial';
          return;
        }

        // Resolve specific transition for this change.
        routeTransition.value = resolveTransition(
          toName?.toString(),
          toPath,
          fromName?.toString(),
          fromPath,
          lastHistoryPosition,
        );
      } finally {
        // Store the history position for next navigation.
        lastHistoryPosition = historyPosition() ?? lastHistoryPosition;
      }
    },
    { immediate: true },
  );

  return routeTransition;
}

/** Get the transition for a particular interaction. */
function resolveTransition(
  toName: string | undefined,
  toPath: string,
  fromName: string | undefined,
  fromPath: string,
  lastHistoryPosition: number | null,
): string {
  // Always fade on desktop, large views sliding is jarring.
  if (!windowDims.isMobile) return 'route-fade';

  // Tab routes on mobile, fade and slightly rise.
  const tabRoutes: string[] = [routes.Base.name, routes.Explore.name, routes.AlbumsList.name];
  const isTabRoute = (name: string | undefined) => !!name && tabRoutes.includes(name);
  const toTab = isTabRoute(toName);
  const fromTab = isTabRoute(fromName);
  if (toTab && fromTab) return 'route-tab';
  if (!toTab && fromTab) return 'route-push';
  if (toTab && !fromTab) return 'route-pop';

  // Slide navigation depending on history position.
  const pos = historyPosition();
  const wentBack = pos !== null && lastHistoryPosition !== null && pos < lastHistoryPosition;

  // Nested routes like folders can push to a parent path
  const wentParent = fromPath.startsWith(`${toPath}/`);

  return wentBack || wentParent ? 'route-pop' : 'route-push';
}

/** Get the current history position. */
function historyPosition(): number | null {
  const pos = window.history.state?.position;
  return typeof pos === 'number' ? pos : null;
}
