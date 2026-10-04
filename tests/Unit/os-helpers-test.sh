#!/usr/bin/env bash

# Tests for functions of libexec/os-helpers that work on files.
#
# Run with: tests/lib/bashunit tests/Unit (or vendor/bin/pest)

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Only the function under test: the library does more when it is sourced
eval "$(sed -n '/^cleanupIni()/,/^}/p' "$ROOT/libexec/os-helpers")"

function set_up() {
	WORK=$(mktemp -d "${TMPDIR:-/tmp}/os-helpers-test.XXXXXX")
}

function tear_down() {
	rm -rf "$WORK"
}

function test_cleanupini_strips_the_indentation() {
	printf '[Const]\n    BaseURL = "http://localhost"\n\t;; comment\n' >"$WORK/in.ini"

	assert_equals '[Const]
BaseURL = "http://localhost"
;; comment' "$(cleanupIni "$WORK/in.ini")"
}

function test_cleanupini_strips_windows_line_endings() {
	printf '[Const]\r\n    BaseURL = "http://localhost"\r\n' >"$WORK/in.ini"

	cleanupIni "$WORK/in.ini" >"$WORK/out.ini"

	assert_equals '0' "$(grep -c "$(printf '\r')" "$WORK/out.ini")"
	assert_equals 'BaseURL = "http://localhost"' "$(sed -n 2p "$WORK/out.ini")"
}

function test_cleanupini_gives_nothing_for_a_missing_file() {
	assert_equals '' "$(cleanupIni "$WORK/missing.ini")"
}

function test_oscountdown_counts_down_to_the_shutdown_not_to_the_next_warning() {
    eval "$(sed -n '/^osCountdown()/,/^}/p' "$ROOT/libexec/os-helpers")"
    osStep() { echo "$1"; }
    osStepEnd() { :; }
    sleep() { :; }

    assert_equals '  Stopping x in 80s' "$(osCountdown 40 'Stopping x' 40)"
}

function test_oscountdown_stops_when_the_check_succeeds() {
    eval "$(sed -n '/^osCountdown()/,/^}/p' "$ROOT/libexec/os-helpers")"
    osStep() { :; }
    osStepEnd() { :; }
    sleep() { :; }
    nobody() { return 0; }

    osCountdown 40 'x' 0 nobody
    assert_equals '1' "$?"
}

function test_oscountdown_goes_to_the_end_when_the_check_fails() {
    eval "$(sed -n '/^osCountdown()/,/^}/p' "$ROOT/libexec/os-helpers")"
    osStep() { :; }
    osStepEnd() { :; }
    sleep() { :; }
    somebody() { return 1; }

    osCountdown 40 'x' 0 somebody
    assert_equals '0' "$?"
}

function test_oscountrealusers_ignores_npcs_and_children() {
	eval "$(sed -n '/^osCountRealUsers()/,/^}/p' "$ROOT/libexec/os-helpers")"
	OSIM_REST_INI=x
	osRest() {
		cat <<'OUT'
2026-10-03 10:00:00,000 INFO  [SCENE]: Removing root agent 11111111-2222-3333-4444-555555555999 from Sim1

Root agents in region Sim1: 3 (root 3, child 1)
Firstname        Lastname         Agent ID                              Type        Position
Ann              Lee              11111111-2222-3333-4444-555555555555  Root        <1, 2, 3>
Bob              NPC              11111111-2222-3333-4444-555555555556  NPC Root    <1, 2, 3>
Cy               Child            11111111-2222-3333-4444-555555555557  Child       <1, 2, 3>

Root agents in region Sim2: 1 (root 1, child 0)
Dee              Roe              11111111-2222-3333-4444-555555555558  Root        <1, 2, 3>
OUT
	}

	assert_equals '2' "$(osCountRealUsers)"
}

function test_oscountrealusers_is_zero_for_empty_regions() {
	eval "$(sed -n '/^osCountRealUsers()/,/^}/p' "$ROOT/libexec/os-helpers")"
	OSIM_REST_INI=x
	osRest() { printf '\nRoot agents in region Sim1: 0 (root 0, child 0)\n'; }

	assert_equals '0' "$(osCountRealUsers)"
}

function test_oscountrealusers_does_not_guess_without_a_list() {
    eval "$(sed -n '/^osCountRealUsers()/,/^}/p' "$ROOT/libexec/os-helpers")"
    OSIM_REST_INI=x
    osRest() { printf 'something else\n'; }

    assert_equals '' "$(osCountRealUsers)"
}

function test_oscountrealusers_from_a_screen_reads_the_rows_cut_at_80_columns() {
	eval "$(sed -n '/^osCountRealUsers()/,/^}/p' "$ROOT/libexec/os-helpers")"
	unset OSIM_REST_INI
	simName=sim
	osScreenExists() { return 0; }
	osScreenOutput() { printf '\nRoot agents in region Sim1: 0 (root 0, child 2)\n'; }

	assert_equals '0' "$(osCountRealUsers)"

	# Only an NPC is there (the root count includes it), the type is in the 80 columns
	osScreenOutput() {
		printf '\nRoot agents in region Sim1: 1 (root 1, child 0)\nBob              NPC              11111111-2222-3333-4444-555555555556  NPC Roo\nt    <1, 2, 3>\n'
	}
	assert_equals '0' "$(osCountRealUsers)"

	# A real user whose type was cut off counts
	osScreenOutput() {
		printf '\nRoot agents in region Sim1: 2 (root 2, child 0)\nAnn              Lee              11111111-2222-3333-4444-555555555555\nBob              NPC              11111111-2222-3333-4444-555555555556  NPC Roo\n'
	}
	assert_equals '1' "$(osCountRealUsers)"

	# Root agents without the rows to tell who they are: no answer
	osScreenOutput() { printf '\nRoot agents in region Sim1: 1 (root 1, child 0)\nAnn Lee 1111-truncated\n'; }
	osCountRealUsers >/dev/null
	assert_equals '1' "$?"
}
