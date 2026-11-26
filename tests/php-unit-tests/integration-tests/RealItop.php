<?php

namespace UnitTestFiles\Test\Integration;

/**
 * Connection to the real iTop used by the integration tests, read from environment variables:
 * - ITOP_TEST_URL: root URL of iTop, e.g. http://localhost (mandatory, the tests are skipped without it);
 * - ITOP_TEST_LOGIN, and ITOP_TEST_PASSWORD or ITOP_TEST_TOKEN: an account with the "Administrator" and
 *   "REST Services User" profiles (it creates and deletes data sources and organizations). The login is needed even
 *   with a token: the tests look the account up by its login, as Orchestrator does to resolve $synchro_user$;
 * - ITOP_TEST_SSL_VERIFY: '0' to accept a self-signed certificate.
 *
 * WARNING: the tests write into this iTop. They only create data sources and organizations whose name holds a
 * unique run id, and delete them afterwards, but never point them to a production iTop.
 */
final class RealItop
{
	/**
	 * @return array{url: string, login: string, password: string, token: string, curl_options: array<string, string>}|string
	 *     The connection, or the reason why it is not configured
	 */
	public static function GetConnection()
	{
		$sUrl = (string)getenv('ITOP_TEST_URL');
		$sLogin = (string)getenv('ITOP_TEST_LOGIN');
		$sPassword = (string)getenv('ITOP_TEST_PASSWORD');
		$sToken = (string)getenv('ITOP_TEST_TOKEN');
		if ($sUrl === '') {
			return 'Set ITOP_TEST_URL (and the credentials) to run the integration tests against a real iTop.';
		}
		if ($sLogin === '' || ($sPassword === '' && $sToken === '')) {
			return "ITOP_TEST_URL is set to $sUrl, but the credentials are incomplete: set ITOP_TEST_LOGIN, and ITOP_TEST_PASSWORD or ITOP_TEST_TOKEN.";
		}

		$aCurlOptions = [];
		if (getenv('ITOP_TEST_SSL_VERIFY') === '0') {
			$aCurlOptions = ['CURLOPT_SSL_VERIFYPEER' => '0', 'CURLOPT_SSL_VERIFYHOST' => '0'];
		}

		return ['url' => rtrim($sUrl, '/'), 'login' => $sLogin, 'password' => $sPassword, 'token' => $sToken, 'curl_options' => $aCurlOptions];
	}
}
