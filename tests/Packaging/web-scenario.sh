#!/usr/bin/env bash
# The site of a grid on real web servers, run by SCENARIO=web-scenario.sh tests/Packaging/run: nginx, Apache
# and Caddy, each with the file the setup writes for it (opensim web GRID snippet SERVER), PHP-FPM or
# mod_php behind. One server at a time, they all want the port 80 of the machine by default.

source /test/lib.sh
magiiic_repository

ts "packages, grid"
apt_q install "$(deb opensim-0.9.3.0)" "$(deb opensim-rest-php)" "$(deb opensim-engine)" "$(deb opensim-helpers)" "$(deb opensim-tools)" "$(deb opensim-web)"
systemctl start mariadb
mysql -e "CREATE DATABASE testgrid_robust; CREATE USER opensim@localhost IDENTIFIED BY 'testpass';
    GRANT ALL ON testgrid_robust.* TO opensim@localhost;"
(cd /var/lib/opensim && runuser -u opensim -- php /test/newgrid.php | tail -1)
systemctl stop mariadb # the site does not need it, the machine has little memory
check "the setup wrote the files for the three servers" "[ -s /etc/opensim/grids/testgrid/web/testgrid.caddyfile ] &&
    [ -s /etc/opensim/grids/testgrid/web/testgrid-nginx.conf ] && [ -s /etc/opensim/grids/testgrid/web/testgrid-apache.conf ]"
apt_q install php-fpm
systemctl start php8.2-fpm
check "PHP-FPM listens where the files say" "[ -S /run/php/php-fpm.sock ]"

# What a visitor and a viewer ask, at the site of the grid: the pages, a helper under its prefix, an unknown URL
site() { # server, url of the site
    local url=$2
    wait_check 30 "$1 answers" "curl -sk $url/ >/dev/null"
    check "$1: the home and the splash page show the grid" "curl -sk $url/ | grep -q Testgrid && curl -sk $url/welcome | grep -q Testgrid"
    check "$1: a helper answers under its prefix" "[ \"\$(curl -sk $url/helpers/motd.php)\" = 'Welcome to Testgrid, <USERNAME>!' ]"
    check "$1: an unknown URL is a 404 of the router" "[ \"\$(curl -sk -o /dev/null -w '%{http_code}' $url/nope)\" = 404 ] && curl -sk $url/nope | grep -q 'Not found'"
}

# The files of the setup are complete: nginx and Apache include theirs, Caddy takes its as the whole config
ts nginx
apt_q install nginx
rm -f /etc/nginx/sites-enabled/default
cp /etc/opensim/grids/testgrid/web/testgrid-nginx.conf /etc/nginx/conf.d/testgrid.conf
check "nginx accepts the file as it is" "nginx -t >/dev/null 2>&1"
systemctl restart nginx
site nginx http://localhost
systemctl stop nginx

ts apache
apt_q install apache2 libapache2-mod-php
cp /etc/opensim/grids/testgrid/web/testgrid-apache.conf /etc/apache2/sites-available/testgrid.conf
a2dissite 000-default >/dev/null
a2ensite testgrid >/dev/null
check "Apache accepts the file as it is" "apachectl configtest >/dev/null 2>&1"
systemctl restart apache2
site apache http://localhost
systemctl stop apache2

ts caddy
apt_q install caddy
cp /etc/opensim/grids/testgrid/web/testgrid.caddyfile /etc/caddy/Caddyfile
check "Caddy accepts the file as the whole config" "caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1"
systemctl restart caddy
site caddy https://localhost
systemctl stop caddy

ts "done: $([ $FAILED = 0 ] && echo 'all checks passed' || echo 'SOME CHECKS FAILED')"
exit $FAILED
