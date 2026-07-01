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

  function currentPath() {
    return (window.location.hash.replace(/^#\/?/, '') || '');
  }

  function init() {
    // Tag every entry we land on so we can tell "back within the app" apart
    // from "back past the app boundary" (e.g. arriving via a deep link, or
    // reload) in the popstate handler below.
    let lastPath = currentPath();
    history.replaceState({ sbApp: true }, '', window.location.href);

    window.addEventListener('hashchange', () => {
      history.replaceState({ sbApp: true }, '', window.location.href);
      lastPath = currentPath();
      dispatch(window.location.hash);
    });

    window.addEventListener('popstate', (e) => {
      if (e.state && e.state.sbApp) return; // normal back/forward within the app
      const wasHome = lastPath === '' || lastPath === 'home';
      if (wasHome) return; // already at the root — let it exit naturally
      history.pushState({ sbApp: true }, '', '#/home');
      lastPath = 'home';
      dispatch('#/home');
    });

    dispatch(window.location.hash);
  }

  return { add, dispatch, navigate, init };
})();
