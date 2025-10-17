<?php

namespace UnitTestFiles\Test;

use DoPostRequestService;
use PHPUnit\Framework\TestCase;
use RestClient;
use Utils;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/restclient.class.inc.php');
require_once(APPROOT.'core/dopostrequestservice.class.inc.php');
require_once(APPROOT.'core/parameters.class.inc.php');

class RestTest extends TestCase
{
	public function setUp(): void
	{
		parent::setUp();
	}

	public function tearDown(): void
	{
		parent::tearDown();
		Utils::MockDoPostRequestService(null);

		$reflection = new \ReflectionProperty(Utils::class, 'oConfig');
		$reflection->setAccessible(true);
		$reflection->setValue(null, null);
	}

	public function GetCredentialsProvider()
	{
		return [
			'login/password (nominal)' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
				],
				'aExpectedCredentials' => ['auth_user' => 'admin1', 'auth_pwd' => 'admin2'],
				'url' => 'URI/webservices/rest.php?login_mode=form&version=1.0',
			],
			'new token' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'itop_token' => 'admin4',
				],
				'aExpectedCredentials' => ['auth_token' => 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=token&version=1.0',
			],
			'new token over legacy one' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'itop_rest_token' => 'admin3',
					'itop_token' => 'admin4',
				],
				'aExpectedCredentials' => ['auth_token' => 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=token&version=1.0',
			],
			'configured login_mode' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'itop_rest_token' => 'admin3',
					'itop_token' => 'admin4',
					'itop_login_mode' => 'newloginform',
				],
				'aExpectedCredentials' => ['auth_token' => 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=newloginform&version=1.0',
			],
		];
	}

	/**
	 * @dataProvider GetCredentialsProvider
	 */
	public function testCallItopViaHttp($aParameters, $aExpectedCredentials, $sExpectedUrl)
	{
		$oParametersMock = $this->createMock(\Parameters::class);
		$oParametersMock->expects($this->atLeast(1))
			->method('Get')
			->will($this->returnCallback(
				function ($sKey, $aDefaultValue) use ($aParameters) {
					if (array_key_exists($sKey, $aParameters)) {
						return $aParameters[$sKey];
					}
					return $aDefaultValue;
				}
			));

		$reflection = new \ReflectionProperty(Utils::class, 'oConfig');
		$reflection->setAccessible(true);
		$reflection->setValue(null, $oParametersMock);

		$oMockedDoPostRequestService = $this->createMock(DoPostRequestService::class);
		Utils::MockDoPostRequestService($oMockedDoPostRequestService);

		$aListParams = [
			'operation'     => 'list_operations', // operation code
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
		];
		$aAdditionalData = ['json_data' => json_encode($aListParams)];
		$oMockedDoPostRequestService->expects($this->once())
			->method('DoPostRequest')
			->with($sExpectedUrl, array_merge($aExpectedCredentials, $aAdditionalData))
			->willReturn(json_encode(['retcode' => 0]));
		;

		$oRestClient = new RestClient();
		$this->assertEquals(['retcode' => 0], $oRestClient->ListOperations());
	}

}
