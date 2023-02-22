<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;
use CallItopService;
use Utils;
use DoPostRequestService;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/callitopservice.class.inc.php');
require_once(APPROOT.'core/dopostrequestservice.class.inc.php');
require_once(APPROOT.'core/parameters.class.inc.php');

class CallItopServiceTest extends TestCase
{
	public function setUp(): void
	{
		parent::setUp();
	}

	public function GetCredentialsProvider(){
		return [
			'login/password (nominal)' => [
				'aParameters' => [
					'itop_login' => 'admin1',
					'itop_password' => 'admin2'
				],
				'aExpectedCredentials' => ['auth_user'=> 'admin1', 'auth_pwd'=>'admin2']
			],
			'legacy rest-token' => [
				'aParameters' => [
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'rest-token' => 'admin3',
				],
				'aExpectedCredentials' => ['rest-token'=> 'admin3']
			],
			'new token' => [
				'aParameters' => [
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'token' => 'admin4',
				],
				'aExpectedCredentials' => ['token'=> 'admin4']
			],
			'new token over legacy one' => [
				'aParameters' => [
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'rest-token' => 'admin3',
					'token' => 'admin4',
				],
				'aExpectedCredentials' => ['token'=> 'admin4']
			],
		];
	}

	/**
	 * @dataProvider GetCredentialsProvider
	 */
	public function testCallItopViaHttp($aParameters, $aExpectedCredentials){
		$oParametersMock = $this->createMock(\Parameters::class);
		$oParametersMock->expects($this->atLeast(1))
			->method('Get')
			->will($this->returnCallback(
				function($sKey, $aDefaultValue) use ($aParameters) {
					if (array_key_exists($sKey, $aParameters)){
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

		$uri = 'http://itop.org';
		$aAdditionalData = ['gabu' => 'zomeu'];
		$oMockedDoPostRequestService->expects($this->once())
			->method('DoPostRequest')
			->with($uri, array_merge($aExpectedCredentials, $aAdditionalData ))
		;

		$oCallItopService = new CallItopService();
		$oCallItopService->CallItopViaHttp($uri, $aAdditionalData);
	}

}
