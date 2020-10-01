<?php

define('APPROOT', dirname(__FILE__).'/../');
require_once(APPROOT . 'core/parameters.class.inc.php');
require_once(APPROOT . 'core/utils.class.inc.php');
require_once(APPROOT . 'core/restclient.class.inc.php');

print '    curl_init exists: ' . function_exists('curl_init').PHP_EOL;

try{
    $oRestClient = new RestClient();
    var_dump($oRestClient->ListOperations());
    print 'Calling iTop Rest API worked!'.PHP_EOL;
    exit(0);
}
catch (Exception $e)
{
    print $e->getMessage().PHP_EOL;
    exit(1);
}
