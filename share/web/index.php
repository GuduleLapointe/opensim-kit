<?php
/**
 * The default site of a grid: what the web server has no file for comes here, and the helpers answer it
 * (their pages, their services at the URLs of the grid). Add your own files next to this one.
 */

require (getenv('OPENSIM_HELPERS_ROOT') ?: '/usr/share/opensim-helpers') . '/index.php';
