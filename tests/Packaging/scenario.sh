#!/usr/bin/env bash
# Package test scenario, run by tests/Packaging/run inside the test container,
# with the packages in /dist.

source /test/lib.sh
core=$(deb opensim-0.9.3.0)
# The tools with the packages of the family they depend on (the helpers, the engine, rest-php)
tools="$(deb opensim-rest-php) $(deb opensim-engine) $(deb opensim-helpers) $(deb opensim-tools)"
# The metapackages, with the local packages they depend on
metas="$(deb opensim) $(deb opensim-0.9.3.0-opensimsearch) $(deb opensim-0.9.3.0-gloebit) $(deb opensim-kit)"
# The dotnet process only: the screen session running it has the same arguments
robust_pid() { pgrep -f "^dotnet .*Robust.dll" | head -1; }
robust_log() { ls /var/log/opensim/testgrid_robust*.log 2>/dev/null | grep -v Stats | head -1; }
quits() { cat /var/log/opensim/testgrid_robust*.log 2>/dev/null | grep -c '\[CONSOLE\] Quitting'; }
# What runs, to find out when an instance goes
state() { echo "   [Robust: $(robust_pid), simulator: $(sim_pid)] $*"; }
# The simulator of the grid made by the wizard, and its region
sim_pid() { pgrep -f "^dotnet .*OpenSim.dll -inifile=/etc/opensim/opensim.d/testgrid_sim1.ini" | head -1; }
sim_registered() { grep -c 'Region Sim1 .* registered at 8002,8002' /var/log/opensim/testgrid_sim1.log 2>/dev/null; }
robust_state() { echo "   Robust pid: $(robust_pid || true), clean shutdowns: $(quits)"; }
profile() { grep -q '^\[opensim-0.9.3.0\]' /etc/opensim/opensim.conf 2>/dev/null; }

magiiic_repository

ts "core alone"
apt_q install "$core"
check "core installed" "[ -f /usr/share/opensim/0.9.3.0/bin/OpenSim.exe ]"
check "no tools" "! command -v opensim >/dev/null && [ ! -e /etc/opensim ]"

ts "tools, then the metapackages"
apt_q install $tools
check "opensim account" "getent passwd opensim >/dev/null"
check "profile of the core" "profile"
check "default profile" "grep -q '^DefaultProfile = opensim-0.9.3.0' /etc/opensim/opensim.conf"
check "service enabled" "systemctl -q is-enabled opensim"
apt_q install $metas
check "opensim-kit installed" "dpkg -s opensim-kit >/dev/null 2>&1"

ts dotnet
opensim install-dotnet -y 2>&1 | tail -1
check ".NET runtime" "runuser -u opensim -- dotnet --list-runtimes | grep -q NETCore.App"

# The web side of a grid: the default site (the helpers came with the tools), installed before the grid so that its
# setup writes its helpers.ini (the user of the instances joins the group of the web server)
ts "web packages"
apt_q install "$(deb opensim-web)"
check "the helpers and the default site are installed, the helpers have no config of their own" "[ -f /usr/share/opensim-helpers/query.php ] &&
    [ ! -e /usr/share/opensim-helpers/includes/config.php ] && [ -f /var/www/html/index.php ]"

ts "grid from the wizard"
systemctl start mariadb
mysql -e "CREATE DATABASE testgrid_robust; CREATE USER opensim@localhost IDENTIFIED BY 'testpass';
    GRANT ALL ON testgrid_robust.* TO opensim@localhost;"
(cd /var/lib/opensim && runuser -u opensim -- php /test/newgrid.php | tail -1)
check "grid enabled" "[ -L /etc/opensim/robust.d/testgrid.ini ]"
check "the setup wrote the helpers.ini of the grid, for the group of the web server" "grep -q 'grid_name = \"Testgrid\"' /etc/opensim/grids/testgrid/helpers.ini &&
    [ \"\$(stat -c '%G %a' /etc/opensim/grids/testgrid/helpers.ini)\" = 'www-data 640' ]"
check "the Robust config tells the viewers where the services are" "grep -q '^ *economy = \"\${Const|WebURL}/helpers\"' /etc/opensim/grids/testgrid/Robust.HG.ini &&
    grep -q '^ *SearchURL = \"\${Const|WebURL}/helpers/query.php\"' /etc/opensim/grids/testgrid/Robust.HG.ini"
check "the setup wrote the files for the web server, in the folder of the grid" "grep -q 'OPENSIM_GRID' /etc/opensim/grids/testgrid/web/testgrid.caddyfile &&
    grep -q 'fastcgi_param OPENSIM_GRID testgrid' /etc/opensim/grids/testgrid/web/testgrid-nginx.conf && [ -s /etc/opensim/grids/testgrid/web/testgrid-apache.conf ]"
cat >/tmp/helpers-settings.php <<'EOF'
<?php
chdir('/usr/share/opensim-helpers');
require 'includes/bootstrap.php';
echo OPENSIM_GRID_NAME;
EOF
check "the helpers read the settings of the grid as the user of the web server" "[ \"\$(OPENSIM_GRID=testgrid runuser -u www-data -- php /tmp/helpers-settings.php)\" = Testgrid ]"
check "opensim web tells where the services are, and writes what the web server needs" "opensim web | grep -q '/helpers/query.php' &&
    opensim web snippet caddy | grep -q 'env OPENSIM_GRID testgrid' && opensim web snippet nginx | grep -q 'fastcgi_param OPENSIM_GRID testgrid;'"
(cd /var/www/html && OPENSIM_GRID=testgrid nohup php -S 127.0.0.1:8090 index.php >/tmp/php-web.log 2>&1 &)
sleep 2
check "the default site shows the grid, the splash page too, the helpers answer under their prefix" "curl -s http://127.0.0.1:8090/ | grep -q Testgrid &&
    curl -s http://127.0.0.1:8090/welcome | grep -q Testgrid && [ \"\$(curl -s -o /dev/null -w %{http_code} http://127.0.0.1:8090/helpers/motd.php)\" = 200 ]"
pkill -f 'php -S 127.0.0.1:8090'
check "no file of the grid has Windows line endings, which the files of the core have" "[ -z \"\$(grep -rlI \"\$(printf '\\r')\" /etc/opensim/grids/testgrid)\" ]"

# The database, as the setup checks it (see Grid/Database.php). As opensim,
# who has no administrator access to the database server: what cannot be
# checked or fixed can be tried again, and nothing is written
wizard() { # grid, password, [user]
    (cd /var/lib/opensim && TEST_GRID=$1 TEST_DB_PASSWORD=$2 TEST_NO_ENABLE=1 TEST_REPEAT=${TEST_REPEAT:-1} \
        TEST_DB_USER=${TEST_DB_USER:-} runuser -u "${3:-opensim}" -- php /test/newgrid.php 2>&1)
}
as_root() { # grid, password
    (cd /var/lib/opensim && TEST_GRID=$1 TEST_DB_PASSWORD=$2 TEST_NO_ENABLE=1 TEST_DB_USER=${TEST_DB_USER:-} php /test/newgrid.php 2>&1)
}
wizard Badgrid wrong >/tmp/badgrid.out
check "no administrator access, wrong password: nothing written" "
    grep -q 'cannot run without its database' /tmp/badgrid.out && [ ! -e /etc/opensim/grids/badgrid ]"
wizard Missinggrid testpass >/tmp/missing.out
check "no administrator access, missing database: says so, gives the commands" "grep -q 'no access to database missinggrid_robust' /tmp/missing.out &&
    grep -q 'CREATE DATABASE' /tmp/missing.out && [ ! -e /etc/opensim/grids/missinggrid ]"

# The password of an account, generated once, is proposed again in the same session
TEST_REPEAT=2 wizard Repeatgrid "" >/tmp/repeat.out
check "the generated password is kept for the session" "[ \"\$(grep -a 'Database password ->' /tmp/repeat.out | sort -u | wc -l)\" = 1 ] &&
    [ \"\$(grep -ac 'Database password ->' /tmp/repeat.out)\" -ge 2 ]"

# As root, with the administrator access: only what is missing is created,
# one statement at a time
as_root Rootgrid testpass >/tmp/rootgrid.out
check "valid account, missing database: the database is created, not the account" "mysql -e 'SHOW DATABASES' | grep -q '^rootgrid_robust\$' &&
    ! grep -qi 'create user' /tmp/rootgrid.out"
check "what the setup writes belongs to opensim" "[ \"\$(stat -c %U /etc/opensim/grids/rootgrid/Robust.HG.ini)\" = opensim ] &&
    [ \"\$(stat -c %U /var/lib/opensim/data/rootgrid)\" = opensim ]"
TEST_DB_USER=grid2user as_root Grid2 "" >/tmp/grid2.out
pass=$(grep -a 'Database password ->' /tmp/grid2.out | head -1 | sed 's/.*-> //')
check "missing account and database: both created, the account logs in" "MYSQL_PWD='$pass' mysql -u grid2user -e 'SELECT 1' grid2_robust >/dev/null 2>&1"
as_root Rootgrid wrong >/tmp/rootwrong.out
check "existing account, wrong password: not recreated, can be tried again" "grep -q 'password is rejected' /tmp/rootwrong.out &&
    ! grep -qi 'create user' /tmp/rootwrong.out"
# An account of the caller's own that can read the server's accounts but not
# create them: the administrator access is found, the creation fails
useradd -m -s /bin/bash dbhelper
mysql -e "CREATE USER dbhelper@localhost IDENTIFIED VIA unix_socket; GRANT SELECT ON mysql.* TO dbhelper@localhost"
TEST_DB_USER=deniedgrid_user wizard Deniedgrid "" dbhelper >/tmp/denied.out
check "a creation that fails ends the setup, with the commands and an error code" "grep -q 'Could not create the user' /tmp/denied.out &&
    grep -q 'CREATE USER' /tmp/denied.out && ! grep -q 'Try again' /tmp/denied.out && grep -q 'setup failed' /tmp/denied.out &&
    [ ! -e /etc/opensim/grids/deniedgrid ]"

# A user who is neither root nor the system user, with a database account of
# their own that may create accounts and databases, and the right to run
# things as opensim (what `opensim start` needs anyway). The database is
# handled with their own rights, no sudo to root; only the writing of the
# files goes to opensim.
useradd -m -s /bin/bash dbadmin
echo 'dbadmin ALL=(opensim) NOPASSWD: ALL' >/etc/sudoers.d/dbadmin && chmod 440 /etc/sudoers.d/dbadmin
mysql -e "CREATE USER dbadmin@localhost IDENTIFIED VIA unix_socket; GRANT ALL ON *.* TO dbadmin@localhost WITH GRANT OPTION"
(cd /var/lib/opensim && TEST_GRID=Adminsgrid TEST_DB_PASSWORD= TEST_NO_ENABLE=1 TEST_DB_USER=adminsuser runuser -u dbadmin -- php /test/newgrid.php >/tmp/adminsgrid.out 2>&1
    echo "exit code: $?" >>/tmp/adminsgrid.out)
pass=$(grep -a 'Database password ->' /tmp/adminsgrid.out | head -1 | sed 's/.*-> //')
check "own database account, no sudo to root: account and database created" "MYSQL_PWD='$pass' mysql -u adminsuser -e 'SELECT 1' adminsgrid_robust >/dev/null 2>&1"
check "the files are written by the system user, the setup ends well" "grep -q 'exit code: 0' /tmp/adminsgrid.out &&
    [ \"\$(stat -c %U /etc/opensim/grids/adminsgrid/Robust.HG.ini)\" = opensim ] && [ \"\$(stat -c %U /var/lib/opensim/data/adminsgrid)\" = opensim ]"
rm -rf /etc/opensim/grids/adminsgrid /var/lib/opensim/data/adminsgrid /var/cache/opensim/adminsgrid

# A user with no administrator access of their own, and a ~/.my.cnf with an
# outdated password. The grid's account is checked with its own credentials
# only, not the ones of the file; the administrator account is asked, its
# password is not shown, and it works from another machine's point of view
# (credentials given, nothing implicit)
useradd -m -s /bin/bash plainuser
echo 'plainuser ALL=(opensim) NOPASSWD: ALL' >/etc/sudoers.d/plainuser && chmod 440 /etc/sudoers.d/plainuser
printf '[client]\nuser=plainuser\npassword=outdated\n' >/home/plainuser/.my.cnf
chown plainuser: /home/plainuser/.my.cnf && chmod 600 /home/plainuser/.my.cnf
mysql -e "CREATE USER dbroot@localhost IDENTIFIED BY 'adminpw'; GRANT ALL ON *.* TO dbroot@localhost WITH GRANT OPTION"
mysql -e "CREATE USER cnfuser@localhost IDENTIFIED BY 'cnfpass'; CREATE DATABASE cnfgrid_robust; GRANT ALL ON cnfgrid_robust.* TO cnfuser@localhost"
TEST_DB_USER=cnfuser wizard Cnfgrid cnfpass plainuser >/tmp/cnfgrid.out
check "an outdated ~/.my.cnf does not disturb the account of the grid" "grep -q 'cnfgrid_robust on localhost: connection OK' /tmp/cnfgrid.out"
rm -rf /etc/opensim/grids/cnfgrid /var/lib/opensim/data/cnfgrid /var/cache/opensim/cnfgrid
TEST_ADMIN_USER=dbroot TEST_ADMIN_PASSWORD=wrongpw TEST_DB_USER=askedwrong_user wizard Askedwrong "" plainuser >/tmp/askedwrong.out
check "a wrong administrator password: refused, nothing created, the commands are shown" "grep -q 'Login refused for dbroot' /tmp/askedwrong.out &&
    grep -q 'CREATE USER' /tmp/askedwrong.out && [ -z \"\$(mysql -BN -e \"SELECT 1 FROM mysql.user WHERE User='askedwrong_user'\")\" ]"
TEST_ADMIN_USER=dbroot TEST_ADMIN_PASSWORD=adminpw TEST_DB_USER=asked_user wizard Asked "" plainuser >/tmp/asked.out
pass=$(grep -a 'Database password ->' /tmp/asked.out | head -1 | sed 's/.*-> //')
check "no administrator access: the credentials are asked, account and database created" "MYSQL_PWD='$pass' mysql -u asked_user -e 'SELECT 1' asked_robust >/dev/null 2>&1"
check "the administrator password is neither shown nor written" "! grep -rqa adminpw /tmp/asked.out /etc/opensim/grids/asked"
rm -rf /etc/opensim/grids/asked /var/lib/opensim/data/asked /var/cache/opensim/asked

# The setup started as root, as on the packaged install, starts the grid and
# sees it run (a screen session is per user, the setup is not the one running it)
(cd /var/lib/opensim && TEST_GRID=Startgrid TEST_DB_PASSWORD= TEST_START=1 TEST_DB_USER=startuser php /test/newgrid.php >/tmp/startgrid.out 2>&1
    echo "exit code: $?" >>/tmp/startgrid.out)
check "the setup reports the grid it started as running, exit code 0" "grep -q \"Grid 'startgrid' is running\" /tmp/startgrid.out &&
    grep -q 'exit code: 0' /tmp/startgrid.out && [ -n \"\$(robust_pid)\" ]"
opensim stop now startgrid >/dev/null 2>&1
check "the launcher fails for an instance that does not exist" "! opensim start nosuchinstance >/dev/null 2>&1"

# Robust is ready when every service its config lists has reported it loaded,
# whatever options it runs with (here without Hypergrid: none of its
# connectors listed), and the ones that failed to load are named
ini=/etc/opensim/grids/startgrid/Robust.HG.ini
cp $ini /tmp/Robust.startgrid.good
sed -i '/UserAgentServerConnector/d;/GatekeeperServiceInConnector/d' $ini
opensim start startgrid >/tmp/nohg.out 2>&1; nohg=$?
check "a grid without Hypergrid is ready, not waited for" "[ $nohg = 0 ] && grep -aq 'startgrid started' /tmp/nohg.out"
opensim stop now startgrid >/dev/null 2>&1
cp /tmp/Robust.startgrid.good $ini
sed -i 's/Password=[^;"]*;/Password=wrongpass;/' $ini
opensim start startgrid >/tmp/baddb.out 2>&1; baddb=$?
check "a grid whose database cannot be used is reported, with the failed services" "[ $baddb != 0 ] &&
    grep -aq 'services failed to load' /tmp/baddb.out && grep -aq 'AssetServiceConnector: failed to load' /tmp/baddb.out"
opensim stop now startgrid >/dev/null 2>&1
cp /tmp/Robust.startgrid.good $ini
rm -rf /etc/opensim/grids/startgrid /etc/opensim/robust.d/startgrid.ini /var/lib/opensim/data/startgrid /var/cache/opensim/startgrid
rm -rf /etc/opensim/grids/rootgrid /var/lib/opensim/data/rootgrid /var/cache/opensim/rootgrid \
    /etc/opensim/grids/grid2 /var/lib/opensim/data/grid2 /var/cache/opensim/grid2
check "setup command" "opensim help | grep -q setup"

ts "service start"
systemctl start opensim
opensim status | tail -2
pid=$(robust_pid)
check "Robust running" "[ -n '$pid' ]"
check "grid core" "grep -q 'Starting in /usr/share/opensim/0.9.3.0/bin' $(robust_log)"
check "Robust ready" "grep -q 'UserAgentServerConnector loaded' $(robust_log)"
check "no write denied" "! grep -qiE 'denied|unauthorized' $(robust_log)"

# A simulator joins the grid, with a new account of the grid as owner of its
# estate (made through the console of Robust), a database of its own (the
# administrator account is asked), and starts with its region
ts "simulator in the grid"
owner_password='Pa ss^w0rd\z'
(cd /var/lib/opensim && TEST_GRID=testgrid TEST_SIM=Sim1 TEST_START=1 TEST_NEW_OWNER="Test Owner" TEST_NEW_OWNER_PASSWORD="$owner_password" \
    TEST_ADMIN_USER=dbroot TEST_ADMIN_PASSWORD=adminpw runuser -u opensim -- php /test/newsim.php >/tmp/sim1.out 2>&1
    echo "exit code: $?" >>/tmp/sim1.out)
check "the simulator wizard ends well, the region is online" "grep -q 'exit code: 0' /tmp/sim1.out &&
    grep -q 'region Sim1 is online' /tmp/sim1.out && [ -n '$(sim_pid)' ]"
check "the region is registered in the grid" "[ \"\$(mysql -BN -e \"SELECT CONCAT(locX DIV 256, ',', locY DIV 256) FROM testgrid_robust.regions WHERE regionName='Sim1'\")\" = 8002,8002 ]"
# The simulator wizard gives the roles of the default region (not the fallback one, which is not checked by default)
# to the first region, the one that lets visitors in: without it a login fails with "destination not found"
check "the simulator knows where the offline messages and the search index of the grid are" "grep -q '^OfflineMessageURL = \"https://localhost/helpers/offline.php\"' /etc/opensim/grids/testgrid/sims/testgrid_sim1.ini &&
    grep -q '^DATA_SRV_Testgrid = \"https://localhost/helpers/register.php\"' /etc/opensim/grids/testgrid/sims/testgrid_sim1.ini"
check "the first region is the default region of the grid, and Robust says so" "grep -q '^Region_Sim1 = \"DefaultRegion, DefaultHGRegion, Persistent\"' /etc/opensim/grids/testgrid/Robust.HG.ini &&
    [ \"\$(mysql -BN -e \"SELECT (flags & 1 AND flags & 1024) FROM testgrid_robust.regions WHERE regionName='Sim1'\")\" = 1 ] &&
    curl -s -d 'METHOD=get_default_regions&SCOPEID=00000000-0000-0000-0000-000000000000' http://127.0.0.1:8003/grid | grep -q '>Sim1<'"
# Robust gives a home to an account from its default region when it makes it: the owner of the first estate was
# made before that region existed, so the setup gives it that home; an account made later gets it from Robust
home_is_default() {
    [ "$(mysql -BN -e "SELECT g.HomeRegionID = r.uuid FROM testgrid_robust.GridUser g JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = g.UserID JOIN testgrid_robust.regions r ON r.regionName = 'Sim1' WHERE u.FirstName = '$1' AND u.LastName = '$2'")" = 1 ]
}
check "the owner of the estate has the default region as home" "home_is_default Test Owner"
check "the simulator has its own database, its config is enabled" "mysql -e 'SELECT 1' testgrid_sim1 >/dev/null 2>&1 &&
    [ -L /etc/opensim/opensim.d/testgrid_sim1.ini ] && [ -f /etc/opensim/grids/testgrid/sims/testgrid_sim1/regions/Sim1.ini ]"
check "the estate belongs to the account made in the grid" "[ -n \"\$(mysql -BN -e \"SELECT PrincipalID FROM testgrid_robust.UserAccounts WHERE FirstName='Test' AND LastName='Owner'\")\" ] &&
    [ \"\$(mysql -BN -e 'SELECT EstateOwner FROM testgrid_sim1.estate_settings LIMIT 1')\" = \"\$(mysql -BN -e \"SELECT PrincipalID FROM testgrid_robust.UserAccounts WHERE FirstName='Test' AND LastName='Owner'\")\" ]"
# OpenSimulator keeps MD5(MD5(password):salt): the password typed in the
# console of Robust, with its space, caret and backslash, is the one given
owner_salt=$(mysql -BN -e "SELECT a.passwordSalt FROM testgrid_robust.auth a JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = a.UUID WHERE u.FirstName='Test' AND u.LastName='Owner'")
owner_hash=$(mysql -BN -e "SELECT a.passwordHash FROM testgrid_robust.auth a JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = a.UUID WHERE u.FirstName='Test' AND u.LastName='Owner'")
check "the password of the account, with a space, a caret and a backslash, is kept as typed" "[ -n '$owner_hash' ] &&
    [ '$owner_hash' = \"\$(printf '%s:%s' \"\$(printf %s \"\$owner_password\" | md5sum | cut -d' ' -f1)\" '$owner_salt' | md5sum | cut -d' ' -f1)\" ]"
check "the region loads the enabled modules" "grep -q 'Plugin Loaded: OpenSimSearch' /var/log/opensim/testgrid_sim1.log"
check "nothing written in the read-only core" "! grep -iE 'unauthorized|denied' /var/log/opensim/testgrid_sim1.log | grep -viE 'gloebit'"
check "the native libraries are found" "[ -L /var/lib/opensim/native/0.9.3.0/libBulletSim.so ] && ! grep -q 'DllNotFound' /var/log/opensim/testgrid_sim1.log"
sim=$(sim_pid)

# More regions are added to the running simulator without restarting it, and
# the console of an instance can be reached
(cd /var/lib/opensim && TEST_GRID=testgrid TEST_SIM=Sim1 TEST_ADD_REGION=Sim1North runuser -u opensim -- php /test/newsim.php >/tmp/region2.out 2>&1
    echo "exit code: $?" >>/tmp/region2.out)
check "a region is added to the running simulator, and online" "grep -q 'exit code: 0' /tmp/region2.out && grep -q 'Region Sim1North is online' /tmp/region2.out &&
    [ \"\$(mysql -BN -e \"SELECT CONCAT(locX DIV 256, ',', locY DIV 256) FROM testgrid_robust.regions WHERE regionName='Sim1North'\")\" = 8003,8002 ] && [ '$(sim_pid)' = '$sim' ]"
check "the parcel of the new region is named after it by the object of the setup, without a restart" "grep -q 'Parcel of region Sim1North is named Sim1North' /tmp/region2.out &&
    [ \"\$(mysql -BN -e \"SELECT Name FROM testgrid_sim1.land WHERE RegionUUID = (SELECT uuid FROM testgrid_robust.regions WHERE regionName='Sim1North')\")\" = Sim1North ]"
(cd /var/lib/opensim && TEST_GRID=testgrid runuser -u opensim -- php /test/account.php >/tmp/account.out 2>&1; echo "exit code: $?" >>/tmp/account.out)
check "an account made after the default region has it as home" "grep -q 'exit code: 0' /tmp/account.out && home_is_default Later User"
# Accounts made from a list, directly in the database (no console): their inventory, their password, their home
printf 'first,last,email,password\nBulk,One,one@example.org,bulkpw1\nBulk,Two,,\n' >/tmp/bulk.csv
(cd /tmp && runuser -u opensim -- opensim import testgrid /tmp/bulk.csv >/tmp/bulk-dry.out 2>&1; echo "exit code: $?" >>/tmp/bulk-dry.out
    runuser -u opensim -- opensim import testgrid /tmp/bulk.csv --apply --result /tmp/bulk-result.csv >/tmp/bulk.out 2>&1; echo "exit code: $?" >>/tmp/bulk.out)
check "a list is checked first, and nothing is written without --apply" "grep -q 'would create' /tmp/bulk-dry.out && grep -q 'Nothing was written' /tmp/bulk-dry.out"
check "the accounts of the list are made in the database, with their inventory" "grep -q 'exit code: 0' /tmp/bulk.out &&
    [ \"\$(mysql -BN -e \"SELECT COUNT(*) FROM testgrid_robust.UserAccounts WHERE FirstName='Bulk'\")\" = 2 ] &&
    [ \"\$(mysql -BN -e \"SELECT COUNT(*) FROM testgrid_robust.inventoryfolders f JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = f.agentID WHERE u.FirstName='Bulk' AND u.LastName='One'\")\" = 21 ] &&
    [ \"\$(mysql -BN -e \"SELECT COUNT(*) FROM testgrid_robust.inventoryitems i JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = i.avatarID WHERE u.FirstName='Bulk' AND u.LastName='One'\")\" = 12 ]"
bulk_salt=$(mysql -BN -e "SELECT a.passwordSalt FROM testgrid_robust.auth a JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = a.UUID WHERE u.FirstName='Bulk' AND u.LastName='One'")
bulk_hash=$(mysql -BN -e "SELECT a.passwordHash FROM testgrid_robust.auth a JOIN testgrid_robust.UserAccounts u ON u.PrincipalID = a.UUID WHERE u.FirstName='Bulk' AND u.LastName='One'")
check "the password of a listed account is kept as Robust keeps it" "[ -n '$bulk_hash' ] &&
    [ '$bulk_hash' = \"\$(printf '%s:%s' \"\$(printf %s bulkpw1 | md5sum | cut -d' ' -f1)\" '$bulk_salt' | md5sum | cut -d' ' -f1)\" ]"
check "a listed account has the default region as home" "home_is_default Bulk One"
check "the result is private, and holds the passwords made here, not the ones given" "[ \"\$(stat -c %a /tmp/bulk-result.csv)\" = 600 ] && ! grep -q bulkpw1 /tmp/bulk-result.csv && grep -q '^Bulk,Two' /tmp/bulk-result.csv"
(cd /tmp && runuser -u opensim -- opensim import testgrid /tmp/bulk.csv --apply >/tmp/bulk-again.out 2>&1)
check "an account that exists is skipped" "grep -q 'exists' /tmp/bulk-again.out && [ \"\$(mysql -BN -e \"SELECT COUNT(*) FROM testgrid_robust.UserAccounts WHERE FirstName='Bulk'\")\" = 2 ]"
rm -f users-result-*.csv /tmp/users-result-*.csv

# The search, on the grid as it is: Robust, a simulator, the accounts (STOP_AFTER=search ends the scenario here)
source /test/search.sh
[[ ${STOP_AFTER:-} != search ]] || { ts "done after the search: $([ $FAILED = 0 ] && echo 'all checks passed' || echo 'SOME CHECKS FAILED')"; exit $FAILED; }

# A region that exists is changed in place: its place, not its identity
regionfile=/etc/opensim/grids/testgrid/sims/testgrid_sim1/regions/Sim1North.ini
regionuuid=$(grep -m1 '^RegionUUID' $regionfile)
(cd /var/lib/opensim && TEST_GRID=testgrid TEST_SIM=Sim1 TEST_RECONFIGURE_REGION=Sim1North TEST_LOCATION=8002,8003 runuser -u opensim -- php /test/newsim.php >/tmp/region3.out 2>&1
    echo "exit code: $?" >>/tmp/region3.out)
check "a region is reconfigured in place, its UUID kept" "grep -q 'exit code: 0' /tmp/region3.out && grep -q '^Location = 8002,8003' $regionfile && [ \"\$(grep -m1 '^RegionUUID' $regionfile)\" = '$regionuuid' ]"
# The next free place and port follow the rules of the setup: the place nearest to the first one of the grid,
# with both the places the registry has and the ones the region files have (Sim1North is registered at
# 8003,8002 until its simulator restarts, its file says 8002,8003)
check "opensim next location gives the free place nearest to the first one" "[ \"\$(opensim next testgrid location)\" = 8001,8002 ]"
# The archives of the users are made in the folder of the grid, the backup of the administrator beside the data
(cd /tmp && runuser -u opensim -- opensim save oar testgrid Sim1 --region Sim1 >/tmp/saveoar.out 2>&1; echo "exit code: $?" >>/tmp/saveoar.out)
check "opensim save oar makes the archive of a region in the folder of the grid" "grep -q 'exit code: 0' /tmp/saveoar.out &&
    ls /var/lib/opensim/data/testgrid/backups/oar/testgrid-sim1-Sim1-*.oar >/dev/null 2>&1"
(cd /tmp && runuser -u opensim -- opensim load oar testgrid Sim1 --region Sim1 --merge >/tmp/loadoar.out 2>&1; echo "exit code: $?" >>/tmp/loadoar.out)
check "opensim load oar loads the newest one" "grep -q 'exit code: 0' /tmp/loadoar.out && grep -q 'Successfully loaded archive' /tmp/loadoar.out"
(cd /tmp && runuser -u opensim -- opensim backup testgrid >/tmp/backup.out 2>&1; echo "exit code: $?" >>/tmp/backup.out)
check "opensim backup makes an archive of the grid with its configuration, its data and its databases" "grep -q 'exit code: 0' /tmp/backup.out &&
    f=\$(ls /var/lib/opensim/data/backups/admin/testgrid-*.tar.gz | head -1) && tar -tzf \$f | grep -q '^etc/grids/testgrid/Robust' &&
    tar -tzf \$f | grep -q '^db/testgrid_robust.sql.gz'"
check "opensim next port gives a free port" "[ \"\$(opensim next port 9100)\" = 9100 ]"
# The ports of an instance are a block of ten, the same on any machine: Robust
# 8002 public and 8003 private; the first simulator 9000, 9004 for its console
# (kept, not enabled), its regions 9001 and 9002, in UDP
opensim ports >/tmp/ports.out 2>&1
check "opensim ports tells what to open, by block of ten, the regions in UDP" "grep -qE '8002 +tcp +public' /tmp/ports.out && grep -qE '8003 +tcp +private' /tmp/ports.out &&
    grep -qE '9000 +tcp +public' /tmp/ports.out && grep -qE '9004 +tcp +off' /tmp/ports.out &&
    grep -qE '9001 +udp +public' /tmp/ports.out && grep -qE '9002 +udp +public' /tmp/ports.out"
check "the private port is published to the local machine only" "opensim ports --publish | grep -q -- '-p 127.0.0.1:8003:8003' && opensim ports --publish | grep -q -- '-p 9001:9001/udp'"
check "a command reaches the console of a running instance" "opensim command testgrid_sim1 'show info' && sleep 1 &&
    runuser -u opensim -- screen -S testgrid_sim1 -X hardcopy /tmp/console.txt && grep -aq 'Version: OpenSim' /tmp/console.txt"
check "a command to an instance that does not run fails" "! opensim command nosuchinstance 'show info' >/dev/null 2>&1"
(sleep 2; printf '\001d') | script -qec "TERM=xterm opensim console testgrid_sim1" /dev/null >/tmp/attach.out 2>&1
check "the console attaches, and leaves the instance running" "grep -aq 'detached from' /tmp/attach.out && [ '$(sim_pid)' = '$sim' ]"

# screen takes a name for the start of one: stopping a grid that does not run
# must not stop the simulator whose name begins the same
opensim stop now testgrid >/dev/null 2>&1
opensim stop now testgrid >/dev/null 2>&1
check "stopping a grid that is not running leaves its simulator alone" "[ -n '$(sim_pid)' ] && [ '$(sim_pid)' = '$sim' ]"
opensim start testgrid >/dev/null 2>&1
pid=$(robust_pid)

ts upgrade
# --allow-downgrades: apt treats a file of the version a repository has too, but another build of it, as a downgrade
apt_q install --reinstall --allow-downgrades $tools "$core"
check "Robust not restarted" "[ '$(robust_pid)' = '$pid' ]"
check "the simulator not restarted" "[ '$(sim_pid)' = '$sim' ]"

ts "development build and modules"
check "modules installed by opensim-kit" "dpkg -s opensim-0.9.3.0-gloebit opensim-0.9.3.0-opensimsearch >/dev/null 2>&1"
unstable=$(deb opensim-unstable)
apt_q install "$unstable"
state after installing the development build
check "profile of the development build" "grep -q '^\[opensim-unstable\]' /etc/opensim/opensim.conf"
check "modules in their own folders" "[ -f /usr/share/opensim-modules/0.9.3.0/opensimsearch/OpenSimSearch.Modules.dll ] &&
    [ -f /usr/share/opensim-modules/0.9.3.0/gloebit/Gloebit.dll ] &&
    [ ! -e /usr/share/opensim/0.9.3.0/bin/OpenSimSearch.Modules.dll ]"
check "log config of the wizard" "[ -f /var/log/opensim/testgrid_robust.log ]"
grid_core() {
    runuser -u opensim -- crudini --inplace --set /etc/opensim/grids/testgrid/testgrid.conf Grid CoreDirectory "$1"
    opensim restart now testgrid >/tmp/robust-restart.out 2>&1
}

# The grid on the development build
ready=$(grep -c 'UserAgentServerConnector loaded' $(robust_log))
grid_core /usr/share/opensim/unstable
state after moving the grid to the development build
check "grid on its own core" "grep 'Starting in' $(robust_log) | tail -1 | grep -q /usr/share/opensim/unstable/bin"
check "development build ready" "[ \$(grep -c 'UserAgentServerConnector loaded' $(robust_log)) = $((ready + 1)) ]"
check "the start of a Robust counts its services" "grep -qE '^  [A-Za-z]+Connector [0-9]+/[0-9]+\$' /tmp/robust-restart.out && grep -qE '^  [A-Za-z]+Connector ([0-9]+)/\\1\$' /tmp/robust-restart.out"

# Removing a build stops the instances running from it only
apt_q remove opensim-unstable
state after removing the development build
check "instances of a removed build stopped" "[ -z '$(robust_pid)' ]"
apt_q install "$unstable"
grid_core /usr/share/opensim/0.9.3.0
state after moving the grid back
pid=$(robust_pid)
check "grid back on the release" "[ -n '$pid' ]"
apt_q remove opensim-unstable
echo "   Robust $(robust_pid) (was $pid), simulator $(sim_pid) (was $sim)"
if [ -z "$(sim_pid)" ]; then
    echo "   The simulator is gone, its log:"
    tail -25 /var/log/opensim/testgrid_sim1.log | cut -c1-200
    echo "   its screen, and what is running:"
    runuser -u opensim -- screen -ls | head -5
    ps -eo pid,etime,args | grep -E "dotnet|screen" | grep -v grep | cut -c1-150
fi
check "instances of another core untouched" "[ '$(robust_pid)' = '$pid' ] && [ '$(sim_pid)' = '$sim' ]"
apt_q install "$unstable"

# A simulator with a remote (REST) console: no screen session, everything through
# its port (x4 of its block), the ports of the next block (9010)
ts "remote console"
(cd /var/lib/opensim && TEST_GRID=testgrid TEST_SIM=Rest TEST_START=1 TEST_CONSOLE=rest TEST_OWNER="Test Owner" \
    TEST_ADMIN_USER=dbroot TEST_ADMIN_PASSWORD=adminpw runuser -u opensim -- php /test/newsim.php >/tmp/rest.out 2>&1
    echo "exit code: $?" >>/tmp/rest.out)
check "a simulator with a remote console is set up and started" "grep -q 'exit code: 0' /tmp/rest.out && grep -q 'region Rest is online' /tmp/rest.out"
check "its console is in its config, on the port x4 of its block" "grep -q '^console_port = 9014' /etc/opensim/grids/testgrid/sims/testgrid_rest.ini &&
    grep -qE '^ConsoleUser = \"[a-z]{12}\"' /etc/opensim/grids/testgrid/sims/testgrid_rest.ini && grep -qE '^ConsolePass = \"[A-Za-z0-9]{32}\"' /etc/opensim/grids/testgrid/sims/testgrid_rest.ini"
check "it has no screen session, and it runs" "! runuser -u opensim -- screen -ls | grep -q testgrid_rest && pgrep -f 'OpenSim.dll -inifile=/etc/opensim/opensim.d/testgrid_rest.ini' >/dev/null"
opensim ports >/tmp/ports-rest.out 2>&1
check "opensim ports lists its console, and its block" "grep -qE '9010 +tcp +public' /tmp/ports-rest.out && grep -qE '9014 +tcp +console' /tmp/ports-rest.out && grep -qE '9011 +udp +public' /tmp/ports-rest.out"
check "a command reaches its console through the port" "opensim command testgrid_rest 'show info' | grep -q 'Version: OpenSim'"
rest_user=$(sed -nE 's/^ConsoleUser = "([a-z]+)"/\1/p' /etc/opensim/grids/testgrid/sims/testgrid_rest.ini)
check "a wrong password is refused" "! OPENSIM_REST_PASSWORD=wrong php /usr/share/opensim-tools/vendor/magicoli/opensim-rest-php/opensim-rest-cli.php --url http://127.0.0.1:9014 --user $rest_user -- 'show info' >/dev/null 2>&1"
opensim stop now testgrid_rest >/tmp/rest-stop.out 2>&1
check "it stops through its console" "! pgrep -f 'OpenSim.dll -inifile=/etc/opensim/opensim.d/testgrid_rest.ini' >/dev/null"
opensim start testgrid_rest >/tmp/rest-start.out 2>&1
check "it starts again, without screen, and is ready by its log" "grep -aq 'testgrid_rest started' /tmp/rest-start.out && pgrep -f 'OpenSim.dll -inifile=/etc/opensim/opensim.d/testgrid_rest.ini' >/dev/null"
opensim stop now testgrid_rest >/dev/null 2>&1
rm -f /etc/opensim/opensim.d/testgrid_rest.ini

# A simulator on a machine that has no Robust of its own: it joins the grid by its
# address (here the one of this machine, taken for another one), which is kept
# under its own nick, and owns an estate of an account that has to exist already
ts "simulator of a grid elsewhere"
(cd /var/lib/opensim && TEST_REMOTE_GRID=Elsewhere TEST_REMOTE_ADDRESS=localhost:8002 TEST_SIM=Far TEST_START=1 TEST_OWNER="Test Owner" \
    TEST_DB_PASSWORD=testpass TEST_ADMIN_USER=dbroot TEST_ADMIN_PASSWORD=adminpw runuser -u opensim -- php /test/newsim.php >/tmp/far.out 2>&1
    echo "exit code: $?" >>/tmp/far.out)
check "the simulator wizard joins a grid by its address and ends well" "grep -q 'exit code: 0' /tmp/far.out && grep -q 'Wrote /etc/opensim/grids/Elsewhere/Elsewhere.conf' /tmp/far.out && grep -q \"Simulator 'Far' is running\" /tmp/far.out"
check "the grid is kept as a remote one, with its address and its ports" "grep -q '^Remote = true' /etc/opensim/grids/Elsewhere/Elsewhere.conf && grep -q '^BaseHostname = localhost' /etc/opensim/grids/Elsewhere/Elsewhere.conf &&
    grep -q '^PublicPort = 8002' /etc/opensim/grids/Elsewhere/Elsewhere.conf && grep -q '^PrivatePort = 8003' /etc/opensim/grids/Elsewhere/Elsewhere.conf"
far_registered() {
    [ "$(mysql -BN -e "SELECT COUNT(*) FROM testgrid_robust.regions WHERE regionName='Far'")" = 1 ] &&
        grep -q '^ExternalHostName = SYSTEMIP' /etc/opensim/grids/Elsewhere/sims/elsewhere_far/regions/Far.ini
}
wait_check 90 "its region registered in the grid, from its own block of ports" far_registered
# Asked to Robust over HTTP, the wizard puts it next to the others, not on one of them
far_placed() {
    [ "$(mysql -BN -e "SELECT COUNT(*) FROM testgrid_robust.regions WHERE locX DIV 256 = (SELECT locX DIV 256 FROM testgrid_robust.regions WHERE regionName='Far') AND locY DIV 256 = (SELECT locY DIV 256 FROM testgrid_robust.regions WHERE regionName='Far')")" = 1 ] &&
        [ "$(mysql -BN -e "SELECT COUNT(*) FROM testgrid_robust.regions WHERE regionName='Far' AND ABS(CAST(locX DIV 256 AS SIGNED) - 8002) <= 3 AND ABS(CAST(locY DIV 256 AS SIGNED) - 8002) <= 3")" = 1 ]
}
check "its place is free, next to the regions of the grid it asked Robust about" far_placed
opensim stop now elsewhere_far >/dev/null 2>&1
rm -f /etc/opensim/opensim.d/elsewhere_far.ini

ts "modules enabled for a region"
conf=/etc/opensim/opensim.conf
check "opensim-kit enabled the safe modules" "[ \"\$(crudini --get $conf Defaults EnabledModules)\" = 'opensimsearch gloebit' ]"
mkdir -p /etc/opensim/opensim.d /var/lib/opensim/data/testsim /var/cache/opensim/testsim
cat >/etc/opensim/opensim.d/testsim.ini <<'EOF'
[Const]
    DataDirectory = "/var/lib/opensim/data/testsim"
    LogsDirectory = "/var/log/opensim"
    CacheDirectory = "/var/cache/opensim/testsim"
[Startup]
    RegistryLocation = "${Const|DataDirectory}/registry"
EOF
chown -R opensim:opensim /etc/opensim/opensim.d /var/lib/opensim/data/testsim /var/cache/opensim/testsim
addins=/var/lib/opensim/data/testsim/registry/addins
opensim start testsim >/dev/null 2>&1; opensim stop now testsim >/dev/null 2>&1
check "modules linked for the region, from their packages" "[ \"\$(readlink $addins/Gloebit.dll)\" = /usr/share/opensim-modules/0.9.3.0/gloebit/Gloebit.dll ] &&
    [ -L $addins/OpenSimSearch.Modules.dll ]"
check "nothing written in the core" "[ -z \"\$(find /usr/share/opensim/0.9.3.0 -newer $conf -type f)\" ]"
# A region cannot run without libgdiplus: it is said, not left to crash
gdiplus=$(ldconfig -p | awk '/libgdiplus\.so/ {print $NF; exit}')
mv "$(readlink -f "$gdiplus")" /tmp/libgdiplus.off && ldconfig
opensim start testsim >/tmp/nogdiplus.out 2>&1; nogdiplus=$?
mv /tmp/libgdiplus.off "$(readlink -f "$gdiplus")" && ldconfig
check "a region without libgdiplus is refused with the way out" "[ $nogdiplus != 0 ] && grep -aq 'libgdiplus is missing' /tmp/nogdiplus.out && grep -aq 'apt install libgdiplus' /tmp/nogdiplus.out"
check "the core packages depend on libgdiplus" "dpkg -s opensim-0.9.3.0 | grep '^Depends:' | grep -q libgdiplus"
crudini --inplace --set $conf Defaults EnabledModules opensimsearch
opensim start testsim >/dev/null 2>&1; opensim stop now testsim >/dev/null 2>&1
check "a module no longer enabled is unlinked" "[ ! -e $addins/Gloebit.dll ] && [ -L $addins/OpenSimSearch.Modules.dll ]"
crudini --inplace --set $conf Defaults EnabledModules "opensimsearch gloebit"
rm -f /etc/opensim/opensim.d/testsim.ini

ts "remove the tools, grid started by the service"
registered=$(sim_registered)
systemctl restart opensim
robust_state
grid_started() { [ -n "$(robust_pid)" ] && [ -n "$(sim_pid)" ] && [ "$(sim_registered)" -gt "$registered" ]; }
wait_check 120 "the service starts the grid, then its simulator" grid_started
before=$(quits)
apt_q remove opensim-tools
robust_state
check "Robust stopped cleanly" "[ -z '$(robust_pid)' ] && [ '$(quits)' = $((before + 1)) ]"
check "the simulator stopped too" "[ -z '$(sim_pid)' ]"
check "core kept" "[ -f /usr/share/opensim/0.9.3.0/bin/OpenSim.exe ]"

ts "reinstall the tools"
apt_q install $tools
check "service enabled again" "systemctl -q is-enabled opensim"

ts "remove the tools, grid started by hand"
opensim start testgrid >/dev/null 2>&1
check "Robust running" "[ -n '$(robust_pid)' ]"
before=$(quits)
apt_q remove opensim-tools
robust_state
check "Robust stopped cleanly" "[ -z '$(robust_pid)' ] && [ '$(quits)' = $((before + 1)) ]"

ts "remove the core, tools installed"
apt_q install $tools
# A copy of the release elsewhere, running: the packaged one can go
cp -a /usr/share/opensim/0.9.3.0 /opt/custom-core
grid_core /opt/custom-core
pid=$(robust_pid)
check "grid on a core outside the packages" "[ -n '$pid' ]"
apt_q remove opensim-0.9.3.0
check "profile removed" "! profile"
check "instances of a custom core untouched" "[ '$(robust_pid)' = '$pid' ]"
opensim stop now >/dev/null 2>&1
check "modules kept without their core" "[ -f /usr/share/opensim-modules/0.9.3.0/gloebit/Gloebit.dll ]"
check "default falls back to the remaining build" "grep -q '^DefaultProfile = opensim-unstable' /etc/opensim/opensim.conf"
apt_q remove opensim-unstable
check "no default profile" "! grep -q '^DefaultProfile' /etc/opensim/opensim.conf"
check "tools work without a core" "opensim status >/dev/null"

ts purge
apt_q purge opensim-tools
check "cache removed" "[ ! -e /var/cache/opensim ]"
check "config, data and logs kept" "[ -d /etc/opensim/grids/testgrid ] && [ -d /var/lib/opensim/data ] && [ -d /var/log/opensim ]"

ts "done: $([ $FAILED = 0 ] && echo 'all checks passed' || echo 'SOME CHECKS FAILED')"
exit $FAILED
