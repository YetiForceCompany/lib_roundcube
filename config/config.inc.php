<?php
/*
  Disable SSL for IMAP/SMTP
  If your IMAP/SMTP servers are on the same host or are connected via a secure network, not using SSL connections improves performance. So don't use "ssl://" or "tls://" urls for 'default_host' and 'smtp_server' config options.
 */
$currentPath = getcwd();
chdir(dirname(__FILE__) . '/../../../../');
include_once('include/ConfigUtils.php');
include_once('config/modules/OSSMail.php');
chdir($currentPath);
