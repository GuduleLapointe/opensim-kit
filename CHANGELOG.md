## Changelog

### Unreleased

- new: `dev/switch.sh` and `dev/release.sh`, the steps of a release
- fix: web server files are complete, for the site of the web URL: to include, or to use alone
- new: `--keep N` on `opensim backup` and `opensim save iar|oar` removes the older ones
- new: `dev/build.sh` makes the Debian packages and the zip of the tools
- new: `BUILD_HOST` builds OpenSimulator on another machine
- update: helpers, engine and rest-php are packages of their projects, the tools depend on them
- update: the helpers read the config of the grid, no `config.php` from the kit
- new: tests of the packages run on the podman of another machine (`CONTAINER_CONNECTION`)
- fix: nginx hands a script that is not a file to the router
- fix: `opensim save|load iar|oar [instance]`, the instance is a grid, a simulator or a region
- fix: the public port sets the first center of a grid only, its regions give it afterwards
- fix: `opensim stop` skips the NPCs on a screen console
- fix: unreadable `helpers.ini` is reported by the helpers instead of a 500
- fix: tests run on macOS (temporary folder, tar)

### 3.0.0-beta.3

- new: `opensim save|load iar|oar`
- new: `opensim backup [GRID [SIM]] [--logs] [--archives] [--output DIR]`
- new: quick setup, one screen, other settings by default
- new: `opensim setup --file FILE`, taking full config from a JSON or YAML file
- new: web server examples (Caddy, nginx, Apache) in `/etc/opensim/grids/<grid>/web/`
- new `opensim oar pack|info|check|unpack` from sources in `share/ossl-scripts/fix-parcel-name-src`

- update: `opensim import [grid] FILE` handles quick setup, full install and bulk import
- refactor: commands share one form, `opensim <command> <instance> [action] [options]`
- feat: terminal shortcuts in the setup (Escape, Ctrl-Q, Ctrl-U, Ctrl-K, Ctrl-W...)
- feat: import accounts can be given with their password hash and salt
- update(setup): password read from config if db user matches one already known
- update(setup): advanced setup groups related questions
- fix: name slugs as snake_case do avoid possible conflicts
- fix: two grids no longer share a map position (first location from the public port)
- fix: a second simulator reuses the database password of the same user
- fix: the search index of a simulator carries its grid name, not OSGrid
- fix: the owner account is made when it has no email
- fix: economy url passed to the viewer (pass base path, not currency.php)
- fix: disable query.php API endpoint was passed for search URL instead of proper search url
- fix: disable offline.php was passed as message of the day url
- fix: first account is optional (OpenSimulator does not require one)
- fix: adding a region no longer asks for a restart, its parcel is no longer renamed by one
- fix: `opensim stop` counts down to the shutdown, not to the next warning
- update `opensim stop` does not wait when no real user is in the regions
- add OSSL is in the standard config
- new `opensim profile list|add|default|remove`
- new: gettext localization of the setup, catalogue in `locales/opensim-kit.pot`
- new `locales/update-pot` script to refresh translations from `.po` files
- new: `opensim import robust|sim FILE` brings the config of an existing setup
- new `opensim users import FILE [--grid NICK] [--apply]`
- new(build): package `opensim-helpers`
- new(build): package `opensim-web` (minimalistic setup for helpers)
- new `INSTALLATION.md` with detailed instructions (packages, git)
- new `opensim enable <instance>` and `opensim disable <instance>`
- fix: scripts use the bash-tools of the kit, not another one in the `PATH`
- fix the owner of the first estate gets the default region as home
- remove package `opensim-manfredaabye-helpers`
- update `opensim` bash completion

### 3.0.0-beta.2

- new: simulators and regions from `opensim setup`, one database per simulator
- new: ports by block of ten, `opensim ports [--publish|--ufw]` tells what to open
- new: remote (REST) console by default, `opensim rest` reaches another machine
- new: a simulator can join a grid run on another machine or in another container
- new: `opensim console <instance>` (or `screen`), `opensim command <instance> <text>`
- new: package `opensim-manfredaabye-helpers`
- new: free blocks between regions (`RegionSpacing`), nearest free place proposed
- new: progress of a start (services, plugins, database, regions) on one line, countdown on stop
- new: first simulator and region `Welcome`; roles (default, default HG, fallback) as a checklist
- new: random name proposed for a simulator or a region (`Gentle Gnat`), accents transliterated
- new: `opensim next port` and `opensim next location` replace `nextfreeports` and `nextlocation`
- update: the setup lands on what was just made, "Add region" proposed, Quit everywhere
- update: the setup restarts at once; a running simulator asks whether to warn its users
- update: a region is added through the console of a running simulator, no restart
- update: simulator ports start at 9000 (HTTP 9000, regions 9001...)
- update: the place proposed for a region is the first free one from the first place of the grid
- update: the setup goes one level deeper at a time (home, grid, simulator, region)
- update: `opensim-rest-cli` is the remote console client, `libexec/rest.php` is gone
- update: tests are in `tests/`, `vendor/bin/pest` runs them (`PACKAGING=1` for the packages)
- update: PHP libraries are composer packages loaded from the start, PHP 8.2 minimum
- update: a region starts without waiting for questions, one that dies is reported
- update: regions run from a read-only core on .NET, native libraries and stack found
- update: core packages depend on `libgdiplus`, `opensim install-dotnet` installs it
- update: the password of the account made in the grid can hold spaces
- fix: Unix line endings in the files made from OpenSim's (0.9.3.0 ships Windows ones)
- fix: OpenSim's default files are not read as instances when looking for free ports
- fix: enabling or disabling from the setup is done by the system user
- fix: `opensim start` launched simulators with the assembly of the previous instance
- fix: stopping a grid that was not running stopped a simulator with a similar name
- fix: stopping a simulator warns its users again, and only when it runs

### 3.0.0-beta.1

First release as packages: a grid from the wizard; simulators and regions still by hand.

- new: Debian and Ubuntu packages from the Magiiic apt repository
- new: standard layout under `/usr/share`, `/etc`, `/var/lib`, `/var/log` (`opensim`)
- new: `opensim` service starts the enabled instances at boot
- new: `opensim setup` wizard creates a grid, prepares its database, starts it
- new: the setup uses the database rights of its user, an admin account only when needed
- new: `opensim install-dotnet` installs the .NET 8 runtime
- new: `opensim start` waits for Robust's services, names those that failed
- update: `opensim` runs as the system user of the install, except `setup`
- update: an existing install (e.g. `/opt/opensim`) works with the packaged tools
- update: tools use [bash-tools](https://github.com/magicoli/bash-tools) 1.0.4 or later
- update the version of the kit restarts at 3.0.0

Not covered yet: simulators and regions from the setup, several Robust instances, modules with Robust.
