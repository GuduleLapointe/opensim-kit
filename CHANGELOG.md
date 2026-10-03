## Changelog

### 3.0.0-beta.3

- new: `opensim save|load iar|oar`
- new: `opensim backup [GRID [SIM]] [--logs] [--archives] [--output DIR]`
- new: quick setup - a single screen with the name of the grid, its login URI (host:port), the owner, the password and the optional email, other settings set by default, with a choice to accept or edit the config
- new: `opensim setup --file FILE`, taking full config from a JSON or YAML file
- new: pre-configuration for web server, `<grid>.caddyfile`, `<grid>-nginx.conf` and `<grid>-apache.conf`, in `/etc/opensim/grids/<grid>/web/`
- new `opensim oar pack|info|check|unpack` from sources in `share/ossl-scripts/fix-parcel-name-src`

- update: `opensim import [grid] FILE [--grid] [--simulator] [--users]` single command for both quick setup, full install and bulk import
- refactor: shared structure between commands have: `opensim <command> <instance> [action] [options]`
- feat: add most common terminal shortcuts in setup, Escape, Ctrl-Q, Ctrl-U, Ctrl-K, Ctrl-W, Alt-D, Ctrl-Y, Ctrl-A, Ctrl-E, Ctrl-B, Ctrl-F, Alt-B, Alt-F, Ctrl-Left, Ctrl-Right, Ctrl-D, Ctrl-T
- feat: import accounts can be given with their password hash and salt
- update(setup): password read from config if db user matches one already known
- update(setup): advanced setup groups related questions
- fix: name slugs as snake_case do avoid possible conflicts
- fix: hypergrid teleport conflicts when hosting two grids or more, default location set to public port on both axes (8002 gives 8002,8002) to avoid same map positions
- fix: the database account of a second simulator: use same password if same db user already if config
- fix: the search index of a simulator carries the name of its grid (`gridname` of `[DataSnapshot]`), not OSGrid
- fix the account of the owner is made when it has no email: the console asks for the email, which the setup did not answer
- fix: economy url passed to the viewer (pass base path, not currency.php)
- fix: disable query.php API endpoint was passed for search URL instead of proper search url
- fix: disable offline.php was passed as message of the day url
- fix: first account is optional (OpenSimulator does not require one)
- fix a region added to a simulator no longer asks for its restart: the console takes it, and the parcel is no longer renamed by a restart (the object of `share/ossl-scripts` does it)
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

- new simulators and regions from `opensim setup`: a simulator has its own database (created with the administrator account when it does not exist) and an estate whose owner is an account of the grid, chosen or created through the console of Robust; the wizard writes its config, links it into `opensim.d`, starts it and checks its region is online in the grid. More regions are added to a running simulator from the same menu, without restarting it
- new the ports of an instance are a block of ten, the first free one, looking at the config files, the ports in use and the regions the grid knows on its other machines: Robust has its public port on x2 and its private one on x3, a simulator its HTTP port on x0 and its regions on the others, the console of any instance on x4; `opensim ports` tells what each instance listens on and who has to reach it, `--publish` gives the options of a container, `--ufw` the firewall rules
- new the console of an instance is asked by the wizard, remote (REST, the default: a user, a password and a port x4 of its block, reachable from another machine or a container) or a screen session; the launcher starts, stops and reaches an instance with a remote console as with a screen one, and `opensim rest --url http://host:port --user user` reaches one of another machine or a container
- new a simulator joins a grid whose Robust is on another machine or in another container from the same `Sim` menu, with no grid created first: its address and ports are asked (its name and nick are read from `get_grid_info`) and kept for the next simulators; the address the regions announce is asked too
- new `opensim console <instance>` (or `screen`) attaches to the console of an instance, screen or remote console; `opensim command <instance> <text>` sends a command from a script
- new package `opensim-manfredaabye-helpers`, the helpers of the OpenSimSearch module (the search service the viewers query), from the sources of the `manfredaabye` fork, which is another implementation than opensim-helpers; its settings are in `/etc/opensim/manfredaabye-helpers`
- new the regions of a grid leave free blocks between them (the rule, asked with the grid, `RegionSpacing` in its config, 0: side by side): the simulator wizard asks the place of the region (1000,1000 by default), then takes the free place nearest to it by the rule and asks to confirm it when it is not the one asked. A grid whose Robust is on another machine is asked over HTTP what its regions take around the place, and follows the default rule
- new the start of an instance shows how it loads: the services of a Robust are counted (`AssetServiceConnector 1/22`), a simulator shows the plugins of modules it loads (`OpenSim.Region.CoreModules 11/15`), the database it brings up to date on a first start (`Database RegionStore 52/66`), then each region configured, in the grid and ready (`Sim1: registered in the grid (1/2)`)
- new the landing region of a grid (where visitors arrive and fall back to) is decided with its first simulator, not with the grid: the first simulator and its first region are called `Welcome` unless changed, and the roles missing in the grid (default region, default HG region when it has Hypergrid, fallback region) are offered as a checklist when a region is created, the first two checked; what is checked is written in the Robust config (`Region_<Name> = "DefaultRegion, ..."`, where the config shows its example, not between the comments of the next section) and Robust restarts to read it. Without a default region a login ends with "destination not found"
- update the setup lands where the work goes on: after a grid on its screen, after a simulator or a region on the screen of the simulator, "Add region" proposed; the screens end with the actions on what they show (configure, disable this grid or simulator), then Back and Quit, which leaves the setup from any screen; the names of grids, simulators and regions are in colour, a simulator is titled with the name it was given
- update the setup restarts a simulator or a grid at once; a simulator that already runs asks whether to warn its users and wait two minutes (`opensim restart` still does by default, `opensim restart now` does not)
- update a region is added to a running simulator through its console, answering its questions, without restarting it; what has to restart (a new region, whose parcel is named after it instead of "Your Parcel", a region moved, enabled or disabled) is listed in `pending-restarts.txt` of the data folder, one `<instance> <reason>` per line, and the setup offers to restart those instances when it ends: the grids first, then each simulator, stopped, its parcels named in its database, started again
- fix enabling or disabling a grid, a simulator or a region from the setup is done by the system user of the install, not by the one who runs the setup: it said `Cannot disable` for want of the right to rename the file
- update the place proposed for a region is the first free one from the first place of the grid, not always 1000,1000
- update the start of an instance is one line that changes (`opensim` shows the first lines the process prints, "STARTING OPENSIM", the plugins, the regions) instead of a list; stopping shows the countdown of the warning to the users, then what the instance does until it exits
- new the setup proposes a random name (an adjective and a noun that begin the same, `Gentle Gnat`) for a simulator or a region when it has nothing better to propose: the first simulator is `Welcome` and its first region has its name, the next ones are not called the same. An accent in the name of a simulator or a region is transliterated, not refused (`Joyeux Noël` becomes `Joyeux Noel`)
- new `opensim next port [-e PORT] [MIN] [COUNT]` and `opensim next location [-e X,Y] GRID [X,Y] [COUNT]` give the next free ports and places by the rules of the setup (the same code); they replace the scripts `nextfreeports` and `nextlocation`, which had rules of their own
- fix the files made from the ones of OpenSim have Unix line endings: OpenSim 0.9.3.0 ships its ini files with Windows ones, which showed as `^M` in the config of a grid; the copies of a grid made before are cleaned when its setup runs again
- update the ports of a simulator are looked for from 9000, where the users are used to find simulators, not right after the ones of Robust: the first simulator is on 9000 with its regions on 9001, 9002..., the next one on 9010; the thousand above its grid, in its hundred (a grid on 8102 has its simulators from 9100)
- fix the copies of the files OpenSim ships (`OpenSimDefaults.ini` sets `http_listener_port = 9000`) are not read as instances when looking for free ports, they kept the block 9000 from being chosen
- update the setup goes one level deeper at a time: home (the core, each grid, Add grid), grid (configure, its simulators, add a simulator, enable or disable), simulator (reconfigure, enable or disable, its regions, add a region), region (reconfigure its place and port, enable or disable): the grid chosen is the one clicked, not the first. The console choice reads "Remote REST console (recommended)" or "Screen session (on this machine)"
- update the remote console commands (`opensim console`, `opensim command`, `opensim rest`) use `opensim-rest-cli` of the opensim-rest-php package, which has the options of the former client of the kit (`--ini`, `--host`, `--url`, `--wait`, standard input, `--repl`); `libexec/rest.php` is gone
- update the tests are all in `tests/` and `vendor/bin/pest` runs them: the PHP minimum and compatibility, the shell scripts (bashunit), and the packages and container image in containers when asked (`PACKAGING=1`)
- update the PHP libraries (engine, helpers, REST client) are composer packages, loaded from the start; PHP 8.2 is the minimum (Debian 12 and Ubuntu 24.04 have it), the packages depend on the PHP extensions the tools use, and the requirements are in the README
- update starting a region no longer waits two minutes for questions it does not ask: a region fully described is up in seconds, and a region that dies right after loading is reported as a failed start
- update regions run from a read-only core on .NET: their native libraries (physics, OpenJPEG) are found, and their stack is the one of `opensim.sh`
- update the packages of the OpenSimulator cores depend on `libgdiplus`, without which a region stops at once; the launcher says when it is missing, and `opensim install-dotnet` installs it
- update the password of the account made in the grid can hold spaces, and is typed in the console as it is given (no escape of screen is read in it, nor is it in the arguments of a process)
- fix `opensim start` launched the simulators with the assembly of the previous instance, i.e. as Robust, when it started several instances
- fix stopping or restarting a grid that was not running shut down a simulator whose name begins the same (`testgrid`, `testgrid_sim1`): a console is now reached only by the exact name of an instance
- fix stopping a simulator warns its users again, and only when it runs

### 3.0.0-beta.1

First release as packages. A grid can be created with the wizard, started and run; simulators and regions are still added by hand.

- new Debian and Ubuntu packages, from the Magiiic apt repository: `opensim-kit` (the tools, the latest OpenSimulator, its essential modules, a database server), `opensim-tools`, `opensim-<version>` (an OpenSimulator release, several side by side), `opensim`, `opensim-unstable`, and the modules `opensim-<version>-opensimsearch` and `-gloebit`, installed but not enabled
- new standard layout: releases in `/usr/share/opensim`, config in `/etc/opensim`, data in `/var/lib/opensim`, logs in `/var/log/opensim`, cache in `/var/cache/opensim`, instances run by the `opensim` account
- new `opensim` service, starts the enabled instances at boot, stopped with the package, never restarted by an upgrade
- new `opensim setup` wizard, creates a grid and its Robust configuration, checks then prepares its database step by step (account, database, rights), and can enable and start the grid, with an exit code that tells whether it runs
- new the setup uses the database rights of the user who starts it, no sudo needed; an administrator account is asked only when something has to be created and the user has no access to do it
- new `opensim install-dotnet`, installs the .NET 8 runtime OpenSimulator 0.9.3 needs where the distribution does not provide it
- new `opensim start` waits until Robust has loaded every service its configuration lists, names the ones that failed, and exits with an error when an instance does not start
- update the `opensim` command runs as the system user of the install, through sudo, for every command but `setup`
- update an existing install (e.g. `/opt/opensim`) keeps working with the packaged tools, its `opensim.conf` gives the locations
- update the tools use [bash-tools](https://github.com/magicoli/bash-tools) 1.0.4 or later, as a package or through composer
- update the version of the kit restarts at 3.0.0

Not covered yet: creating simulators and regions from the setup, several Robust instances, and running the modules with Robust, which is not tested.
