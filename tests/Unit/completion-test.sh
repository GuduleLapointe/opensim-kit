#!/usr/bin/env bash

# Tests for the bash completion of `opensim`.
#
# Run with: tests/lib/bashunit tests/Unit (or vendor/bin/pest)

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

function set_up() {
	WORK=$(mktemp -d "${TMPDIR:-/tmp}/completion-test.XXXXXX")
	mkdir -p "$WORK/grids/Alpha/sims" "$WORK/robust.d" "$WORK/opensim.d"
	touch "$WORK/grids/Alpha/Robust.HG.ini" "$WORK/grids/Alpha/sims/alpha_sim1.ini" "$WORK/grids/Alpha/sims/alpha_sim2.ini"
	ln -s "$WORK/grids/Alpha/Robust.HG.ini" "$WORK/robust.d/Alpha.ini"
	ln -s "$WORK/grids/Alpha/sims/alpha_sim1.ini" "$WORK/opensim.d/alpha_sim1.ini"
	export OPENSIM_ETC_DIRS="$WORK"
	# shellcheck disable=SC1091
	. "$ROOT/share/bash_completion.d/opensim"
}

function tear_down() {
	rm -rf "$WORK"
	unset OPENSIM_ETC_DIRS
}

# What is proposed for a command line (the words, the last one being typed)
complete_words() {
	COMP_WORDS=("$@")
	COMP_CWORD=$((${#COMP_WORDS[@]} - 1))
	_opensim
	echo "${COMPREPLY[*]}"
}

function test_the_commands_are_proposed() {
	assert_contains "enable" "$(complete_words opensim e)"
	assert_contains "setup" "$(complete_words opensim s)"
	assert_equals "disable" "$(complete_words opensim di)"
}

function test_start_proposes_the_enabled_instances() {
	assert_equals "alpha alpha_sim1" "$(complete_words opensim start a)"
}

function test_enable_proposes_every_grid_and_simulator_enabled_or_not() {
	assert_equals "alpha alpha_sim1 alpha_sim2" "$(complete_words opensim enable a)"
}

function test_stop_proposes_now_first() {
	assert_equals "now" "$(complete_words opensim stop n)"
	assert_equals "alpha_sim1" "$(complete_words opensim stop now alpha_s)"
}

function test_next_proposes_what_it_gives() {
	assert_equals "port" "$(complete_words opensim next p)"
}

function test_the_grid_comes_first_in_the_commands_that_have_actions() {
	assert_contains "alpha" "$(complete_words opensim web a)"
	assert_equals "show snippet check" "$(complete_words opensim web alpha '')"
	assert_equals "snippet" "$(complete_words opensim web alpha sn)"
	assert_equals "caddy" "$(complete_words opensim web alpha snippet c)"
	assert_contains "alpha" "$(complete_words opensim import a)"
	assert_contains "--users" "$(complete_words opensim import alpha file.csv --u)"
	assert_equals "location" "$(complete_words opensim next alpha l)"
}

function test_the_archives_are_completed_by_kind() {
	assert_equals "iar" "$(complete_words opensim save i)"
	assert_contains "alpha" "$(complete_words opensim load oar a)"
	assert_equals "alpha" "$(complete_words opensim save iar al)"
	assert_equals "--noassets" "$(complete_words opensim save iar --noa)"
	assert_equals "--merge" "$(complete_words opensim load iar --m)"
	assert_equals "--region" "$(complete_words opensim save oar alpha --re)"
	assert_equals "--logs" "$(complete_words opensim backup alpha --l)"
}
