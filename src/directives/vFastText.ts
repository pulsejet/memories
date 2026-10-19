import type { Directive } from 'vue';

interface FastTextElement extends HTMLElement {
  __fastTextNode?: Text;
}

// Fast text updates: the element stays static (no interpolation/slots),
// so Vue never re-patches its children. Text changes mutate the Text
// node in place via Text.data, which is much faster than setting
// textContent (no child teardown/recreation). beforeUpdate only writes
// when the value actually changed.
export const vFastText: Directive<FastTextElement, string> = {
  created(el, binding) {
    const textNode = document.createTextNode(binding.value ?? '');
    el.__fastTextNode = textNode;
    el.appendChild(textNode);
  },
  beforeUpdate(el, binding) {
    if (binding.value !== binding.oldValue && el.__fastTextNode) {
      el.__fastTextNode.data = binding.value ?? '';
    }
  },
  unmounted(el) {
    delete el.__fastTextNode;
  },
};
