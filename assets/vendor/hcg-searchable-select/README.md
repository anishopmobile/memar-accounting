# Locally pinned HCG Searchable Select

- Upstream: https://github.com/html-code-generator/hcg-searchable-select
- Version: **1.0.4** (MIT)
- Upstream commit: `081f8e76033b600cc761af849bef8b1075f20722`
- Files: `hcg-select.js` (unmodified), `hcg-select.css` (unmodified), `LICENSE`
- Upstream source tree snapshots are pinned and checked into this repository; no CDN or runtime fetch is used.

Integration (separate, project-owned files):
- `assets/js/hcg-select-integration.js`: enhance all native single-select controls in both static and AJAX-generated DOM; cleanup detached controls, preserve native input/change, values, forms, and disabled-state behavior.
- `assets/css/hcg-select-integration.css`: scoped RTL/Estedad styling for toolbars, modals, invoice rows, settings and payment form UI.

**Compatibility notes:** Original JavaScript deliberately does not replace `<select multiple>` or multi-row `<select size="...">`: these remain native because the upstream library does not support them. The source contains text-escaping for highlighted options. It watches dynamic option list updates and exposes `refresh()`. The original native element is the value submitted to PHP.

Runtime selectors are transformed visually only. No changes to financial calculation, product data, payment routing or server-side security logic.
