# Third-party libraries shipped in `public/lib`

The plugin ships its front-end dependencies as pre-built files instead of resolving
them through npm: there is no JavaScript build step, and nothing in `node_modules`
is ever served.

`package.json` and `package-lock.json` at the root of the plugin pin the versions
listed below so that `npm audit` and Dependabot can see them. Installing them produces
no artefact the plugin uses -- they exist for the tooling, not for the build.

**Nothing is vendored here any more.** Every front-end library the plugin draws on is
now the one GLPI core already ships under its own `public/lib/`, which is versioned,
audited and upgraded with the core. This file stays the reference for that state:
**if a library is ever added back, add its row below and pin it in the manifest**.

| Path | Library | Version | Licence | Upstream |
| --- | --- | --- | --- | --- |
| _(none)_ | | | | |

## Notes on what was removed

`jquery-gantt.js`, `jquery-gantt.css`, `jquery-gantt/img/` and `jquery-plugins/img/`
were a webpack bundle of **taitems/jQuery.Gantt** (100 KB, unmaintained since 2016)
plus two identical copies of eleven sprites. The bundle carried no version marker and
is **not on npm**: the package named `jquery.gantt` there is a different project
(`oguzhanoya/jquery-gantt`), sharing none of the identifiers of the file we shipped
(`UTC_DAY_IN_MS`, `scrollToToday`, `waitToggle`, the `:findday` selector), so pinning it
would have aimed the security tooling at the wrong codebase. It could therefore never be
tracked by anything but this file.

`Gantt::showGantt()` now builds the chart with the FullCalendar bundle of the core
(`public/lib/fullcalendar.js`), in its `resourceTimeline` view: one parent row per
contract, one child row per contract day. Core already ships the library, its GPL
scheduler licence key and its locales, so the migration added no dependency at all. It
also closed an HTML injection sink: the removed library built its rows by concatenating
the strings it was handed, which is why `getDataToDisplayOnGantt()` used to pre-escape
them and glue them together with `<br/>`; FullCalendar escapes resource and event titles
itself, so the two collectors now return plain text and the tooltip is set through
`setAttribute('title', ...)`. The eleven sprites went with it: the share of consumed
credit is painted by a CSS gradient in `public/scripts/gantt.js`.

`public/style.css` went with the library: its 86 rules were the stock jQuery.Gantt
theme (`.fn-gantt`, `.gantt2`, `.fn-gantt-hint`, `.fn-gantt-loader`, the sprite
offsets), none of which any PHP, Twig or JS file of the plugin ever emitted once the
chart moved. It was registered on every central page through `ADD_CSS`; that entry is
gone from `setup.php`, and what the new chart needs sits with the rest of the plugin
styles in `public/manageentities.css`, where the dead `.fn-gantt` rule was replaced by
the `.manageentities-gantt` ones.

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

## Loading a core library from an AJAX tab

Both remaining call sites -- the DirectHelpdesk gauges and the GANTT tab -- render inside
the response of `ajax/common.tabs.php`, which emits no page footer, so
`Html::requireJs()` is silently dropped there. They solve it differently, and on purpose:

* `public/scripts/directhelpdesk-gauges.js` is registered through `ADD_JAVASCRIPT`, so it
  runs at page load, before the tab exists. It injects `lib/echarts.js` itself and waits
  for the container with a `MutationObserver`.
* `Gantt::showGantt()` echoes `lib/fullcalendar.css`, `lib/fullcalendar.js`, the locale
  file and `scripts/gantt.js` inside the tab response. jQuery `.html()` inserts the
  container first and then evaluates the scripts in document order, `<script src>`
  included, so no observer is needed.

## Licence headers

`tools/regenerate_headers.php` excludes `public/lib` (along with `vendor`,
`node_modules`, `lib`, `dist` and `var`), so a library added back here will not be
stamped with the manageentities GPL header the way the removed ones had been.
