#!/usr/bin/env bash
# What the scenarios of tests/Packaging share, in the test container: the checks, and
# the Magiiic repository for the dependencies (bash-tools).

FAILED=0
ts() { echo "[$(date +%T)] == $*"; }
check() {
    if eval "$2"; then
        echo "   ok: $1"
    else
        echo "   FAILED: $1"
        FAILED=1
    fi
}
# Like check, for what takes a while to be true on a slow machine: the condition
# is tried again every 3 seconds, for up to $1 seconds
wait_check() {
    local seconds=$1 end=$((SECONDS + $1))
    until eval "$3"; do
        if [ "$SECONDS" -ge "$end" ]; then
            echo "   FAILED: $2"
            FAILED=1
            return
        fi
        sleep 3
    done
    echo "   ok: $2"
}
apt_q() {
    DEBIAN_FRONTEND=noninteractive apt-get "$@" -y -qq 2>&1 |
        grep -E "opensim|needs|E:" | grep -vE "^(Selecting|Preparing|Unpacking)"
}
deb() { ls /dist/"$1"_*.deb | grep -E "_($(dpkg --print-architecture)|all)\.deb$" | tail -1; }

# The Magiiic repository, for the dependencies (bash-tools), as in the README
magiiic_repository() {
    rm -f /usr/sbin/policy-rc.d # container images forbid service actions
    curl -fsSL https://apt.magiiic.com/magiiic-packaging.asc | gpg --dearmor -o /usr/share/keyrings/magiiic-packaging.gpg
    echo "deb [signed-by=/usr/share/keyrings/magiiic-packaging.gpg] https://apt.magiiic.com stable main" >/etc/apt/sources.list.d/magiiic.list
    apt-get update -qq
}
