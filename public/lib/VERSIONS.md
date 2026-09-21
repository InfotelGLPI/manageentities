# Third-party libraries shipped in `public/lib`

The plugin ships its front-end dependencies as pre-built files instead of resolving
them through npm: there is no JavaScript build step, and nothing in `node_modules`
is ever served.

`package.json` and `package-lock.json` at the root of the plugin pin the versions
listed below so that `npm audit` and Dependabot can see them. Installing them produces
no artefact the plugin uses -- they exist for the tooling, not for the build. Run
`npm run audit` to confront the shipped versions with the upstream advisories. Both
manifests currently declare no dependency at all: the single library still vendored is
the one that cannot be pinned, described in the next section.

This file stays the reference for what is actually vendored: **every time a library
below is added, upgraded or removed, update the matching row, then the manifest**.

| Path | Library | Version | Licence | Upstream |
| --- | --- | --- | --- | --- |
| `jquery-gantt.js`, `jquery-gantt.css`, `jquery-gantt/img/`, `jquery-plugins/img/` | jQuery Gantt Chart (taitems) | unknown, see below | MIT | https://github.com/taitems/jQuery.Gantt |

## The one library that cannot be pinned

`jquery-gantt.js` is a webpack bundle of **taitems/jQuery.Gantt**, carrying no version
marker. That project is unmaintained (last release 2016) and is **not on npm**.

The npm package named `jquery.gantt` is a different project entirely
(`oguzhanoya/jquery-gantt`): its source shares none of the identifiers of the file we
ship (`UTC_DAY_IN_MS`, `scrollToToday`, `waitToggle`, the `:findday` selector). Pinning
it would aim the security tooling at the wrong codebase, which is worse than the gap it
appears to close. The dependency is therefore tracked here and here only.

## Notes on what is shipped

`jquery-ui/` was removed, together with the npm dependency that pinned it. The directory
shipped a full 1.14.2 build (336 KB) that no PHP, Twig or JS file ever registered, so the
widget was never on the page. Three functions of `scripts-manageentities.js` called the
`dialog` widget regardless -- `showFormAddPDFContract()`, `showDialog()` and
`alertCreateEntity()` -- and all three were unreachable: the markup of the first went away
with `src/AddElementsView.php` in the wizard rewrite (commit `c686c1c`), and the two others
never had any. They are gone. `InterventionStakeholder::showMessage()` was the one live
caller; it now builds a core Bootstrap modal through `glpi_alert()`, which
`Html::includeHeader()` loads on every page. Core bundles only six low-level jQuery UI
modules (`lib/bundles/base.js`) and never `dialog`, so every one of those call sites was
raising `$(...).dialog is not a function`.

Two files went with jQuery UI, both left without a single referent once the dead dialogs
were removed. `public/scripts/jquery.form.js` was a vendored copy of the jQuery Form
plugin (44 KB, no version marker, carrying the same stale manageentities GPL header as
the libraries above) registered on every central page; its only use was the `.ajaxForm()`
binding on the form whose markup no longer exists. `ajax/updateDocumentList.php` only
ever rendered the button that opened that dialog. Note that `public/scripts/` is not
covered by this file: it holds plugin code, and jQuery Form was the last third-party
library hiding there.

`echarts/` was removed: the plugin used to vendor its own Apache ECharts 6.1.0 build
(plus 34 themes) and register it through `ADD_JAVASCRIPT`, while GLPI core already ships
ECharts at `public/lib/echarts.js` and `DirectHelpdesk::showDashboard()` already asked
for it with `Html::requireJs('charts')`. Both were loaded on every page, and because
plugin scripts are emitted after core's in `page_footer.html.twig`, the plugin copy won
the `window.echarts` global -- core's own charts ran on a version core does not ship.
The gauges use no API newer than 5.x, so they now run on the core bundle. The themes
went with it: the gauges init with a `null` theme and never referenced `azul`.

`jquery-gantt/img/` and `jquery-plugins/img/` hold the same eleven sprites. Only the
`jquery-plugins/` copy is used, by `Gantt.php`.

## Licence headers

The file listed above (jQuery Gantt) carries a stale manageentities GPL header, prepended
years ago by a licence-header run that did not exclude this directory. It does **not**
describe it: the licence that applies is the one in the table above.
`tools/regenerate_headers.php` excludes `public/lib` (along with `vendor`,
`node_modules`, `lib`, `dist` and `var`), so the situation cannot get worse; fixing the
rest is a job for the upgrade that re-downloads that library.
