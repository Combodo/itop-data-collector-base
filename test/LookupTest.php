<?php

namespace UnitTestFiles\Test;

use iTopPersonCollector;
use LookupTable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;
use RestClient;
use Utils;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');
require_once(APPROOT.'core/lookuptable.class.inc.php');
require_once(APPROOT.'core/restclient.class.inc.php');

class LookupTest extends TestCase
{
	private $oRestClient;
	private $oLookupTable;

	public function setUp(): void
	{
		parent::setUp();

		$this->oRestClient = $this->createMock(RestClient::class);

		LookupTable::SetRestClient($this->oRestClient);

		$this->oMockedLogger = $this->createMock("UtilsLogger");
		Utils::MockLog($this->oMockedLogger, LOG_DEBUG);

	}

    public function tearDown(): void
	{
		parent::tearDown();

	}

	/*
	 * test function lookup from class LookupTable
	 * */
	public function LookupProvider() {
		return [
			'normal' => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "Microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', "10.0.19044"],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => false,
				'bCaseSensitive' => false,
				'bIgnoreMappingErrors' => false,
				'sExpectedRes' => true,
				'sExpectedErrorType' => '',
				'sExpectedErrorMessage' => '',
				'sExpectedValue' => 61,
			],
			'casesensitive_true' => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "Microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', "10.0.19044"],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => false,
				'bCaseSensitive' => true,
				'bIgnoreMappingErrors' => false,
				'sExpectedRes' => true,
				'sExpectedErrorType' => '',
				'sExpectedErrorMessage' => '',
				'sExpectedValue' => 61,
			],
			'casesensitive_true_error' => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', "10.0.19044"],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => false,
				'bCaseSensitive' => true,
				'bIgnoreMappingErrors' => false,
				'sExpectedRes' => false,
				'sExpectedErrorType' => LOG_WARNING,
				'sExpectedErrorMessage' => 'No mapping found with key: \'Microsoft Windows 10_10.0.19044\', \'osversion_id\' will be set to zero.',
				'sExpectedValue' => 61,
			],
			"error_fieldNotFound_dontIgnoreMappingError" => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "Microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', "10.0.190445"],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => false,
				'bCaseSensitive' => false,
				'bIgnoreMappingErrors' => false,
				'sExpectedRes' => '',
				'sExpectedErrorType' => LOG_WARNING,
				'sExpectedErrorMessage' => 'No mapping found with key: \'microsoft windows 10_10.0.190445\', \'osversion_id\' will be set to zero.',
				'sExpectedValue' => 2,
			],
			"error_fieldNotFound_ignoreMappingError" => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "Microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', "10.0.190445"],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => false,
				'bCaseSensitive' => false,
				'bIgnoreMappingErrors' => true,
				'sExpectedRes' => true,
				'sExpectedErrorType' => LOG_DEBUG,
				'sExpectedErrorMessage' => 'No mapping found with key: \'microsoft windows 10_10.0.190445\', \'osversion_id\' will be set to zero.',
				'sExpectedValue' => "10.0.190445",
			],
			"emptyfield" => [
				'aFirstLine' => ["primary_key", "name", "osfamily_id", "osversion_id"],
				'aData' => [
					'OSVersion::61' => [
						'code' => 0,
						"message" => "",
						"class" => "OSVersion",
						"key" => "61",
						"fields" => [
							"osfamily_id" => "Microsoft Windows 10",
							"osversion_id" => "10.0.19044",
						],
					],
				],
				'aLineData' => ["1", "test_normal", 'Microsoft Windows 10', ''],
				'aLookupFields' => ["osfamily_id", "osversion_id"],
				'sDestField' => 'osversion_id',
				'iFieldIndex' => 3,
				'bSkipIfEmpty' => true,
				'bCaseSensitive' => false,
				'bIgnoreMappingErrors' => false,
				'sExpectedRes' => '',
				'sExpectedErrorType' => '',
				'sExpectedErrorMessage' => '',
				'sExpectedValue' => 2,
			],
		];
	}

	/**
	 * @dataProvider LookupProvider
	 */
	public function testLookup($aFirstLine,  $aData, $aLineData, $aLookupFields, $sDestField, $iFieldIndex, $bSkipIfEmpty, $bCaseSensitive, $bIgnoreMappingErrors, $sExpectedRes, $sExpectedErrorType, $sExpectedErrorMessage, $sExpectedValue)
	{
		$this->oRestClient->expects($this->once())
									->method('Get')
									->with('OSVersion', 'SELECT OSVersion', 'osfamily_id,osversion_id')
									->willReturn([
											'code' => 0,
											'objects' => $aData
											])  ;
		$this->oLookupTable = new LookupTable('SELECT OSVersion', $aLookupFields,$bCaseSensitive, $bIgnoreMappingErrors );

		if($sExpectedErrorType != ''){
			$this->oMockedLogger->expects($this->once())
									->method('Log')
									->with($sExpectedErrorType, $sExpectedErrorMessage) ;
		} else {
			$this->oMockedLogger->expects($this->never())
				->method('Log') ;

		}
		$this->oLookupTable->Lookup($aFirstLine, $aLookupFields, $sDestField, 0);
		$sRes = $this->oLookupTable->Lookup($aLineData, $aLookupFields, $sDestField, 1, $bSkipIfEmpty);


		$this->assertEquals( $sExpectedRes,$sRes );

		if ($sRes) {
			$this->assertEquals($sExpectedValue,$aLineData[$iFieldIndex]);
		}
}
}
