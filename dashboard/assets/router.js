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
    window.addEventListener('hashchange', () => dispatch(window.location.hash));
    dispatch(window.location.hash);
  }

  return { add, dispatch, navigate, init };
})();
