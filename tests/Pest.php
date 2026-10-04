<?php
/**
 * Pest setup. Run the suite with ./vendor/bin/pest.
 *
 * - tests/Environment: what the project needs from its environment (PHP version, compatibility)
 */

// The temporary folder by its real path: on macOS /var is a link to /private/var, and the paths the code finds (realpath)
// would not be the ones the tests built
putenv('TMPDIR=' . realpath(sys_get_temp_dir()));
