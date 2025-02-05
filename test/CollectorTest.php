<?php

namespace UnitTestFiles\Test;

use iTopPersonCollector;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;
use Utils;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class CollectorTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";

	public function setUp(): void
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
        // Remove params.local.xml if it exists because it can interfere with the tests
        if (file_exists(APPROOT."/conf/params.local.xml")) {
            unlink(APPROOT."/conf/params.local.xml");
        }
	}

    public function tearDown(): void
	{
		parent::tearDown();
		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
	}

	public function AttributeIsNullifiedProvider()
	{
		$sEmptyConfig = '<iTopPersonCollector_nullified_attributes></iTopPersonCollector_nullified_attributes>';
		$sOtherAttributeNulllified = <<<XML
<iTopPersonCollector_nullified_attributes type="array">
	<attribute>first_name</attribute>
</iTopPersonCollector_nullified_attributes>
XML;
		$sPhoneNullifiedSection = <<<XML
<iTopPersonCollector_nullified_attributes type="array">
	<attribute>phone</attribute>
</iTopPersonCollector_nullified_attributes>
XML;

		$sPhoneNullifiedSubSection = <<<XML
<iTopPersonCollector>
	<nullified_attributes type="array">
		<attribute>phone</attribute>
	</nullified_attributes>
</iTopPersonCollector>
XML;

		return [
			'no nullify config' => [''],
			'empty nullify config' => [$sEmptyConfig],
			'other attributes nullified' => [$sOtherAttributeNulllified],
			'phone nullified in iTopPersonCollector_nullified_attributes section' => [$sPhoneNullifiedSection, true],
			'phone nullified in nullified_attributes sub section' => [$sPhoneNullifiedSubSection, true],
		];
	}

	/**
	 * @dataProvider AttributeIsNullifiedProvider
	 */
	public function testAttributeIsNullified($sCollectorXmlSubSection, $bExpectedIsNullified = false)
	{
		$this->copy(APPROOT."/test/collector/attribute_isnullified/*");

		$sXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- Default values for parameters. Do NOT alter this file, copy its content to conf/params.local.xml and edit it instead -->
<parameters>
  <console_log_level>6</console_log_level>
  $sCollectorXmlSubSection

  <console_log_dateformat>||Y-m-d H:i:s||</console_log_dateformat>
  <synchro_user>admin</synchro_user>
  <contact_to_notify></contact_to_notify>
  <full_load_interval></full_load_interval>

  <json_placeholders>
    <prefix></prefix>
    <persons_data_table>synchro_data_person_1</persons_data_table>
    <synchro_status>production</synchro_status>
  </json_placeholders>
</parameters>
XML;

		file_put_contents(self::$sCollectorPath."params.distrib.xml", $sXml);

		require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
		Utils::LoadConfig();
		$oCollector = new iTopPersonCollector();
		$oCollector->Init();

		$this->assertEquals($bExpectedIsNullified, $oCollector->AttributeIsNullified('phone'));

		$oCollector->Collect(1);
		$sExpectedCsv = <<<CSV
primary_key;first_name;name;org_id;phone;mobile_phone;employee_number;email;function
1;isaac;asimov;Demo;;123456;9998877665544;issac.asimov@function.io;writer

CSV;
		$sExpectedNullifiedCsv = <<<CSV
primary_key;first_name;name;org_id;phone;mobile_phone;employee_number;email;function
1;isaac;asimov;Demo;<NULL>;123456;9998877665544;issac.asimov@function.io;writer

CSV;

		if ($bExpectedIsNullified) {
			$this->assertEquals($sExpectedNullifiedCsv, file_get_contents(APPROOT."/data/iTopPersonCollector-1.csv"));
		} else {
			$this->assertEquals($sExpectedCsv, file_get_contents(APPROOT."/data/iTopPersonCollector-1.csv"));
		}
	}

	protected function copy($sPattern)
	{
		if (!is_dir(self::$sCollectorPath)) {
			mkdir(self::$sCollectorPath);
		}

		$aFiles = glob($sPattern);
		foreach ($aFiles as $sFile) {
			if (is_file($sFile)) {
				if (!copy($sFile, self::$sCollectorPath.basename($sFile))) {
					throw new Exception("Failed copying $sFile to ".self::$sCollectorPath.basename($sFile));
				}
			}
		}
	}

	/**
	 * @dataProvider providerUpdateSDSAttributes
	 * @param array $aExpectedAttrDef
	 * @param array $aSynchroAttrDef
	 * @param bool $bWillUpdate
	 */
	public function testUpdateSDSAttributes($aExpectedAttrDef, $aSynchroAttrDef, bool $bWillUpdate)
	{
	    $this->copy(APPROOT."/test/collector/attribute_isnullified/*");
	    require_once APPROOT."/core/restclient.class.inc.php";
	    require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
	    $oCollector = new iTopPersonCollector();
	    $oMockClient = $this->CreateMock('RestClient');

		if ($bWillUpdate==0){
			$oMockClient->expects($this->never())->method("Update");
		} else {
			$oMockClient->expects($this->once())
				->method("Update")
				->with('SynchroAttribute')
				->willReturn(['code' => 0]);
		}

	    $bRet = $this->InvokeNonPublicMethod(get_class($oCollector), 'UpdateSDSAttributes', $oCollector, [$aExpectedAttrDef, $aSynchroAttrDef, '', $oMockClient]);

	    $this->assertTrue($bRet);
	}

	public function providerUpdateSDSAttributes()
	{
	    return [
	        'no difference' => [
	            'aExpectedAttrDef' => [
	                [
	                    "attcode" => "name",
	                    "update" => "1",
	                    "reconcile" => "1",
	                    "update_policy" => "master_locked",
	                    "finalclass" => "SynchroAttribute",
	                    "friendlyname" => "name",
	                ],
	            ],
	            'aSynchroAttrDef' => [
	                [
	                    "attcode" => "name",
	                    "update" => "1",
	                    "reconcile" => "1",
	                    "update_policy" => "master_locked",
	                    "finalclass" => "SynchroAttribute",
	                    "friendlyname" => "name",
	                ],
	            ],
	            'bWillUpdate' => false,
	        ],
	        'reconcile is different' => [
    	    'aExpectedAttrDef' => [
    	        [
    	            "attcode" => "name",
    	            "update" => "1",
    	            "reconcile" => "1",
    	            "update_policy" => "master_locked",
    	            "finalclass" => "SynchroAttribute",
    	            "friendlyname" => "name",
    	        ],
    	    ],
    	    'aSynchroAttrDef' => [
    	        [
    	            "attcode" => "name",
    	            "update" => "1",
    	            "reconcile" => "0", // Difference here
    	            "update_policy" => "master_locked",
    	            "finalclass" => "SynchroAttribute",
    	            "friendlyname" => "name",
    	        ],
    	    ],
	        'bWillUpdate' => true,
	       ],
	        'update policy is different on OPTIONAL field' => [
	            'aExpectedAttrDef' => [
	                [
	                    "attcode" => "optional", // Note: 'optional' is an attribute considered as "optional" in this test
	                    "update" => "1",
	                    "reconcile" => "1",
	                    "update_policy" => "master_locked",
	                    "finalclass" => "SynchroAttribute",
	                    "friendlyname" => "optional",
	                ],
	            ],
	            'aSynchroAttrDef' => [
	                [
	                    "attcode" => "optional",
	                    "update" => "1",
	                    "reconcile" => "1",
	                    "update_policy" => "master_unlocked",  // Difference here
	                    "finalclass" => "SynchroAttribute",
	                    "friendlyname" => "optional",
	                ],
	            ],
	            'bWillUpdate' => true,
	        ],
	        'OPTIONAL field actually missing' => [
	            'aExpectedAttrDef' => [
	            ],
	            'aSynchroAttrDef' => [
	                [
	                    "attcode" => "optional",
	                    "update" => "1",
	                    "reconcile" => "1",
	                    "update_policy" => "master_unlocked",  // Difference here
	                    "finalclass" => "SynchroAttribute",
	                    "friendlyname" => "optional",
	                ],
	            ],
	            'bWillUpdate' => false,
	        ],
	    ];
	}

	/**
	 * @param string $sObjectClass for example DBObject::class
	 * @param string $sMethodName
	 * @param object $oObject
	 * @param array $aArgs
	 *
	 * @return mixed method result
	 *
	 * @throws \ReflectionException
	 *
	 * @since 2.7.4 3.0.0
	 */
	public function InvokeNonPublicMethod($sObjectClass, $sMethodName, $oObject, $aArgs)
	{
	    $class = new \ReflectionClass($sObjectClass);
	    $method = $class->getMethod($sMethodName);
	    $method->setAccessible(true);

	    return $method->invokeArgs($oObject, $aArgs);
	}

	public function CreateSynchroDataSourceProvider() {
		return [
			'all readonly fields' => [
				'aSourceDefinition' => [
					'attribute_list' => [],
					'friendlyname' => [],
					'user_id_friendlyname' => [],
					'user_id_finalclass_recall' => [],
					'notify_contact_id_friendlyname' => [],
					'notify_contact_id_finalclass_recall' => [],
					'notify_contact_id_obsolescence_flag' => [],
					'notify_contact_id_archive_flag' => [],
					'name' => [],
				],
				'aExpectedSourceDefinition' => [
					'name' => [],
				],
			],
			'subset of all readonly fields' => [
				'aSourceDefinition' => [
					'attribute_list' => [],
					'friendlyname' => [],
					'user_id_friendlyname' => [],
					'user_id_finalclass_recall' => [],
					'notify_contact_id_friendlyname' => [],
					'notify_contact_id_archive_flag' => [],
					'name' => [],
				],
				'aExpectedSourceDefinition' => [
					'name' => [],
				],
			],
		];
	}

	/**
	 * @dataProvider CreateSynchroDataSourceProvider
	 * @param array $aSourceDefinition
	 * @param array $aExpectedSourceDefinition
	 */
	public function testCreateSynchroDataSource($aSourceDefinition, $aExpectedSourceDefinition)
	{

		$this->copy(APPROOT."/test/collector/attribute_isnullified/*");
		require_once APPROOT."/core/restclient.class.inc.php";
		require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
		$oCollector = new iTopPersonCollector();
		$oMockClient = $this->CreateMock('RestClient');

		$sComment="COMMENT";

		$oMockClient->expects($this->once())
				->method("Create")
				->with('SynchroDataSource',$aExpectedSourceDefinition, $sComment)
				->willReturn(['code' => 0, 'objects' => [ ['key' => '123', 'fields'=> ['attribute_list' => []], ]]]);


		$bRet = $this->InvokeNonPublicMethod(get_class($oCollector), 'CreateSynchroDataSource', $oCollector, [$aSourceDefinition, $sComment, $oMockClient]);

		$this->assertEquals('123', $bRet);
		$this->assertEquals('123', $oCollector->GetSourceId());
	}

	/**
	 * @dataProvider CreateSynchroDataSourceProvider
	 * @param array $aSourceDefinition
	 * @param array $aExpectedSourceDefinition
	 */
	public function testUpdateSynchroDataSource($aSourceDefinition, $aExpectedSourceDefinition)
	{
		$this->copy(APPROOT."/test/collector/attribute_isnullified/*");
		require_once APPROOT."/core/restclient.class.inc.php";
		require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
		$oCollector = new iTopPersonCollector();
		$oMockClient = $this->CreateMock('RestClient');

		$sComment="COMMENT";

		$oMockClient->expects($this->once())
			->method("Update")
			->with('SynchroDataSource', "123", $aExpectedSourceDefinition, $sComment)
			->willReturn(['code' => 0, 'objects' => [ ['key' => '123', 'fields'=> ['attribute_list' => []], ]]]);

		$this->InvokeNonPublicMethod(get_class($oCollector), 'SetSourceId', $oCollector, ['123']);
		$bRet = $this->InvokeNonPublicMethod(get_class($oCollector), 'UpdateSynchroDataSource', $oCollector, [$aSourceDefinition, $sComment, $oMockClient]);

		$this->assertEquals('123', $bRet);
	}

	public function DataSourcesAreEquivalentProvider() {
		return [
			'exactly same ds' => [ "ds1.json", "ds1.json", true ],

			'ds1 vs ds1_oneadditional_field' => [ "ds1.json", "ds1_oneadditional_field.json", true ],
			'ds1_oneadditional_field vs ds1' => [ "ds1_oneadditional_field.json", "ds1.json", false ],

			'ds1 vs ds1_oneadditionnal_attributelist_field' => [ "ds1.json", "ds1_oneadditionnal_attributelist_field.json", true],
			'ds1_oneadditionnal_attributelist_field vs ds1' => [ "ds1_oneadditionnal_attributelist_field.json", "ds1.json", false],

			'optional attribute case: ds1 vs ds1_oneadditionnal_attributelist_field' => [ "ds1.json", "ds1_oneadditionnal_attributelist_field.json", true, ['name'] ],
			'optional attribute case: ds1_oneadditionnal_attributelist_field vs ds1' => [ "ds1_oneadditionnal_attributelist_field.json", "ds1.json", true, ['name']],

			'ds1 vs ds1_onefieldvalue_different' => [ "ds1.json", "ds1_onefieldvalue_different.json", false ],
			'ds1_onefieldvalue_different vs ds1' => [ "ds1_onefieldvalue_different.json", "ds1.json", false ],

			'ds1 vs ds1_same_attributelist_fields_onedifferentvalue' => [ "ds1.json", "ds1_same_attributelist_fields_onedifferentvalue.json", false ],
			'ds1_same_attributelist_fields_onedifferentvalue vs ds1' => [ "ds1_same_attributelist_fields_onedifferentvalue.json", "ds1.json", false ],

			'optional attribute case: ds1 vs ds1_same_attributelist_fields_onedifferentvalue' => [ "ds1.json", "ds1_same_attributelist_fields_onedifferentvalue.json", true, ['team_list'] ],
			'optional attribute case: ds1_same_attributelist_fields_onedifferentvalue vs ds1' => [ "ds1_same_attributelist_fields_onedifferentvalue.json", "ds1.json", true, ['team_list'] ],
		];
	}

	/**
	 * @dataProvider DataSourcesAreEquivalentProvider
	 *
	 * @param string $sDS1Path
	 * @param string $sDS2Path
	 * @param bool $bExpected
	 */
	public function testDataSourcesAreEquivalent($sDS1Path, $sDS2Path, $bExpected, $aOptionalAttributes=[]) {
		$this->copy(APPROOT."/test/collector/attribute_isnullified/*");
		require_once APPROOT."/core/restclient.class.inc.php";
		require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
		$oCollector = new iTopPersonCollector();
		$oCollector->SetOptionalAttributes($aOptionalAttributes);

		$sResourceDir = __DIR__ . '/collector/datasources/';
		$sDS1 = json_decode(file_get_contents($sResourceDir . $sDS1Path), true);
		$sDS2 = json_decode(file_get_contents($sResourceDir . $sDS2Path), true);
		$bRet = $this->InvokeNonPublicMethod(get_class($oCollector), 'DataSourcesAreEquivalent', $oCollector, [ $sDS1, $sDS2 ]);

		$this->assertEquals($bExpected, $bRet, "$sDS1Path vs $sDS2Path");
	}
}
