<?php

namespace UnitTestFiles\Test;

use IOException;
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

	public function setUp()
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
	}

	public function tearDown()
	{
		parent::tearDown();
		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
	}

	public function AttributeIsNullifiedProvider(){
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

		return [
			'no nullify config' => [ ''],
			'empty nullify config' => [$sEmptyConfig],
			'other attributes nullified' => [$sOtherAttributeNulllified],
			'phone nullified' => [$sPhoneNullifiedSection, true],
		];
	}

	/**
	 * @dataProvider AttributeIsNullifiedProvider
	 */
	public function testAttributeIsNullified($sCollectorXmlSubSection, $bExpectedIsNullified=false){
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

		file_put_contents(self::$sCollectorPath . "params.distrib.xml", $sXml);

		require_once self::$sCollectorPath."iTopPersonCollector.class.inc.php";
		$oCollector = new iTopPersonCollector();
		Utils::LoadConfig();

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

		if ($bExpectedIsNullified){
			$this->assertEquals($sExpectedNullifiedCsv, file_get_contents(APPROOT."/data/iTopPersonCollector-1.csv"));
		} else {
			$this->assertEquals($sExpectedCsv, file_get_contents(APPROOT."/data/iTopPersonCollector-1.csv"));
		}
	}

	private function copy($sPattern)
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
}
