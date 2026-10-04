#!/usr/bin/env bash
# The site of a grid on real web servers, run by SCENARIO=web-scenario.sh tests/Packaging/run: nginx, Apache
# and Caddy, each with the example the setup writes for it (opensim web GRID snippet SERVER), PHP-FPM or
# mod_php behind. One server at a time, they all want the port 80 of the machine by default.

source /test/lib.sh
magiiic_repository

ts "packages, grid"
apt_q install "$(deb opensim-0.9.3.0)" "$(deb opensim-tools)" "$(deb opensim-helpers)" "$(deb opensim-web)"
systemctl start mariadb
mysql -e "CREATE DATABASE testgrid_robust; CREATE USER opensim@localhost IDENTIFIED BY 'testpass';
    GRANT ALL ON testgrid_robust.* TO opensim@localhost;"
(cd /var/lib/opensim && runuser -u opensim -- php /test/newgrid.php | tail -1)
systemctl stop mariadb # the site does not need it, the machine has little memory
check "the setup wrote the examples for the three servers" "[ -s /etc/opensim/grids/testgrid/web/testgrid.caddyfile ] &&
    [ -s /etc/opensim/grids/testgrid/web/testgrid-nginx.conf ] && [ -s /etc/opensim/grids/testgrid/web/testgrid-apache.conf ]"
apt_q install php-fpm
systemctl start php8.2-fpm
check "PHP-FPM listens where the examples say" "[ -S /run/php/php-fpm.sock ]"

# What a visitor and a viewer ask: the pages, a helper under its prefix, an unknown URL
site() { # server, port
    local url=http://127.0.0.1:$2
    check "$1: the home and the splash page show the grid" "curl -s $url/ | grep -q Testgrid && curl -s $url/welcome | grep -q Testgrid"
    check "$1: a helper answers under its prefix" "[ \"\$(curl -s $url/helpers/motd.php)\" = 'Welcome to Testgrid, <USERNAME>!' ]"
    check "$1: an unknown URL is a 404 of the router" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' $url/nope)\" = 404 ] && curl -s $url/nope | grep -q 'Not found'"
}

ts nginx
apt_q install nginx
rm -f /etc/nginx/sites-enabled/default
printf 'server {\n\tlisten 8091;\n\tinclude /etc/opensim/grids/testgrid/web/testgrid-nginx.conf;\n}\n' >/etc/nginx/conf.d/grid.conf
check "nginx accepts the example" "nginx -t >/dev/null 2>&1"
systemctl restart nginx
site nginx 8091
systemctl stop nginx

ts apache
apt_q install apache2 libapache2-mod-php
echo "Listen 8092" >/etc/apache2/ports.conf
printf '<VirtualHost *:8092>\n\tInclude /etc/opensim/grids/testgrid/web/testgrid-apache.conf\n</VirtualHost>\n' >/etc/apache2/sites-enabled/000-default.conf
check "Apache accepts the example" "apachectl configtest >/dev/null 2>&1"
systemctl restart apache2
site apache 8092
systemctl stop apache2

ts caddy
apt_q install caddy
printf ':8093 {\n\timport /etc/opensim/grids/testgrid/web/testgrid.caddyfile\n}\n' >/etc/caddy/Caddyfile
check "Caddy accepts the example" "caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1"
systemctl restart caddy
sleep 2
site caddy 8093
systemctl stop caddy

ts "done: $([ $FAILED = 0 ] && echo 'all checks passed' || echo 'SOME CHECKS FAILED')"
exit $FAILED
