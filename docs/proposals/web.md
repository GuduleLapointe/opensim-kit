# Basic web: a placeholder site and `/helper`

## Need

A grid needs a web side from the first day: a landing page (the `welcome` and `DestinationGuide` URLs of Robust point to the web URL) and the scripts of opensim-helpers, served at `{weburl}/helpers/` by default (`currency.php`, `query.php`, `register.php`, `offline.php`, `guide.php`, ...); the helpers are those **of the grid**: configured with its credentials and its connectors, nothing is shared between grids. The default path is `/helpers`: the current documentation of opensim-helpers (`CURRENCY_HELPER_URL`, the registrars of `config.example.php`) and the older template of `libexec/newgrid` say `/helpers`, and the convention does not change from one to the other. The path is one variable (`HELPERS_PATH`) in the snippets and the setup, so `/helper`, the historical name of the currency helper, is a setting away.

## Known

- **opensim-helpers** (3.0.x): every script starts with `require_once 'includes/config.php'`, a PHP file of `define()` constants made by hand from `includes/config.example.php` (`OPENSIM_GRID_NAME`, `OPENSIM_LOGIN_URI`, the credentials of the databases `OPENSIM_DB_*`, `SEARCH_DB_*`, `CURRENCY_DB_*`, the mail sender...). It does not read `/etc/opensim`.
- **The kit** has all of this already, in its files: the name and nick of the grid (`GridInfoService`), its web URL (`[Const] WebURL`), the ports, the credentials of the Robust database (`DatabaseService ConnectionString`), the locations of the config, data and logs from `opensim.conf` (`EtcRoot`, `DataRoot`...).
- **opensim-engine** already has `Engine_Settings` (the one place of settings of the PHP libraries) and `class-ini.php`; opensim-helpers requires it.
- The former `opensim-manfredaabye-helpers` package (removed) showed how a webroot is packaged: files in `/usr/share/<package>`, settings in `/etc/opensim/<package>`, and snippets for nginx and apache that `Alias` a path to the webroot (see its files in the history of `packaging/`).
- Other implementations of the helpers exist (w4os for WordPress, the fork of Kevin Cozens and Manfred Aabye, wiredux): all follow the protocols of OpenSimulator and its viewers, so they are interchangeable in principle. The kit does not package them: the operator chooses `opensim-helpers` or a third-party solution, and the web URLs of the setup are only URLs.
- The sim and Robust configs the setup writes have the web URLs commented (`;economy`, `;search`, `;message`, `DATA_SRV_<Grid>`), none active.

## Proposed

### 1. Configuration of the helpers from the kit

- **Where**: in opensim-engine (`Engine_Settings`), so every project that uses the engine (w4os, the helpers) can use it: a loader `Engine_Settings::from_opensim_kit()` that finds the profile in `opensim.conf` (`OPENSIM_CONF` or `/etc/opensim/opensim.conf`, then the profile and the grid asked by `OPENSIM_GRID`, default: the only enabled grid), reads the Robust ini (with `[Const]` expansion, a reader shared with `config-import.md`) and answers the settings the helpers ask for.
- **opensim-helpers** `includes/config.php`: if the file exists as today, it wins (nothing breaks); otherwise the constants are defined from the loader (`OPENSIM_GRID_NAME`, `OPENSIM_LOGIN_URI`, `OPENSIM_DB_*` from the Robust connection string, `SEARCH_DB_*` and `CURRENCY_DB_*` from a `[Helpers]` section of the grid when it has one, the Robust database otherwise).
- **Rights**: a web server user (`www-data`) cannot, and should not, read `Robust.ini` with all its credentials. The setup writes `/etc/opensim/grids/<nick>/helpers.ini` (mode 640, group of the web server) with only what the helpers need (the database accounts of the helpers, the URLs, the mail sender), and the loader reads that file. The profile gives its path (`HelpersConfig`).

### 2. The packages

- `opensim-helpers` (new package): the webroot of the library in `/usr/share/opensim-helpers` (the `classes`, `includes`, scripts, `vendor` of composer with the engine and the REST library), no secret; snippets for Caddy, nginx and apache2 in `/usr/share/doc/opensim-helpers/` and, installed but not enabled, in `/etc/opensim/web/`; `Suggests` the web server, `Depends` the PHP extensions of the helpers.
- `opensim-web` (new package): the placeholder site (below), as a webroot in `/usr/share/opensim-web/html`, a snippet that serves it and includes the one of the helpers (`/helper`). The metapackage `opensim-kit` may `Recommends` it.
- The snippets alias `/helper` (a variable at the top, `HELPER_PATH`, to change it) to the webroot of the package, deny the folders that are not for the web, and run PHP through `php-fpm`, as the existing search snippets do.

### 3. The placeholder site

- A static page (one `index.html`, no build step, light and dark theme) with the name of the grid, how to connect (the login URI, the viewers' grid manager entry, Hypergrid address), the status (`/helper/` `get_grid_info` or the `GridStatus` URL), and where the helpers are; the grid name and URI come from a small generated `site.json` (the setup rewrites it when the grid changes), so the page is not a template to edit by hand.
- The setup offers, when a grid is made, to write the web URLs in the config: `[GridInfoService] welcome` and `economy` (`{weburl}/helpers`, the viewer adds the name of the script) and `[LoginService] SearchURL` (`{weburl}/helpers/query.php`, the API of the in-world search; the search page of the web site is another script, not in opensim-helpers yet; there is no `message` URL: `offline.php` is for offline messages, not for the message of the day), the simulator `[Economy]`, `[Search]`, `[Messaging]`, `DATA_SRV_<Grid>` (`{weburl}/helpers/register.php`), active only when the helpers are installed (the check is the presence of the package or of a URL that answers).

### 4. A command

`opensim web` shows the URLs (`{weburl}/helper/...`), the snippet to include for the web server in use (detected: caddy, nginx, apache2), whether the helpers answer, and (`--enable`) installs the snippet when it can do it safely (a file in the conf.d folder, a reload), never rewriting the config of the server.

## Order of work

1. engine: `Engine_Settings::from_opensim_kit()` and its tests (a temporary tree, as `NextTest` does).
2. helpers: `includes/config.php` that falls back on it, tests with the example tree.
3. kit: `helpers.ini` written by the setup (new questions only for what is missing), the web URLs in the config of the grid and of the simulators.
4. packaging: `opensim-helpers`, `opensim-web`, the snippets (the build for the three libraries is an apt-repo job: not something the cloud session can publish).
5. `opensim web`.

## Decided

- The helpers are specific to each grid (its credentials and its connectors): one `helpers.ini` per grid, never one for the machine. A search shared between grids (another project of the author, 2do Directory) will change only a part of the settings of the helpers, which stay those of the grid; it is supported later, not now.
- No package for another implementation of the helpers; no clash to avoid with a package of the fork, which is gone.

## To decide

- The path, `/helpers` or `/helper`: `/helpers` as in the documentation, `HELPERS_PATH` makes it a setting.
- What the helpers do on a machine with several grids (`OPENSIM_GRID` set by the virtual host).
- A placeholder site per grid or one per machine.
