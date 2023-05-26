// DO NOT import this file anywhere, it clears all caches on load.
// Imported as a hook from BeforeTemplateListener.php

(async function () {
  const keys = (await window.caches?.keys()) ?? [];
  for (const key of keys) {
    if (key.match(/^memories-/)) {
      window.caches.delete(key);
    }
  }
})();
