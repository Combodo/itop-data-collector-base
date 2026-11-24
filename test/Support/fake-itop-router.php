<?php

/**
 * Router of `php -S` serving FakeItop over HTTP (see FakeItopServer). The state of the fake iTop is kept in the file
 * given by the FAKE_ITOP_STATE environment variable, between requests and for the test to read.
 */

define('APPROOT', dirname(__DIR__, 2).'/');
require_once APPROOT.'core/ioexception.class.inc.php';
require_once APPROOT.'core/dopostrequestservice.class.inc.php';
require_once __DIR__.'/FakeHttpServer.php';
require_once __DIR__.'/FakeItop.php';

$sStateFile = getenv('FAKE_ITOP_STATE');
$oItop = unserialize(file_get_contents($sStateFile));

$sPathPrefix = (string)parse_url(\UnitTestFiles\Test\Support\FakeItop::URL, PHP_URL_PATH);
$sRequestUri = $_SERVER['REQUEST_URI'];
if (strpos($sRequestUri, $sPathPrefix.'/') !== 0) {
	http_response_code(404);
	echo 'Not Found';

	return true;
}
$sUrl = \UnitTestFiles\Test\Support\FakeItop::URL.substr($sRequestUri, strlen($sPathPrefix));

$sBody = $oItop->Handle($sUrl, $_POST);
file_put_contents($sStateFile, serialize($oItop), LOCK_EX);

header('Content-Type: '.((strpos($sUrl, '/webservices/rest.php') !== false) ? 'application/json' : 'text/html').'; charset=UTF-8');
echo $sBody;

return true;
