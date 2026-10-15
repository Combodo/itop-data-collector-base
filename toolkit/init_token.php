<?php

define('APPROOT', dirname(dirname(__FILE__)).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');

try {
	if (! defined('_SERVER')){
		$_SERVER = ['REQUEST_METHOD' => 'POST'];
	}

	Utils::InitConsoleLogLevel();
	if (!Utils::UseOauth2()) {
		echo "OAuth2 not configured\n";
		exit(1);
	}

	$aAuthorizeUrlParameters = [
		'auth_user' => Utils::GetConfigurationValue('itop_login', ''),
		'auth_pwd' => Utils::GetConfigurationValue('itop_password', ''),
		'response_type' => 'code',
		'client_id' => Utils::GetConfigurationValue('itop_oauth2_clientid', ''),
		'redirect_uri' => Utils::GetConfigurationValue('itop_redirect_uri', ''),
	];

	$oCallItopService = new CallItopService();
	var_dump($oCallItopService->GetOauth2AccessToken(true, "Itop", $aAuthorizeUrlParameters));
} catch (\Exception $e){
	echo "Failed to negociate OAuth2 token\n";
	echo $e->getMessage() . "\n";
}