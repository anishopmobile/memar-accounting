/* Small, framework-independent mobile navigation controller.
   Bootstrap/Tabler modal data API is not activated for legacy modals. */
(function () {
  'use strict';
  var body = document.body;
  var open = document.getElementById('sidebarOpen');
  var close = document.getElementById('sidebarClose');
  var sidebar = document.getElementById('appSidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!body || !open || !close || !sidebar || !backdrop) return;
  var compact = window.matchMedia('(max-width: 1024px)');
  var previousFocus = null;

  function setOpen(value) {
    value = !!value && compact.matches;
    if (value) previousFocus = document.activeElement;
    body.classList.toggle('sidebar-open', value);
    open.setAttribute('aria-expanded', String(value));
    sidebar.setAttribute('aria-hidden', String(!value && compact.matches));
    backdrop.setAttribute('aria-hidden', String(!value));
    if (value) {
      close.focus();
    } else if (previousFocus && previousFocus.focus) {
      previousFocus.focus();
      previousFocus = null;
    }
  }
  open.addEventListener('click', function () { setOpen(true); });
  close.addEventListener('click', function () { setOpen(false); });
  backdrop.addEventListener('click', function () { setOpen(false); });
  sidebar.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    if (link && !link.classList.contains('nav-dropdown-toggle')) setOpen(false);
  });
  document.addEventListener('keydown', function (event) {
    if (!body.classList.contains('sidebar-open')) return;
    if (event.key === 'Escape') { setOpen(false); return; }
    if (event.key !== 'Tab') return;
    var items = Array.prototype.slice.call(sidebar.querySelectorAll('a[href],button:not([disabled])'))
      .filter(function (el) { return el.getClientRects().length > 0; });
    if (!items.length) return;
    if (event.shiftKey && document.activeElement === items[0]) {
      event.preventDefault(); items[items.length - 1].focus();
    } else if (!event.shiftKey && document.activeElement === items[items.length - 1]) {
      event.preventDefault(); items[0].focus();
    }
  });
  function updateMode() {
    if (!compact.matches) {
      if (body.classList.contains('sidebar-open')) setOpen(false);
      sidebar.removeAttribute('aria-hidden');
    } else sidebar.setAttribute('aria-hidden', String(!body.classList.contains('sidebar-open')));
  }
  if (compact.addEventListener) compact.addEventListener('change', updateMode);
  else compact.addListener(updateMode);
  updateMode();
}());
