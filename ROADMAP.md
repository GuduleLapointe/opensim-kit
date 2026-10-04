# Roadmap

## Fixes

- [x] Separation of concern is not respected in child libraries. Each library must remain strictly independent and never rely on data, classes or concept defined in the apps/projects/libraries consuming it.
- [x] The port-based location must only be used to set the initial center of the grild. Once it has been set, it is not overridable by the rule.
- [x] Wrong syntax for `opensim load iar|oar` and `opensim save iar|oar`: action and save must stay together, `opensim save iar [instance]`, not `opensim save [instance] iar`
- [x] Could not get load/save oar/iar to work
- [ ] Could not get helpers to work (web returns 500) (the helpers answer with the new `config.php` on a test grid, and say when `helpers.ini` is unreadable by the web user instead of a 500; to confirm on the real site: what does the error log say? The packaged helpers answer behind real nginx, Apache and Caddy in `web-scenario.sh`)
- [ ] setup: working from grid/sims was a design flaw: (done: the grid screen lists regions, "Add region" asks the simulator of the pool, `opensim save|load oar` and `opensim backup` take a sim or a region; to do: the other commands)
    - from user's point of view, there is a grid, with regions
    - and simulators are just a pool of servers providing the regions
    - setup must propose to create regions first, with the choice to create a new simulator or add them to use one from the sims pool.
    - command-line tools must allow both sims and regions as second argument
- [x] Changelog is not a user guide, keep it as a SHORT list of changes, no explanations!
- [x] Tests output is truncated, KEEP descriptions and notifications SHORT
- [x] (Test fail) opensim save and load → refuse a file that exists a simulator that does not run a missing password
- [x] (Test fail) opensim backup → holds the configuration the data and a dump of each database of a grid and nothing the users backed up
- [x] (Test fail) opensim backup → takes the archives of the users and the logs when asked
- [x] (Test fail) opensim backup → is the one of a simulator without the other ones
- [x] (Test fail) opensim import the config of a grid → tells the plan and the settings it keeps and writes nothing without apply
- [x] Architectural misconception: opensim-engine include codes referencing the class `OpenSim_Kit`, which is a nonsense: Engine is the a standalone library, the Kit consumes it, not the other way around
- [ ] check `opensim stop` on a simulator with an NPC in it: the real users are counted from `show users`, the NPCs (`NPC Root`) are skipped, but only the format was checked on a real core, not an NPC -> does not seem to properly skip the NPCs (the rows of `show users` are now read on a screen console too, the NPCs are left out; still to confirm with a real NPC)
- [ ] confirm on ubuntu 24.04 (ursull), with the next package and an outdated bash-tools left in composer global, that `opensim setup` no longer ends with `debug: command not found` (the scripts now load the bash-tools of the kit before the one of the `PATH`, see `libexec/load-helpers`)
- [ ] dependencies packages must be added to the release assets in their own repositories
- [x] opensim status "down" count should not be displayed when none of the instances are down
- [x] a new region is named after itself without a restart of the simulator (the object of `share/ossl-scripts`, loaded through the console)
- [x] firewall: `opensim ports --ufw` tells the rules, `INSTALLATION.md` has a Firewall section, the setup does not apply them
- [x] the simulators of a grid with helpers know where `offline.php` (`[Messaging]`) and `register.php` (`DATA_SRV_MISearch` of `[DataSnapshot]`) are
- [x] the message of the day: `[LoginService] MessageUrl` is a URL whose text is shown at login (read by Robust when it starts, `WelcomeMessage` when it cannot be), found in the code of the core (`LLLoginService`); `motd.php` of opensim-helpers serves the `motd` of `helpers.ini`
- [x] the destination guide is `guide.php` of the helpers, `DestinationGuide` of the Robust config points to it
- [ ] the search page of the web site: not in opensim-helpers (no branch has a page, only the backend behind `query.php`), it is probably in the WordPress plugin; find out where it reads from, so that it finds what `parser.php` indexes, and write the `search` URL of `[GridInfoService]` when it exists (found: the `web-search` block of w4os has no source of its own, it asks `query.php` of the helpers: `dir_places_query` over XML-RPC with `websearch`, `gk` and the flags)
- [ ] run again the packaging scenario beyond the upgrade step (the development build, removal, purge) and with the real `apt-package`: only the packages built by hand with nfpm were tested; run it with docker as well as podman (done with podman: full scenario on Debian 12 and Ubuntu 24.04, packages from `apt-package`; docker left)
- [x] try the web server examples written by the setup (`<grid>.caddyfile`, `<grid>-nginx.conf`, `<grid>-apache.conf`) on a real Caddy, nginx and Apache (`tests/Packaging/web-scenario.sh`; nginx needed a fix)

## Critical improvements

- [x] `opensim save|load iar|oar` and `opensim backup`: the archives of the users in `<data of the grid>/backups`, the backup of the administrator (configuration, data, databases)
- [ ] `opensim restore` of a backup of the administrator on a new machine (the install, the databases, the data), and a check of what the archive holds
- [ ] the archives of the users: `opensim save oar` for every region of a grid (a rotation, `--keep N`), and the OAR of a region that belongs to a user who is not the owner of the grid (what the owners of regions can ask for themselves)
- [x] backup: the rotation of the old backups (`--keep N`), and the same for the archives
- [x] bulk avatar creation (from a list provided by a third-party source: CSV, JSON or YAML), directly in the database, as `opensim import [grid] FILE --users`; relies on the install the profile gives, with or without the kit
- [ ] try the accounts made in the database with a viewer (login, inventory, default outfit) on a test grid, and check the numbers of the system folders (`Settings` 56 and `Material` 57) against the core
- [ ] test `opensim import` on real grids (the config of a grid or of a simulator, a setup file, a list of accounts: what works, what is missing)
- [x] add opensim-helpers package and examples of configuration as alias/subfolder in caddy, nginx and apache2
- [ ] add docker/podman installation support in setup
- [ ] rename composer packages to match github account GuduleLapointe
- [ ] parcel naming object: make it part of the library of the grid too (the original stays available to the users, a copy is rezzed in each new region), and let the operator choose what a new region gets at its start (name of the parcel, music, media, flags: the script is the place for it)
- [ ] OSSL: the standard config enables the functions with the defaults of the core; let the operator customize them (which functions, for whom)

## Next steps

- [ ] add full import procedure for existing grids/simulators: the config is imported (`opensim import`), the database, the assets and the other data are not
- [x] add/update bash completion
- [ ] build ready to use zip packages for simple download, added to the release assets alongside the source code and apt package
- [ ] add simplified installation script, detecting the platform and installing with the appropriate method (zip package or apt package), with a one-line command like `curl -sSL https://raw.githubusercontent.com/GuduleLapointe/opensim-kit/refs/heads/master/install.sh | bash`
- [x] add basic ready to use website (the placeholder site of the `opensim-web` package)
- [ ] add ready to use default avatars
- [ ] add default Inventory package
- [ ] add nat/port forwarding instructions or presets (with standard tools or third-party provides like ngrok, cloudflare...)
- [ ] make most opensim-tools features installation-agnostic (support both opensim-kit and custom/standard opensim installations): `/etc/opensim/opensim.conf` becomes only a reference pointing to the user's actual core installation and configuration files location (e.g. `/opt/osgrid`, `~/diva`, `~/opensim/opensim-0.9.3.0/bin`...)
    - [x] `opensim profile list|add|default|remove` registers an install made by hand (core, config and data directories)
    - [ ] a grid follows the profile of its own install, the default profile is only the default of the new grids
    - [ ] the system user to run the instances as is per profile, not only `SystemUser` of `[Defaults]`
    - [ ] the setup offers to register an install (the core menu), and no script assumes `/etc/opensim`, `/var/lib/opensim` or `/usr/share/opensim` (review, one fix per file, with a test)
- [ ] test the OpenSimSearch module from end to end with a parcel shown in search: the chain works up to the snapshot (the sim registers on `register.php`, `parser.php` fetches it, `query.php` answers); a new region has no searchable parcel, the flag is `ShowDirectory` = 4096 in `Flags` of the `land` table of the simulator, to set in the test then check that `query.php` finds the parcel
- [ ] add to the README of opensim-helpers (and of the engine) a short section on how to use them with the OpenSim kit
- [ ] install instructions in the README of `lsl-ossl-zed` (rust, clone, `zed: install dev extension`, the prebuilt LSP binaries only cover Linux x86_64 and macOS)

## Less urgent

- [x] localize the setup with gettext (a requirement of the project): the messages of the PHP setup are translatable (`locales/`, `locales/update-pot`)
- [ ] localize the launcher and the bash scripts with gettext
- [ ] a first translation of the setup (`locales/fr.po`)
- [ ] localize helpers
- [ ] `opensim online`: show who's online

## Could be great

- [ ] Memory/CPU usage monitoring. Notify admin and/or restart Sim above given thresholds. Thinking twice about previous notify thing: maybe it's better to suggest a dedicated monitoring tool handle that.
