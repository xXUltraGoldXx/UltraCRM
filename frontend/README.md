# UltraCRM — frontend

Vue 3 single-page app that talks to the Symfony API in [`../api`](../api).
See the [project README](../README.md) for what the product does and how to
set the whole thing up.

```bash
npm install
npm run dev      # dev server
npm run build    # production build into dist/
```

Serve `dist/` as the document root and route `/api` to the backend.

## How it is organised

```
src/
  views/         one file per screen, routed in router/index.js
  components/    shared building blocks
    ui/          the design system: UiButton, UiCard, UiSheet, …
  composables/   reusable behaviour (management sheets, delete confirmation)
  stores/        Pinia stores; auth.js holds the JWT and the permission check
  labels.js      German UI wording in one place
  format.js      shared date, number and currency formatters
  assets/app.css design tokens — colours, spacing, type scale
```

## Conventions worth knowing

**The UI language is German, the code language is English.** Every string a
user sees lives in the template or in `labels.js` and stays German. Comments,
identifiers and file names are English.

**Design tokens, not literal values.** Colours, spacing and radii come from
the custom properties in `assets/app.css` (`--sp-*`, `--text-*`, `--accent`,
`--separator`, `--radius-*`). A hard-coded pixel or colour value in a
component breaks the dark and light themes.

**`:deep()` only works inside `<style scoped>`.** In global CSS it silently
does nothing — which once cost an afternoon of confusion.

**Touch targets are at least 44px.** The app is used on phones; anything
smaller is not reliably hittable.

**Permissions decide what is rendered.** `auth.darf('contacts.manage')` gates
actions, and the navigation filters itself the same way, so nobody is offered
a button that answers with 403.
