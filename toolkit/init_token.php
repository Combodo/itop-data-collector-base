<?php

define('APPROOT', dirname(dirname(__FILE__)).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');

try {
	$bHelp = (Utils::ReadBooleanParameter('help', false) == true);

	$sCliBaseName = basename($argv[0]);
	if ($bHelp) {
		$sHelp = <<<HELP

Usage: php $sCliBaseName [--help] [--itop_login=LOGIN] [--itop_password=PWD]

Command line to initiate/test your OAuth2 negociation with iTop. 
Login/Password are mandatory only at this stage if you use/switch with OAuth2 authentication. 
Credentials can be provided either in CLI parameters or in collector configuration.


HELP;

		echo $sHelp;
		exit(0);
	}

	if (! defined('_SERVER')) {
		$_SERVER = ['REQUEST_METHOD' => 'POST'];
	}

	Utils::InitConsoleLogLevel();
	$oAuth2Service = Utils::GetItopOauth2Service();
	if (is_null($oAuth2Service)) {
		echo "OAuth2 not enabled/configured\n";
		exit(1);
	}

	$sLogin = Utils::ReadParameter('itop_login', null) ?? Utils::GetConfigurationValue('itop_login', '');
	$sPassword = Utils::ReadParameter('itop_password', null) ?? Utils::GetConfigurationValue('itop_password', '');
	$aAuthorizeUrlParameters = [
		'auth_user' => $sLogin,
		'auth_pwd' => $sPassword,
		'response_type' => 'code',
	];

	var_dump($oAuth2Service->GetOauth2AccessToken($aAuthorizeUrlParameters));
} catch (\Exception $e) {
	echo "Failed to negociate OAuth2 token\n";
	echo $e->getMessage()."\n";
}
