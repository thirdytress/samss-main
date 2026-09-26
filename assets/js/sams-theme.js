(function () {
  'use strict';

  var storageKey = 'sams-theme';
  var savedTheme = localStorage.getItem(storageKey);
  var preferredTheme = savedTheme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-sams-theme', theme);
    localStorage.setItem(storageKey, theme);
    document.querySelectorAll('.sams-theme-toggle').forEach(function (button) {
      var dark = theme === 'dark';
      button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      button.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
      button.innerHTML = dark
        ? '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 15.3A8.5 8.5 0 0 1 8.7 4 8.5 8.5 0 1 0 20 15.3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    });
  }

  function addToggle() {
    if (document.querySelector('.sams-theme-toggle')) return;
    var host = document.querySelector('.topbar__right, .topbar__actions, .topbar__user, .topbar__user-actions');
    if (!host) host = document.body;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'sams-theme-toggle';
    button.addEventListener('click', function () {
      applyTheme(document.documentElement.getAttribute('data-sams-theme') === 'dark' ? 'light' : 'dark');
    });
    host.insertBefore(button, host.firstChild || null);
    applyTheme(preferredTheme);
  }

  applyTheme(preferredTheme);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addToggle);
  } else {
    addToggle();
  }
})();
