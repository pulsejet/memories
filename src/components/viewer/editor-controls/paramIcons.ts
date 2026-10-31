import type { Component } from 'vue';
import AngleAcute from 'vue-material-design-icons/AngleAcute.vue';
import Blur from 'vue-material-design-icons/Blur.vue';
import Brightness from 'vue-material-design-icons/WhiteBalanceSunny.vue';
import CameraIris from 'vue-material-design-icons/CameraIris.vue';
import Contrast from 'vue-material-design-icons/Contrast.vue';
import Highlights from 'vue-material-design-icons/WeatherSunny.vue';
import Hue from 'vue-material-design-icons/InvertColors.vue';
import Focus from 'vue-material-design-icons/ImageFilterCenterFocus.vue';
import Palette from 'vue-material-design-icons/Palette.vue';
import PaletteSwatch from 'vue-material-design-icons/PaletteSwatch.vue';
import Shadows from 'vue-material-design-icons/WeatherNight.vue';
import Thermometer from 'vue-material-design-icons/Thermometer.vue';

/**
 * Param key → Material Design icon component.
 * Used by the data-driven slider loops (sidebar + mobile strip) so new
 * sliders only touch `types.ts` plus one entry here.
 */
export const PARAM_ICONS: Record<string, Component> = {
  brightness: Brightness,
  exposure: CameraIris,
  contrast: Contrast,
  highlights: Highlights,
  shadows: Shadows,
  saturation: Palette,
  vibrance: PaletteSwatch,
  hue: Hue,
  temperature: Thermometer,
  blur: Blur,
  sharpen: Focus,
  straighten: AngleAcute,
};

export function iconFor(key: string): Component | undefined {
  return PARAM_ICONS[key];
}
