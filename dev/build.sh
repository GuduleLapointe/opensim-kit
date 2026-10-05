#!/usr/bin/env bash
# Builds what this project distributes into dist/, from the committed tree (commit first: the version carries the hash of
# HEAD): the Debian packages (apt-package runs packaging/build and nfpm) and the zip of the tools. Calls what
# packaging/ defines.
#
#   dev/build.sh                      every format
#   dev/build.sh zip                  the zip
#   dev/build.sh deb [package...]     the Debian packages of packaging/*.yaml, all or the ones named
#                                     (opensim-tools, opensim-kit, opensim-web, opensim-core, opensim-unstable...)

set -e
cd "$(dirname "$0")/.."
source dev/lib.sh

formats=()
packages=()
for word in "$@"; do
    case $word in
        deb | zip) formats+=("$word") ;;
        *) packages+=("$word") ;;
    esac
done
if [[ ${#formats[@]} -eq 0 ]]; then
    if [[ ${#packages[@]} -gt 0 ]]; then formats=(deb); else formats=(deb zip); fi
fi

# A build is made from the last commit and from composer.lock: both are checked, not left to be remembered
if [[ -z "${DIRTY:-}" && -n "$(git status --porcelain --untracked-files=no)" ]]; then
    die "commit your changes first, a build is made from the last commit (DIRTY=1 builds it anyway):
$(git status --short --untracked-files=no)"
fi
if ! composer validate --no-check-publish --no-check-all --no-interaction >/dev/null 2>&1; then
    die "composer.lock is not up to date with composer.json (composer update the packages that changed, commit):
$(composer validate --no-check-publish --no-check-all --no-interaction 2>&1 || true)"
fi
untracked=$(git ls-files --others --exclude-standard)
[[ -z "$untracked" ]] || log "note: files git does not track are not in the build: $(tr '\n' ' ' <<<"$untracked")"

mkdir -p dist

for format in "${formats[@]}"; do
    case $format in
        deb)
            # The packages are made by apt-package (the tool of the apt repository, which publishes them too)
            apt_package=$(command -v apt-package || true)
            for candidate in /opt/apt-repo/bin/apt-package /opt/magic/apt-repo/bin/apt-package; do
                [[ -n "$apt_package" || ! -x "$candidate" ]] || apt_package=$candidate
            done
            if [[ -z "$apt_package" ]]; then
                die "apt-package is not installed (the apt-repo project, see DEVELOPERS.md)"
            fi
            require nfpm
            # dist/ holds the build just made of the packages that carry the version of the project, not the former ones
            for definition in packaging/*.yaml; do
                name=$(basename "$definition" .yaml)
                [[ ${#packages[@]} -eq 0 || " ${packages[*]} " == *" $name "* ]] || continue
                grep -qE '\$\{(DEB_)?VERSION\}' "$definition" || continue
                rm -f "dist/${name}_"*.deb
            done
            "$apt_package" "${packages[@]}"
            ;;
        zip)
            rm -f dist/opensim-kit-*.zip
            packaging/zip
            ;;
    esac
done

success "Built from the commit $(git rev-parse --short HEAD), ${DIRTY:+with uncommitted changes, }\"$(git log -1 --format=%s)\"; in a version, g is for git and the hash follows it."
