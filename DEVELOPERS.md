# Developers

## Architecture

This kit is a collection of tools and services to manage OpenSim grids.

It uses libraries `opensim-helpers`, `opensim-engine`, and `opensim-rest-php`. Each library has its own repository and is usable as a standalone app/web app/project. Therefore, each part can only use it's own code and dependencies, never code or concepts related to the kit. The config of a grid (`/etc/opensim/grids/<grid>/`: its Robust config and its `helpers.ini`) is not one of them: it is the structure the projects share, and `opensim-helpers` reads it itself when it has no `config.php`.

- **opensim-rest-php** uses no external dependencies. It provides a generic REST console API.
- **opensim-engine** provides the OpenSim engine and grid low-level management logic. It uses `opensim-rest-php`.
- **opensim-helpers** provides the web tools to communicate with the bridge from the viewer (query, currency...) or a browsser (web search, guide...). It relies on `opensim-engine` and `opensim-rest-php`.
- **opensim-kit** provides the whole environment setup and management. It uses own code and `opensim-helpers` (and therefore `opensim-engine` and `opensim-rest-php`).
- **w4os WordPress plugin** is another implementation to provide management and web interface, that uses `opensim-helpers`.

## Packaging

The packages are built with [nfpm](https://nfpm.goreleaser.com) and published to an apt repository with the tools of [build-tools](https://github.com/magicoli/build-tools) (`apt-package`, `apt-publish`), the repository being the one `APT_REPO_DIR` names in `.env`. Everything lives in `packaging/`, one definition per package:

| Package                   | Definition              | Version                          | Content                                                                                                                                                            |
| ------------------------- | ----------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `opensim-tools`           | `opensim-tools.yaml`    | git tag of this repository       | the tools, in `/usr/share/opensim-tools`, the `opensim` command, the service                                                                                       |
| `opensim-<version>`       | `opensim-core.yaml`     | OpenSimulator release + revision | an OpenSimulator release, in `/usr/share/opensim/<version>`, for amd64 and arm64                                                                                   |
| `opensim`                 | `opensim.yaml`          | same as the latest release       | metapackage, the latest release                                                                                                                                    |
| `opensim-kit`             | `opensim-kit.yaml`      | git tag of this repository       | metapackage, the tools and the latest release                                                                                                                      |
| `opensim-web`             | `opensim-web.yaml`      | git tag of this repository       | the placeholder site of a grid, in `/usr/share/opensim-web/html` (`share/web`)                                                                                     |
| `opensim-unstable`        | `opensim-unstable.yaml` | source version, date and commit  | OpenSimulator built from the `upstream/opensim` submodule, in `/usr/share/opensim/unstable`                                                                        |
| `opensim-<core>-<module>` | `opensim-<module>.yaml` | module version + revision        | a module for a release or `unstable`, in `/usr/share/opensim-modules/<core>/<module>`, not loaded until enabled                                                    |

`opensim-helpers`, `opensim-engine` and `opensim-rest-php` are built and packaged by their own projects (`dev/build.sh` in each one): `opensim-tools` depends on them, and its `vendor/magicoli/` folder has links to their folders in `/usr/share` instead of copies (`packaging/siblings`). The tools and the releases install without each other. `opensim-tools` adds an install profile for each release in `/usr/share/opensim`, and removes it with the release, through a dpkg trigger.

The modules are installed in their own folders, as some fail when loaded without their config: installing one does not enable it. They only suggest their core, so a build made elsewhere can use them and removing a core keeps them. `opensim-kit` installs the handful of essential modules for the latest release; the others are only in the repository. Third-party terms are in `contrib/README.md`, `modules/README.md` and in the `copyright` file of each module package.

Removing `opensim-<version>` or `opensim-unstable` stops the instances running from that core only, found by the full path of their assembly in the process list: instances of another release or of a build installed elsewhere are left alone. Nothing in an OpenSim instance needs a clean shutdown, its state is in the database, so a plain stop is enough.

The version of the tools and of the metapackages is the version tag when the build is on one, the release. Any other build carries the version being worked on, declared in `.version` (a pre-release such as `3.0.0-dev`): `3.0.0~dev.<commits>+g<sha>`, which sorts after the former dev builds and before the release. The tag goes on the release commit only, which updates `CHANGELOG.md` (the tag message repeats it); `.version` is bumped for the next development version after it, a pre-release that sorts after the tag (`3.0.0-beta.2` after `3.0.0-beta.1`, not `3.0.0-dev`: `~dev` sorts after `~beta`).

## Building and publishing

```bash
dev/build.sh                                 # every package and the zip of the tools, into dist/
dev/build.sh deb opensim-tools               # only this package
dev/build.sh zip                             # dist/opensim-kit-<version>.zip, the tools with their vendor folder
vendor/bin/apt-package --publish      # publish, on a version tag of this repository
vendor/bin/apt-package --publish opensim-core opensim   # a new release, without a tag
```

`dev/build.sh` builds the last commit and refuses when changes are not committed or when `composer.lock` is not up to date (`DIRTY=1` builds the last commit anyway). The Debian packages are made by `apt-package`, of build-tools. The version carries the commit: `3.0.0~beta.4.709+gb98c10a` (`g` is for git, as `git describe` writes it, the hash follows it). The scripts are those of [build-tools](https://github.com/magicoli/build-tools) (`vendor/bin/build-tools`, a development dependency): the version, `stage` (the committed files without what `.distignore` leaves out, and the vendor folder composer makes without the development tools, from the repositories of `composer.json`), the release. The project keeps its own `packaging/`: `tools` (the tools as distributed: stage, translations, OSSL script archives), `build-zip` (the zip of the tools), `siblings` (the projects the Debian package gets from their own packages), `build` (prepares the packages), `remote-build`.

Publishing skips the packages whose version is already in the repository, so the tools and the releases keep their own pace. The packages with the version of this repository need a version tag (`v3.0.0`, the `v` is optional), and are attached to its GitHub release, marked as a pre-release when the version has one (`v3.0.0-beta.1`).

`packaging/build` runs first and prepares only the packages asked for:

- `opensim-tools`: `build/opensim-tools` from the committed tree (`packaging/tools`), with the PHP dependencies (`composer install --no-dev`, bash-tools included), without what `.distignore` lists, and without the projects of `packaging/siblings`, which come from their packages. The `bash-tools` package it depends on is at least the version composer bundles (`BASH_TOOLS_VERSION`, read from `composer.json`). Uncommitted changes are not packaged.
- `opensim-core`, `opensim`: the release of `packaging/opensim-core.versions`, the last one or `OPENSIM_VERSION`, e.g. `OPENSIM_VERSION=0.9.3.0 vendor/bin/apt-package opensim-core`. Its tarball is downloaded into `src/` and checked against its SHA256.

- `opensim-web`: `share/web` as it is.
- `opensim-unstable`: OpenSimulator built from the commit of the `upstream/opensim` submodule (a shallow clone of the upstream repository, `master`), in a .NET SDK container (podman, or `CONTAINER=docker`). Each commit is built once, into `build/cache/`. With `BUILD_HOST=name` in `.env` (an ssh host with git, podman and rsync, see `.env.example`) the build runs on that machine instead, in the same container, so its system does not matter: it keeps a clone of the repository and the NuGet packages in `~/.cache/opensim-kit`, always builds from the files of the commit, and only what the package needs (`bin/` and the documents) comes back. The build is much faster there than in the podman machine of a laptop. Update it with `git submodule update --remote upstream/opensim`.
- `opensim-<module>`: the files listed for the module in `packaging/opensim-modules.versions`, for the release of `opensim-core`, or the core of `MODULES_CORE` (a release or `unstable`), e.g. `MODULES_CORE=unstable vendor/bin/apt-package opensim-gloebit`. The files downloaded go to `src/`, checked against their SHA256. The packages are named after the modules.

A new OpenSimulator release is added at the end of `packaging/opensim-core.versions`, with its checksum. A published package is never changed: a packaging change of a published release gets the next revision in that file, as for the modules in `packaging/opensim-modules.versions`.

The maintainer scripts of `opensim-tools` create the `opensim` account and the folders of the system layout, write the default `/etc/opensim/opensim.conf` when missing, and handle the `opensim` service like `dh_installsystemd`: enabled on first install, stopped with the instances on removal, never restarted by upgrades.

The scripts use the helpers of [bash-tools](https://github.com/magicoli/bash-tools): the `bash-tools` package, a dependency of `opensim-tools`, or composer's copy in a git checkout (`composer install`). It is a development dependency of composer, so the packages don't bundle it. During development, composer links the local `../bash-tools` checkout when there is one (path repository, `dev-dev`): switch to a released version before a release (and back to `dev-dev` with the path repository after it).

The PHP dependencies are locked for PHP 8.1 (`config.platform.php` in `composer.json`), the oldest PHP of the supported systems (Ubuntu 22.04).

The releases are installed read-only, so nothing may be written in their `bin/` folder at run time. OpenSim writes its platform `System.Drawing.Common.dll` there on start when it differs: the build puts it in place beforehand.

## Release

One command in each project, in the order of the family (rest-php, engine, helpers, kit), each one completely before the next:

```bash
dev/release.sh          # the whole release, after one question
dev/release.sh status   # what is done and what remains
dev/release.sh beta     # patch|minor|major|stable|dev|alpha|beta|rc|1.2.3-beta.4: see the versions in the README of build-tools
```

It needs the `.env` of the project to say where the apt repository is (`APT_REPO_DIR`, see `.env.example` of build-tools), `gh` (logged in) and `nfpm`, and says what is missing before it does anything. `dev/switch.sh dev|release` does the composer part alone: the projects of the family linked next to this one (path repositories, `@dev`), or required by version (`^` the latest version tag of each, refused when a project changed since that release).

## Shell scripts

The scripts of `dev/` are wrappers of build-tools. The scripts of `packaging/` and `tests/Packaging` use the functions of [bash-tools](https://github.com/magicoli/bash-tools) to ask, tell and fail (`log`, `success`, `warning`, `die`, `end`, `yesno`, `require`, `usage`, `read_env`), loaded from `vendor/bin/bash-helpers`: write the new ones the same way, not with their own prompts and `echo`.

## Tests

One command runs every test, `vendor/bin/pest` (`composer test`): the PHP ones with [Pest](https://pestphp.com), the shell scripts with [bashunit](https://bashunit.typeddevs.com) (`tests/lib/bashunit`), run from Pest. Everything is in `tests/`:

- `tests/Environment`: the PHP minimum. It is read from `composer.json`, the composer platform must follow it, and `phpcs` with PHPCompatibility (`phpcs.xml.dist`) checks that the code needs nothing newer and uses nothing deprecated up to the newest PHP version listed in the test. It runs on the minimum PHP, 8.2 (pinned in `.php-version` for the tools that read it).
- `tests/Unit`: the shell scripts (`*-test.sh`, bashunit): they parse, and bash scripts use `#!/usr/bin/env bash`. Also `tests/lib/bashunit tests/Unit`.
- `tests/Packaging`: the packages and the container image, tested in containers (podman). Slow, they need podman and the packages of `dist/`, so they run only when asked, on a host that has them: `PACKAGING=1 vendor/bin/pest`. The scripts also run by hand, from the repository root.

`tests/Packaging/run` installs the packages of `dist/` in a container with systemd (podman): a release alone then the tools, a grid made by the wizard and run by the service, a simulator with its region joining that grid (its estate owner made through the console of Robust, a second region added while it runs), the console commands, then upgrade, the other builds, removal of the tools and of the release, and purge:

```bash
tests/Packaging/run                                   # Debian 12
tests/Packaging/run docker.io/library/ubuntu:24.04    # Ubuntu 24.04
```

`scenario.sh` runs in the container, its scripted answers to the wizards are in `newgrid.php` and `newsim.php` (`ScriptedUi.php`). The container is capped to 700 MB (`MEMORY=1g tests/Packaging/run` to change it): the machine of podman is shared with every other container, and one that lacks memory stops the biggest process of any of them, not the one that asked for it. With the cap, the test does not reach the containers of the other projects as long as about 800 MB are free in that machine (the script says when they are not): stop the containers you do not need first, rather than giving the machine more memory. `KEEP=1` keeps the container for inspection.

`SCENARIO=web-scenario.sh tests/Packaging/run` serves the site of a grid with real nginx, Apache and Caddy, each with the example the setup writes for it; `lib.sh` has the checks the scenarios share. The packages of the helpers, the engine and rest-php are taken from the `dist` folders of their projects (`DEPS_HELPERS`, `DEPS_ENGINE` and `DEPS_REST` say where else they are): build them first.

`STOP_AFTER=search tests/Packaging/run` stops the scenario after the search tests (`search.sh`), on the grid it has made: Robust, a simulator, the accounts of a list, the helpers of the package behind a web server. The searches are what the OpenSimSearch module of a simulator asks `query.php`: the same XML-RPC calls, with the keys it sends (`tests/Packaging/search/contract.php`, from its source, checked by `tests/Unit/SearchModuleContractTest.php`) and the answers read the way it reads them. A simulator that only tells its snapshot (`search/fakesim.php`, with the files `collector-*.xml` in the format OpenSimulator writes) registers to `register.php`, and the tables and the searches are checked (places, popular places, land for sale, events, classifieds, a second snapshot, unregistering); events, classifieds and 120 parcels for the pages are put in the tables by `search/seed.sql`. The real simulator of the grid registers too, and the search of people is the call of the core to the user accounts of Robust. What the viewer sends to the simulator (UDP) and the publication of events and classifieds are not tested: the first is OpenSimulator's, the others are not done by the helpers.

`tests/Packaging/check` is the quick one, without systemd: the tools install with those three packages and the command runs, the zip is used without composer. What a test container needs is sent to it as a tar stream, so it can run on the podman of another machine: `CONTAINER_CONNECTION=name` (a `podman system connection`), and `MEMORY=`, can be set in `tests/.env` (see `tests/.env.example`), read after the `.env` of the project (bash-tools `read_env`: what the files set wins over the environment of the command).

`tests/Packaging/distro-pest` runs the Pest suites of the kit and of its libraries with the PHP of a distribution and only the extensions the packages depend on (`tests/Packaging/distro-pest docker.io/library/ubuntu:24.04` for another one): this is what checks that the PHP minimum and the dependencies of the packages are the real ones. `tests/Packaging/container-image` checks the container image.

## References

- http://opensimulator.org
- https://github.com/opensim/opensim
- http://opensimulator.org/wiki/UnofficialDebPackages
- http://opensimulator.org/wiki/Related_Software

**Main modules**

- **Search**: https://github.com/GuduleLapointe/OpenSimSearch, in `modules/OpenSimSearch` (fork of https://github.com/kcozens/OpenSimSearch, very old but still the source for builds)

**Currency modules**

Active:

- **Gloebit**: https://github.com/gloebit/opensim-moneymodule-gloebit
    - Compiled dlls https://github.com/gloebit/opensim-moneymodule-gloebit/releases
- **PayPal**: https://github.com/Outworldz/DTL-PayPal
- **DTL/NSL**: https://github.com/MTSGJ/opensim.currency
- **Podex Exchange**: https://www.podex.info/p/setting-up-money-server.html (service active, but see below for dll module)

Outdated:

- **Generic MoneyServer**: used by Podex Exchange and other money implementations as well as for fictional currencies. The Original svn repo vanished (http://www.nsl.tuis.ac.jp/svn/opensim/opensim.currency/trunk). Podex website mentions https://github.com/MTSGJ/opensim.currency, which is active, but has a disclaimer to investicate: "Web Monitor function (ASP.NET) is removed from original DTL Currency. So, this version is less secure than original version!! Please use this at Your Own Risk!!". Many other forks were made by various grid operators, a comparison of them could be useful to determine which is the best option to include or if we should create our own.
- **Virwox** (abandonned), as well as OMEconomy-Modules, make sure to remove any remaining references

**Additional tools**

- https://github.com/GuduleLapointe/opensim-helpers/
- https://github.com/GuduleLapointe/w4os/
- http://opensimulator.org/wiki/Webinterface
- https://github.com/Outworldz/os-webrtc-janus (webrtc voice)

**Alternative distributions**

- https://www.osgrid.org/download (most popular)
- https://github.com/Outworldz/DreamGrid-Opensim (dist 7.2 but based on opensim 0.9.3.1)
- https://github.com/diva (outdated)

**OpenSimulator.org resources**

- [Console commands reference](http://opensimulator.org/wiki/Server_Commands):
- [OSSL functions reference](http://opensimulator.org/wiki/Category:OSSL_Functions)
