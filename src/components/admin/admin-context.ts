import { computed, inject, provide, type InjectionKey, type Ref } from 'vue';

import type { ISystemConfig, ISystemStatus } from './AdminTypes';

/** Updater provided by AdminMain, implemented as PUT to SYSTEM_CONFIG. */
type AdminUpdateFn = <K extends keyof ISystemConfig>(key: K, value?: ISystemConfig[K] | null) => void | Promise<void>;

/** Shared admin state injected into all sections. */
type AdminContext = {
  status: Ref<ISystemStatus | null>;
  systemConfig: Ref<ISystemConfig | null>;
  update: AdminUpdateFn;
};

/** Injection key for the shared admin state; provided by AdminMain. */
const adminContextKey: InjectionKey<AdminContext> = Symbol('memories-admin-context');

/** Provide admin context; must be called synchronously in AdminMain setup. */
export function provideAdminContext(context: AdminContext) {
  provide(adminContextKey, context);
}

/** Shared logic for admin sections. */
export function useAdminContext() {
  const ctx = inject(adminContextKey);
  if (!ctx) {
    throw new Error('useAdminContext() must be used within AdminMain');
  }

  return {
    status: ctx.status,
    systemConfig: ctx.systemConfig,
    update: ctx.update,
    enableTranscoding: computed({
      get: () => !ctx.systemConfig.value?.['memories.vod.disable'],
      set: (value: boolean) => {
        if (ctx.systemConfig.value) {
          ctx.systemConfig.value['memories.vod.disable'] = !value;
        }
      },
    }),
  };
}
