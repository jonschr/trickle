<?php
// Run with disable_functions=disk_free_space,set_time_limit to model managed hosting.
define('ABSPATH','/fixture/');require __DIR__.'/../includes/support.php';
CSM_Support::long_request();
echo "PASS Request setup tolerates a host-disabled set_time_limit function\n";
