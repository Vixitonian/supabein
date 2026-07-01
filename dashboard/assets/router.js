'use strict';

const Router = (() => {
  const routes = [];

  function add(pattern, handler) {
    // Convert :param segments to named groups
    const re = new RegExp(
      '^' + pattern.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, '(?<$1>[^/]+)') + '$'
    );
    routes.push({ re, handler });
  }

  function dispatch(hash) {
    const path = hash.replace(/^#\/?/, '') || '';
    for (const route of routes) {
      const m = path.match(route.re);
      if (m) {
        route.handler(m.groups || {});
        return;
      }
    }
    // Fallback
    render404();
  }

  function navigate(hash) {
    window.location.hash = hash;
  }

  function init() {
    // Note: an empty hash already routes to Home (see the '' route), so
    // going back past the app's first in-app entry lands on Home for free —
    // no extra history bookkeeping needed here.
    window.addEventListener('hashchange', () => dispatch(window.location.hash));
    dispatch(window.location.hash);
  }

  return { add, dispatch, navigate, init };
})();
