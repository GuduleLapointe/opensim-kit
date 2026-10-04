## Changelog

### Unreleased

- fix: engine and helpers don't know the kit, `config.php` of the helpers package defines their constants
- fix: `opensim save|load iar|oar [instance]`, the instance is a grid, a simulator or a region
- fix: the public port sets the first center of a grid only, its regions give it afterwards
- fix: `opensim stop` skips the NPCs on a screen console
- fix: unreadable `helpers.ini` is reported by the helpers instead of a 500
- fix: tests run on macOS (temporary folder, tar)

### 3.0.0-beta.3

- new: `opensim save|load iar|oar`
- new: `opensim backup [GRID [SIM]] [--logs] [--archives] [--output DIR]`
- new: quick setup, one screen (grid name, login URI, owner, password, optional email), other settings by default
- new: `opensim setup --file FILE`, taking full config from a JSON or YAML file
- new: pre-configuration for web server, `<grid>.caddyfile`, `<grid>-nginx.conf` and `<grid>-apache.conf`, in `/etc/opensim/grids/<grid>/web/`
- new `opensim oar pack|info|check|unpack` from sources in `share/ossl-scripts/fix-parcel-name-src`

- update: `opensim import [grid] FILE [--grid] [--simulator] [--users]` single command for both quick setup, full install and bulk import
- refactor: shared structure between commands have: `opensim <command> <instance> [action] [options]`
- feat: terminal shortcuts in the setup (Escape, Ctrl-Q, Ctrl-U, Ctrl-K, Ctrl-W, Alt-D, Ctrl-A, Ctrl-E...)
- feat: import accounts can be given with their password hash and salt
- update(setup): password read from config if db user matches one already known
- update(setup): advanced setup groups related questions
- fix: name slugs as snake_case do avoid possible conflicts
- fix: hypergrid teleport conflicts with two grids or more, first location from the public port on both axes (8002 gives 8002,8002)
- fix: the database account of a second simulator: use same password if same db user already if config
- fix: the search index of a simulator carries the name of its grid (`gridname` of `[DataSnapshot]`), not OSGrid
- fix the account of the owner is made when it has no email: the console asks for the email, which the setup did not answer
- fix: economy url passed to the viewer (pass base path, not currency.php)
- fix: disable query.php API endpoint was passed for search URL instead of proper search url
- fix: disable offline.php was passed as message of the day url
- fix: first account is optional (OpenSimulator does not require one)
- fix: a region added to a simulator no longer asks for a restart, the parcel is no longer renamed by a restart
- fix `opensim stop` counts down to the shutdown (`Stopping sim in 120s`), not to the next warning to the users
- update `opensim stop` does not wait when no real user is in the regions
- add OSSL is in the standard config
- new `opensim profile list|add|default|remove`
- new setup supports gettext localization, domain `opensim-kit`, language from environment (`LC_ALL`, `LANGUAGE`); catalogue in `locales/opensim-kit.pot`
- new `locales/update-pot` script to refresh translations from `.po` files
- new `opensim import robust FILE` and `opensim import sim FILE --grid NICK` port the configuration of an existing setup
- new `opensim users import FILE [--grid NICK] [--apply]`
- new(build): package `opensim-helpers`
- new(build): package `opensim-web` (minimalistic setup for helpers)
- new `INSTALLATION.md` with detailed instructions (packages, git)
- new `opensim enable <instance>` and `opensim disable <instance>`
- fix: make sure the scripts use the bash-tools provided by the kit, avoid conflict with other versions that might be in the `PATH`
- fix the owner of the first estate gets the default region as home
- remove package `opensim-manfredaabye-helpers`
- update `opensim` bash completion

### 3.0.0-beta.2

- new: simulators and regions from `opensim setup`, each simulator with its own database and an estate owned by an account of the grid
- new: ports by block of ten, the first free one; `opensim ports [--publish|--ufw]` tells what to open
- new: remote (REST) console, the default; `opensim rest --url URL --user USER` reaches another machine or a container
- new: a simulator can join a grid run on another machine or in another container
- new: `opensim console <instance>` (or `screen`), `opensim command <instance> <text>`
- new: package `opensim-manfredaabye-helpers`
- new: free blocks between the regions of a grid (`RegionSpacing`), the nearest free place is proposed
- new: progress of a start (services, plugins, database, regions) on one line, countdown on stop
- new: the first simulator and its first region are `Welcome`; the roles (default, default HG, fallback) are a checklist written in the Robust config
- new: random name proposed for a simulator or a region (`Gentle Gnat`), accents transliterated
- new: `opensim next port` and `opensim next location` replace `nextfreeports` and `nextlocation`
- update: the setup lands on the screen of what was just made, "Add region" proposed, Quit on every screen
- update: the setup restarts a simulator or a grid at once, a running simulator asks whether to warn its users
- update: a region is added to a running simulator through its console, no restart; its parcel is named after it
- update: ports of a simulator start at 9000 (HTTP 9000, regions 9001...), in the hundred of its grid
- update: the place proposed for a region is the first free one from the first place of the grid
- update: the setup goes one level deeper at a time (home, grid, simulator, region)
- update: `opensim-rest-cli` is the remote console client, `libexec/rest.php` is gone
- update: tests are in `tests/`, `vendor/bin/pest` runs them (`PACKAGING=1` for the packages)
- update: PHP libraries are composer packages loaded from the start, PHP 8.2 minimum
- update: a region starts without waiting two minutes for questions, one that dies at start is reported
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

First release as packages. A grid can be created with the wizard, started and run; simulators and regions are still added by hand.

- new: Debian and Ubuntu packages from the Magiiic apt repository (`opensim-kit`, `opensim-tools`, `opensim-<version>`, `opensim-unstable`, modules)
- new: standard layout, releases in `/usr/share/opensim`, config in `/etc/opensim`, data in `/var/lib/opensim`, logs in `/var/log/opensim`
- new `opensim` service, starts the enabled instances at boot, stopped with the package, never restarted by an upgrade
- new: `opensim setup` wizard, creates a grid and its Robust config, prepares its database, can enable and start it
- new: the setup uses the database rights of the user who starts it, an administrator account is asked only when needed
- new `opensim install-dotnet`, installs the .NET 8 runtime OpenSimulator 0.9.3 needs where the distribution does not provide it
- new: `opensim start` waits until Robust has loaded its services, names the ones that failed, exits with an error when an instance does not start
- update the `opensim` command runs as the system user of the install, through sudo, for every command but `setup`
- update an existing install (e.g. `/opt/opensim`) keeps working with the packaged tools, its `opensim.conf` gives the locations
- update the tools use [bash-tools](https://github.com/magicoli/bash-tools) 1.0.4 or later, as a package or through composer
- update the version of the kit restarts at 3.0.0

Not covered yet: creating simulators and regions from the setup, several Robust instances, and running the modules with Robust, which is not tested.
