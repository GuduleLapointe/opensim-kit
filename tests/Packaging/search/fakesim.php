<?php
/**
 * A simulator that only answers the data snapshot, for php -S: ?method=collector gives the file in /tmp/fakesim/collector.xml,
 * which a test replaces to change what the simulator says. Anything else is a 404.
 */
$file = '/tmp/fakesim/collector.xml';
if (($_GET['method'] ?? '') === 'collector' && is_file($file)) {
    header('Content-Type: text/xml');
    readfile($file);
    return true;
}
http_response_code(404);
echo "404\n";
