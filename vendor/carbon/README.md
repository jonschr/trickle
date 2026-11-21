# Carbon date picker

IBM Carbon Web Components 2.68.0, bundled locally with only the date picker and its input. Apache-2.0; see LICENSE, THIRD_PARTY_NOTICES.txt and date-picker.min.js.LEGAL.txt for bundled dependency notices. Source: https://github.com/carbon-design-system/carbon/tree/main/packages/web-components

Rebuild from the repository root (Node/npm required only for rebuilding):

```sh
build_dir="$(mktemp -d)"
npm install --prefix "$build_dir" --ignore-scripts --no-audit --no-fund @carbon/web-components@2.68.0 esbuild@0.28.2
NODE_PATH="$build_dir/node_modules" "$build_dir/node_modules/.bin/esbuild" vendor/carbon/entry.js --bundle --minify --format=iife --target=es2020 --legal-comments=linked --outfile=vendor/carbon/date-picker.min.js
```

`assets/media-dates.js` adapts Carbon's date-change event to the existing UI and makes the input fit narrow panels. The two independent calendar bounds retain the plugin's support for open-ended UTC date ranges. Carbon formats the displayed date as MM/DD/YYYY; the picker value stays YYYY-MM-DD.

Table sorting also uses the arrow--up, arrow--down and arrows--vertical SVG paths from @carbon/icons 11.92.0, embedded in assets/admin.js under the same Apache-2.0 license.
