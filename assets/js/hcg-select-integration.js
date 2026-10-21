/* Memar Accounting: global, progressive HCG v1.0.4 integration.
 * Operates on the existing native selects (including AJAX forms); does not
 * change their values, form serialization, CSRF, event handlers or requests.
 */
(function () {
  'use strict';
  if (!window.hcgSelect) return;

  var enhanced = new Set();
  var queued = false;
  var root = document.documentElement;

  function ensure(select) {
    if (!select || select.tagName !== 'SELECT' || select.multiple ||
        select.size > 1 || select.hasAttribute('data-hcg-exclude') ||
        select.closest('[data-hcg-exclude]')) return;
    if (select._hcgApi) {
      enhanced.add(select);
      return;
    }
    if (!select.isConnected) return;

    var first = select.options.length ? select.options[0] : null;
    var firstIsEmpty = !!(first && first.value === '');
    var hint = firstIsEmpty ? first.text : 'انتخاب کنید';
    var api = window.hcgSelect(select, {
      placeholder: hint,
      searchPlaceholder: 'جستجو در گزینه‌ها...',
      noResultsText: 'موردی پیدا نشد',
      clearable: firstIsEmpty
    });
    if (!api) return;
    enhanced.add(select);
    api.element.classList.add('memar-searchable-select');
    // Keep RTL text legible without modifying the MIT-licensed vendor code.
    api.element.setAttribute('dir', 'rtl');
    var clear = api.element.querySelector('.hcg-select-clear');
    if (clear) clear.setAttribute('aria-label', 'پاک کردن انتخاب');

    // Native external label -> custom accessible control is handled by HCG.
    // Respect original title if the select had no explicit <label>.
    var control = api.element.querySelector('.hcg-select-control');
    if (select.title && control) control.title = select.title;
  }

  function scan() {
    queued = false;
    // Destroy observers on removed selects after the original AJAX mutation
    // has settled. This prevents leaks when invoice/payment rows are rebuilt.
    enhanced.forEach(function (select) {
      if (!select.isConnected) {
        try { if (select._hcgApi) select._hcgApi.destroy(); }
        catch (e) { console.warn('Select cleanup:', e); }
        enhanced.delete(select);
      }
    });
    document.querySelectorAll('select').forEach(ensure);
  }

  function schedule() {
    if (queued) return;
    queued = true;
    if (window.requestAnimationFrame) window.requestAnimationFrame(scan);
    else window.setTimeout(scan, 0);
  }

  // Runtime population via jQuery.html()/empty()/append(), form row injection,
  // dynamic invoice units etc. is transparently enhanced and stays native.
  var observer = new MutationObserver(function (changes) {
    for (var i = 0; i < changes.length; i++) {
      if (changes[i].type === 'childList') { schedule(); return; }
    }
  });

  function start() {
    scan();
    observer.observe(root, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();

  window.MemarSelects = {
    refresh: function (scope) {
      (scope && scope.querySelectorAll ? scope : document)
        .querySelectorAll('select').forEach(function (select) {
          ensure(select);
          if (select._hcgApi) select._hcgApi.refresh();
        });
    },
    count: function () { return enhanced.size; }
  };
})();
