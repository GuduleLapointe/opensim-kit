# Installation

The kit is installed from the packages of the Magiiic apt repository, or from a clone of this repository. The steps in short are in the [README](README.md); the details are here.

- [Quick start](#quick-start)
- [The packages](#the-packages)
- [The database, the files, .NET](#the-database-the-files-net)
- [The setup](#the-setup)
- [The web side of a grid](#the-web-side-of-a-grid)
- [Importing, and accounts in bulk](#importing-and-accounts-in-bulk)
- [Running a grid](#running-a-grid)
- [An install that was not made by the kit](#an-install-that-was-not-made-by-the-kit)
- [From git](#from-git)

## Quick start

On Debian or Ubuntu, from the Magiiic apt repository (add it once):

```bash
curl -fsSL https://apt.magiiic.com/magiiic-packaging.asc | sudo gpg --dearmor -o /usr/share/keyrings/magiiic-packaging.gpg
echo "deb [signed-by=/usr/share/keyrings/magiiic-packaging.gpg] https://apt.magiiic.com stable main" | sudo tee /etc/apt/sources.list.d/magiiic.list
sudo apt update
sudo apt install opensim-kit      # the tools, the latest OpenSimulator, a database server, a placeholder web site
sudo opensim setup                # choose "Quick setup": a grid and its first region, in one form
sudo systemctl start opensim      # the service starts the enabled instances (the setup already started them)
```

The quick setup asks for the name of the grid, where it is reached (`host:port`), its owner (an account, with a password and an optional email), then shows everything it chose for the rest (ports, names, database, folders...) for you to accept or edit. When it is done, you log in with a viewer to the login URI it tells.

What is left to do, if you want it: serve the economy and the search of the grid with the helpers (see [the web side](#the-web-side-of-a-grid)), add regions and simulators (the setup again, or a setup file), make accounts.

## The packages

- `opensim-tools`: the tools, in `/usr/share/opensim-tools`, and the `opensim` command. They work with the packaged OpenSimulator releases or any other one.
- `opensim-<version>`, e.g. `opensim-0.9.3.0`: an OpenSimulator release, in `/usr/share/opensim/<version>`. Several releases can be installed side by side, with or without the tools.
- `opensim`: the latest release
- `opensim-<version>-<module>`, e.g. `opensim-0.9.3.0-gloebit`, `opensim-0.9.3.0-opensimsearch`: a module for a release. Installing it does not enable it. It works with any build of the same version.
- `opensim-unstable`: OpenSimulator built from the development branch, in `/usr/share/opensim/unstable`
- `opensim-helpers`: the helpers of a grid (economy, search, destination guide, offline messages, message of the day), in `/usr/share/opensim-helpers`, to be served by a web server. Their settings are read from the kit (`/etc/opensim/grids/<grid>/helpers.ini`), nothing is to edit.
- `opensim-web`: a placeholder site for the grid (its name, how to connect, where its services are), until it has its own.
- `opensim-kit`: the tools, the latest release, its essential modules and a database server. The other modules are in the repository, not installed automatically.

## The database, the files, .NET

OpenSim and Robust cannot run without a database. The packages of OpenSimulator accept MySQL/MariaDB or PostgreSQL (and SQLite for regions), on this machine or another one; the setup of this kit works with MySQL/MariaDB (`opensim-kit` installs the server). The setup runs as the user who starts it, with that user's own rights on the database (or as root, `sudo opensim setup`): only the writing of the files and the start of the instances are done as the system user of the install, through `sudo -u`, as every other command. Before writing anything, it checks the account can log in, then that it can use the database. The account of the grid is always tried with its own credentials only, as OpenSimulator will, whatever is in the `~/.my.cnf` of the user. Only what is missing is created, one statement at a time, with an administrator account of the database server: the setup uses the access the user already has (root, or a database account of their own that may create accounts and databases, e.g. in their `~/.my.cnf`, or sudo without a password), and otherwise asks for the credentials of an administrator account, on this machine or another one, kept in memory for the session only. A problem of access can be tried again; a creation that fails ends the setup with the commands to run from an administrator account. A database prepared by someone else, with the credentials, needs none of it.

The files are at the usual places: config in `/etc/opensim`, data in `/var/lib/opensim`, logs in `/var/log/opensim`, cache in `/var/cache/opensim`. The instances run as the `opensim` account: the `opensim` command switches to it, through sudo.

OpenSimulator 0.9.3 needs the .NET 8 runtime and the native library `libgdiplus`, which the packages install (a region stops at once without it, the launcher says so). Ubuntu installs the runtime with the package; on Debian, install it (and `libgdiplus`, for an install that did not come from the packages) with:

```bash
sudo opensim install-dotnet
```

## The setup

`opensim setup` is a wizard: it goes one level deeper at a time (the core, the grids, a grid, a simulator, a region), and every screen ends with `Back` and `Quit`.

### Quick and advanced

`Add grid` proposes the quick setup first. One screen with the name of the grid, its login URI (`host:port`, the address the viewers log in to), the owner of the grid (First Last), their password and an optional email, to go through with Tab or Enter. The usual settings are chosen for everything else and shown together, for the grid and for its first simulator, before anything is written: `Continue` makes everything, `Edit config` asks every question again, with those settings as the answers proposed. The database is the setup's business: its user and password are the defaults, and the credentials of a database administrator are asked only if the database cannot be made without them.

The advanced setup asks every question: the core, the ports, the console, the database, the web URL, the path of the helpers, Hypergrid, the names. Once the grid is made it goes on to its first simulator and region; when the grid and its first region are made, the setup says so, with the login URI, and proposes `Finish setup` (or `Continue setup` for more).

### The keys of a terminal

`Escape` gives up the screen and goes back to the menu it came from, `Ctrl-Q` leaves the setup from anywhere. The editing keys of readline work in texts and passwords: `Ctrl-U`, `Ctrl-K`, `Ctrl-W` and `Alt-D` cut (a password too), `Ctrl-Y` pastes, `Ctrl-A`, `Ctrl-E`, `Alt-B`, `Alt-F` and the arrows move, `Ctrl-D` and `Ctrl-T` edit.

### Simulators and regions

A simulator has its own database (made when it does not exist) and its first region, and the owner of its estate is an account of the grid, chosen or created on the way (in the console of Robust, which is started for that if needed). The wizard writes the config of the simulator in `/etc/opensim/grids/<grid>/sims/`, links it into `/etc/opensim/opensim.d`, starts it and checks that its region is online in the grid. The first simulator and its first region are called `Welcome` unless you choose another name, and the region is the one visitors arrive in (the default region of the grid): without one nobody can log in.

More regions are added from the screen of the simulator (`Add region`), through its console when it runs, without restarting it. A new region gets the object of `share/ossl-scripts` (an archive made from `fix-parcel-name-src`, loaded through the console, whose script names the parcel after the region); OSSL is part of the standard config for that. What has to restart (a region moved, enabled or disabled) is listed, and the wizard offers to restart it when you quit. `opensim enable <instance>` and `opensim disable <instance>` do from the command line what the screens do.

Names are snake_case (`the_rapist`, not `therapist`): the nick of a grid and the name of an instance keep the words apart.

### A grid on another machine

On a machine that has no Robust of its own (a grid run elsewhere, on another host or in another container), `Add grid`, `Connect to an external grid`, needs no grid created first: it asks for the address of the grid and its public port, reads its name and nick from `get_grid_info`, asks for its private port (which only its simulators reach), and keeps that description under the nick of the grid for the next simulators (`/etc/opensim/grids/<nick>/<nick>.conf`). The estate of such a simulator is owned by an account of the grid that already exists, and the address the regions announce (the viewers connect to it) is asked, as `SYSTEMIP`, the first interface of the machine, is not the one of the world behind a NAT or in a container.

### A setup in a file

A setup can be described in a file, JSON or YAML (the example is `share/examples/setup.yaml`, in `/usr/share/opensim-tools/share/examples/`): the grid, its owner, its simulators and regions, and the accounts to make. `opensim import setup.yaml` brings it in: without `--apply` it tells what it would make, with `--apply` it makes it, with the questions of the wizard answered from the file. What the wizard does is kept in the folder of the grid, `setup.json` (readable by its owner only, it holds the database passwords), to make the same grid again.

## The web side of a grid

The `opensim-web` package is the default site, its root is `/var/www/html`: what the web server has no file for is routed to the helpers (`opensim-helpers`), which serve their pages (`/` the home, `/welcome` the splash page of the viewer) and their services, at the URL the grid chooses for each (`[Urls]` of its `helpers.ini`, `/helpers/<script>.php` by default). Put your own files next to its `index.php`.

The Robust config tells the viewers the URLs: `economy`, `welcome` (`[GridInfoService]`), `SearchURL`, `DestinationGuide`, `MessageUrl` (`[LoginService]`).

```bash
opensim web <grid>                # where the services are
opensim web <grid> check          # which ones answer
opensim web <grid> snippet caddy  # for the web server: caddy, nginx or apache
```

The setup writes the configuration of each server in `/etc/opensim/grids/<grid>/web/` (`<grid>.caddyfile`, `<grid>-nginx.conf`, `<grid>-apache.conf`): the root, `index.php` for what is not a file, PHP, the grid.

## Importing, and accounts in bulk

`opensim import [grid] FILE [--grid] [--simulator] [--users] [--apply]` brings what a file holds into the install. It tells what the file is:

- the config of a grid that was not made by the kit (a Robust ini), or of a simulator (an OpenSim ini): the files are not changed, the setup's settings are detected and go through the generators of the kit, the other settings of the original are kept in the new config, the places of the data stay where they are;
- a setup file of the kit (JSON or YAML), see above;
- a list of accounts (CSV with or without a header line, JSON or YAML: first, last, email, password), made directly in the database of the grid, with their inventory and default outfit, as Robust makes them. An account that exists is skipped; one without a password gets one, written in a result file (mode 600) and never on the screen. A password can also be given hashed, as Robust keeps it (`password_hash` and `password_salt`).

Everything the file holds is imported, or only what `--grid`, `--simulator` or `--users` ask for. The grid, first, is the nick of the grid to make, or the grid the simulators and accounts join; it can be left out when the file tells it or the machine has only one grid. Nothing is made without `--apply`.

## Running a grid

The ports of an instance are a block of ten, the same on any machine, so an instance can move to another one without changing any port. The REST console is always the port x4 of the block. Robust has a public port, ending with 2, and a private one, ending with 3 (the one its simulators use, which has no protection of its own): 8002, 8003, and 8004 for its console. A simulator has a single HTTP port, the first of its block, and its regions take the other ports: the first simulator is on 9000, its console on 9004, its regions on 9001, 9002, 9003 and 9005 to 9009, the next simulator on 9010... Simulators are looked for from 9000, where the users are used to find them: the thousand above their grid, in its hundred, so a grid whose Robust is on 8102 has its simulators from 9100. The next free block is chosen looking at the config files of the machine, the ports in use, and the regions the grid knows on its other machines.

`opensim ports [instance...]` tells what the enabled instances listen on and who has to reach it: the public ports (and the regions, in UDP, which are the ones the viewers send to) for everyone, the private port of Robust and the consoles only for the machines that need them. `--publish` gives the `-p` options of a container, `--ufw` the rules of a firewall. `opensim next port [-e PORT] [MIN] [COUNT]` gives the next free ports, and `opensim next <grid> location [X,Y] [COUNT] [-e X,Y]` the free places nearest to the one asked in a grid, by the same rules as the setup, for scripts.

The console of an instance is one of two kinds, asked by the wizard (remote by default). The remote one (REST) is reached through a port, x4 of the block of the instance, with a user and a password the wizard makes: it gives every right on the instance, so it works the same from this machine, from a container or from another machine, and its port is only published to the machines that need it. The other is a screen session, attached on the machine that runs the instance.

The commands take the instance (a grid or a simulator) right after the command:

```bash
opensim status
opensim start|stop|restart [now] [instance...]   # stop warns the users, and does not wait when nobody is there
opensim enable|disable <instance...>
opensim console <instance>                       # a screen session, or a prompt on the remote console (Ctrl-D leaves it)
opensim command <instance> <text>|-              # one command, or the lines of the standard input
OPENSIM_REST_PASSWORD=<password> opensim rest --url http://host:8024 --user admin -- show info
```

`opensim rest` is for an instance of another machine or a container. A remote console controls an instance that runs: it can stop it, but not start it again (that is for the service, or the container), nor change its files.

`opensim stop` warns the users, counting down to the shutdown (two minutes by default), and stops at once when nobody is in the regions of the simulator (the NPCs and the child agents are not counted; the remote console tells everything, a screen session only tells when no region has anyone). `opensim stop now` does not warn.

The `opensim` service starts the enabled instances at boot. Package upgrades never restart them.

## Backups and archives

There are two levels. The administrator backs up the install, the users have their own archives.

`opensim backup [GRID [SIM|REGION]] [--logs] [--archives] [--output DIR]` makes what it takes to install a grid again as it is, on another machine or after a crash: its configuration (the folder of the grid, the links that enable it, `opensim.conf`), its persistent data, and a dump of each of its databases (Robust and the simulators). One archive per grid, `gridnick-stamp.tar.gz`, or `gridnick-sim-stamp.tar.gz` for one simulator (`opensim backup mygrid sim1` or `opensim backup mygrid _sim1`), in `<data>/backups/admin` unless `--output` says otherwise. The logs are left out unless asked, so are the archives of the users, which are backups already. The archive holds the passwords of the databases: it is readable by its owner only.

The users' archives are made through the console of a running simulator, so the files are written where the simulator can write (its own `bin` folder is not): `opensim save|load iar|oar`, with the options of the console of the simulator. The instance (a grid, and a simulator or a region of it) comes after `iar` or `oar`, it is left out when there is only one grid (and one running simulator, for a region).

```bash
opensim save iar mygrid Jane Doe / secret --noassets        # the inventory of an account, the password is asked when left out
opensim load iar mygrid --merge Jane Doe / secret           # the newest archive of that inventory
opensim save oar mygrid Welcome --noassets     # a region (or a simulator, with --region when it has several, or --all)
opensim load oar mygrid Welcome --merge        # the newest archive of that region
```

Without a file name, an archive is made in `<data of the grid>/backups/iar` or `oar` and named after what it is, the special modes in suffixes so that nobody restores a `noassets` archive by mistake: `gridnick-first-last[-noassets][-perm<P>][-skipbadassets]-stamp.iar` and `gridnick-sim[-region][-noassets][-perm<P>][-publish]-stamp.oar` (no region in the name of a multi-region archive, `--all`), the stamp being `YYYYMMDD-HHMMSS`. A file name without a folder is in those folders, a relative path is from where the command is typed. Without a file name, `load` takes the newest archive of that account or region, and says which.

## An install that was not made by the kit

An existing install, e.g. in `/opt/opensim`, keeps working with the packaged tools without moving anything: its `opensim.conf` gives the locations. `opensim profile add <name> --core DIR [--etc DIR] [--data DIR]` registers it (the config and data directories are the core one in an install by the book, `/path/to/opensim/bin`), `opensim profile list|default|remove` manage the profiles. To run it with the service, set its account with `sudo systemctl edit opensim` (`User=` and `Group=` in a `[Service]` section).

## From git

Requires PHP 8.2 or newer, with the `ctype`, `iconv`, `json` and `mbstring` extensions, and those of the engine: `curl`, `filter`, `gettext`, `intl`, `mysqli`, `pdo`, `session` and `simplexml`. `posix` is used when available. The packages install what they need.

On Debian and Ubuntu: `sudo apt install php-cli php-curl php-intl php-mbstring php-mysql php-xml`.

```shell
git clone --recursive https://github.com/GuduleLapointe/opensim-kit.git
cd opensim-kit
composer install   # PHP libraries and bash-tools
export PATH=$PATH:$PWD/bin
opensim setup
```

Answer the common setting questions.

Configuration should be working as is, but you will probably want to adjust

- ./etc/opensim/opensim.conf
  (main database configuration)
- ./etc/opensim/robust.d/*.ini (robust settings)
- ./etc/opensim/opensim.d/*.ini (simulators settings)

```shell
opensim start
```

To enable bash completion:

```shell
sudo apt update
sudo apt install bash-completion
sudo ln -s $PWD/share/bash_completion.d/opensim /etc/bash_completion.d/
```
