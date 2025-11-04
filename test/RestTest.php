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

	public function GetCredentialsProvider(){
		return [
			'login/password (nominal)' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2'
				],
				'aExpectedCredentials' => ['auth_user'=> 'admin1', 'auth_pwd'=>'admin2'],
				'url' => 'URI/webservices/rest.php?login_mode=form&version=1.0'
			],
			'new token' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'itop_token' => 'admin4',
				],
				'aExpectedCredentials' => ['auth_token'=> 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=token&version=1.0'
			],
			'new token over legacy one' => [
				'aParameters' => [
					'itop_url' => 'URI',
					'itop_login' => 'admin1',
					'itop_password' => 'admin2',
					'itop_rest_token' => 'admin3',
					'itop_token' => 'admin4',
				],
				'aExpectedCredentials' => ['auth_token'=> 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=token&version=1.0'
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
				'aExpectedCredentials' => ['auth_token'=> 'admin4'],
				'url' => 'URI/webservices/rest.php?login_mode=newloginform&version=1.0'
			],
		];
	}

	/**
	 * @dataProvider GetCredentialsProvider
	 */
	public function testCallItopViaHttp($aParameters, $aExpectedCredentials, $sExpectedUrl){
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

		$aListParams = array(
			'operation'     => 'list_operations', // operation code
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
		);
		$aAdditionalData = ['json_data' => json_encode($aListParams)];
		$oMockedDoPostRequestService->expects($this->once())
			->method('DoPostRequest')
			->with($sExpectedUrl, array_merge($aExpectedCredentials, $aAdditionalData ))
			->willReturn(json_encode(['retcode' => 0]));
		;

		$oRestClient = new RestClient();
		$this->assertEquals(['retcode' => 0], $oRestClient->ListOperations());
	}

	public function testGetFullSynchroDataSource(){
		require_once(APPROOT.'core/collector.class.inc.php');
		$oMockClient = $this->CreateMock('RestClient');

		$aFields = [
			'name' => 'SynchroAttribute',
			'name2' => 'SynchroAttribute',
			'cis_list' => 'SynchroAttLinkSet',
		];

		$aAttributeList = [];
		foreach ($aFields as $sField => $sClass){
			$aAttributeList []= [
				'attcode' => $sField,
				'finalclass' => $sClass,
			];
		}
		$aSource = [
			'attribute_list' => $aAttributeList,
			'friendlyname' => [], //should be removed from output response
			'user_id_friendlyname' => [], //should be removed from output response
			'user_id_finalclass_recall' => [], //should be removed from output response
			'notify_contact_id_friendlyname' => [], //should be removed from output response
			'notify_contact_id_finalclass_recall' => [], //should be removed from output response
			'notify_contact_id_obsolescence_flag' => [], //should be removed from output response
			'notify_contact_id_archive_flag' => [], //should be removed from output response
		];

		$sExpectedOql1 = <<<OQL
SELECT SynchroAttribute WHERE attcode IN ('name','name2') AND sync_source_id = 123
OQL;

		$sExpectedOql2 = <<<OQL
SELECT SynchroAttLinkSet WHERE attcode IN ('cis_list') AND sync_source_id = 123
OQL;

		$aReturnObjects1 = [
			[
				'fields' => [
					'attcode' => 'name',
					'update' => '1',
					'reconcile' => '1',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					'finalclass' => 'SynchroAttribute',
					'friendlyname' => 'name', //should be removed
					"sync_source_id" =>  'sync_source_id', //should be removed
					"sync_source_name" =>  'sync_source_name', //should be removed
					"sync_source_id_friendlyname" =>  'sync_source_id_friendlyname',  //should be removed
					"fake_field" =>  'fake_field'
				]
			],
			[
				'fields' => [
					'attcode' => 'name2',
					'update' => '1',
					'reconcile' => '0',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					'finalclass' => 'SynchroAttribute',
					'friendlyname' => 'name2', //should be removed
					"sync_source_id" =>  'sync_source_id', //should be removed
					"sync_source_name" =>  'sync_source_name', //should be removed
					"sync_source_id_friendlyname" =>  'sync_source_id_friendlyname',  //should be removed
					"fake_field2" =>  'fake_field2',
				]
			],
		];
		$aReturnObjects2 = [
			[
				'fields' => [
					'attcode' => 'cis_list',
					'update' => '1',
					'reconcile' => '1',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					'finalclass' => 'SynchroAttLinkSet',
					'friendlyname' => 'cis_list', //should be removed
					"sync_source_id" =>  'sync_source_id', //should be removed
					"sync_source_name" =>  'sync_source_name', //should be removed
					"sync_source_id_friendlyname" =>  'sync_source_id_friendlyname',  //should be removed
					"fake_field3" =>  'fake_field3'
				]
			],
		];
		$oMockClient->expects($this->exactly(2))
			->method("Get")
			->withConsecutive(['SynchroAttribute', $sExpectedOql1], ['SynchroAttLinkSet', $sExpectedOql2])
			->willReturnOnConsecutiveCalls(['code' => 0, 'objects' => $aReturnObjects1], ['code' => 0, 'objects' => $aReturnObjects2]);

		RestClient::GetFullSynchroDataSource($aSource, "123", $oMockClient);
		$aExpected = [
			'attribute_list' => [
				[
					'attcode' => 'name',
					'finalclass' => "SynchroAttribute",
					'update' => '1',
					'reconcile' => '1',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					"fake_field" =>  'fake_field',
				],
				[
					'attcode' => 'name2',
					'finalclass' => "SynchroAttribute",
					'update' => '1',
					'reconcile' => '0',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					"fake_field2" =>  'fake_field2',
				],
				[
					'attcode' => 'cis_list',
					'finalclass' => "SynchroAttLinkSet",
					'update' => '1',
					'reconcile' => '1',
					'update_policy' => 'master_locked',
					'row_separator' => '|',
					'attribute_separator' => ';',
					'value_separator' => ':',
					'attribute_qualifier' => "'",
					"fake_field3" =>  'fake_field3',
				],
			]
		];
		$this->assertEquals($aExpected, $aSource);
	}

}
