<?php

namespace UnitTestFiles\Test;

use IOException;
use iTopPersonCsvCollector;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;
use Utils;

@define('APPROOT', dirname(__FILE__).'/../');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/csvcollector.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class CsvCollectorTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";
	private $oMockedLogger;

	public function setUp(): void
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}

		$this->oMockedLogger = $this->createMock("UtilsLogger");
		Utils::MockLog($this->oMockedLogger);
	}

	public function tearDown(): void
	{
		parent::tearDown();
		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
	}

	public function testIOException()
	{
		$this->assertFalse(is_a(new Exception(""), "IOException"));
		$this->assertTrue(is_a(new IOException(""), "IOException"));
	}

	/**
	 * @param bool $sAdditionalDir
	 *
	 * @dataProvider OrgCollectorProvider
	 * @throws \Exception
	 */
	public function testOrgCollector($sAdditionalDir = false)
	{
		$this->copy(APPROOT."/test/single_csv/common/*");
		$this->copy(APPROOT."/test/single_csv/".$sAdditionalDir."/*");

		require_once self::$sCollectorPath."iTopPersonCsvCollector.class.inc.php";

		$this->oMockedLogger->expects($this->exactly(0))
			->method("Log");

		$oOrgCollector = new iTopPersonCsvCollector();
		Utils::LoadConfig();
		$oOrgCollector->Init();

		$this->assertTrue($oOrgCollector->Collect());

		$sExpected_content = file_get_contents(self::$sCollectorPath."expected_generated.csv");

		$this->assertEquals($sExpected_content, file_get_contents(APPROOT."/data/iTopPersonCsvCollector-1.csv"));
	}

	private function copy($sPattern)
	{
		if (!is_dir(self::$sCollectorPath)) {
			mkdir(self::$sCollectorPath);
		}

		$aFiles = glob($sPattern);
		foreach ($aFiles as $sFile) {
			if (is_file($sFile)) {
				$bRes = copy($sFile, self::$sCollectorPath.basename($sFile));
				if (!$bRes) {
					throw new Exception("Failed copying $sFile to ".self::$sCollectorPath.basename($sFile));
				}
			}
		}
	}

	public function OrgCollectorProvider()
	{
		return array(
			"nominal" => array("nominal"),
			"charset_ISO" => array("charset_ISO"),
			"separator" => array("separator"),
			"separator_tab" => array("separator_tab"),
			"clicommand" => array("clicommand"),
			"adding hardcoded values" => array("hardcoded_values_add"),
			"replacing hardcoded values" => array("hardcoded_values_replace"),
			"ignored attributes" => array("ignored_attributes"),
			"configured header" => array("configured_header"),
			"mapping" => array("mapping"),
			"separator_incolumns" => array("separator_incolumns"),
			"return_in_fieldvalues" => array("return_in_fieldvalues"),
            "mapping same column twice" => array("map_column_twice"),
		);
	}

	public function testAbsolutePath()
	{
		$this->copy(APPROOT."/test/single_csv/common/*");
		$sTargetDir = tempnam(sys_get_temp_dir(), 'build-');
		@unlink($sTargetDir);
		mkdir($sTargetDir);
		$sTargetDir = realpath($sTargetDir);
		$sContent = str_replace("TMPDIR", $sTargetDir, file_get_contents(APPROOT."/test/single_csv/absolutepath/params.distrib.xml"));
		$rHandle = fopen(APPROOT."/collectors/params.distrib.xml", "w");
		fwrite($rHandle, $sContent);
		fclose($rHandle);

		$sCsvFile = dirname(__FILE__)."/single_csv/nominal/iTopPersonCsvCollector.csv";
		if (is_file($sCsvFile)) {
			copy($sCsvFile, $sTargetDir."/iTopPersonCsvCollector.csv");
		} else {
			throw new \Exception("Cannot find $sCsvFile file");
		}

		require_once self::$sCollectorPath."iTopPersonCsvCollector.class.inc.php";

		$this->oMockedLogger->expects($this->exactly(0))
			->method("Log");

		$oOrgCollector = new iTopPersonCsvCollector();
		Utils::LoadConfig();
		$oOrgCollector->Init();

		$this->assertTrue($oOrgCollector->Collect());

		$sExpected_content = file_get_contents(dirname(__FILE__)."/single_csv/nominal/expected_generated.csv");

		$this->assertEquals($sExpected_content, file_get_contents(APPROOT."/data/iTopPersonCsvCollector-1.csv"));
	}

	/**
	 * @param $error_file
	 * @param $error_msg
	 * @param bool $exception_msg
	 *
	 * @throws \Exception
	 * @dataProvider ErrorFileProvider
	 */
	public function testCsvErrors($sErrorFile, $sErrorMsg, $sExceptionMsg = false)
	{
		$this->copy(APPROOT."/test/single_csv/common/*");
		copy(APPROOT."/test/single_csv/csv_errors/$sErrorFile", self::$sCollectorPath."iTopPersonCsvCollector.csv");

		require_once self::$sCollectorPath."iTopPersonCsvCollector.class.inc.php";
		$orgCollector = new iTopPersonCsvCollector();
		Utils::LoadConfig();
		$orgCollector->Init();

		if ($sExceptionMsg) {
			$this->oMockedLogger->expects($this->exactly(2))
				->method("Log")
				->withConsecutive(array(LOG_ERR, $sErrorMsg), array(LOG_ERR, $sExceptionMsg));
		} else {
			if ($sErrorMsg) {
				$this->oMockedLogger->expects($this->exactly(1))
					->method("Log");
			} else {
				$this->oMockedLogger->expects($this->exactly(0))
					->method("Log");
			}
		}
		try {
			$bResult = $orgCollector->Collect();

			$this->assertEquals($sExceptionMsg ? false : true, $bResult);
		} catch (Exception $e) {
			$this->assertEquals($sExceptionMsg, $e->getMessage());
		}
	}

	public function ErrorFileProvider()
	{
		return array(
			"wrong number of line" => array(
				"wrongnumber_columns_inaline.csv",
				"[iTopPersonCsvCollector] Wrong number of columns (1) on line 2 (expected 18 columns just like in header): aa",
				'iTopPersonCsvCollector::Collect() got an exception: Invalid CSV file.',
			),
			"no primary key" => array(
				"no_primarykey.csv",
				"[iTopPersonCsvCollector] The mandatory column \"primary_key\" is missing in the csv file.",
				'iTopPersonCsvCollector::Collect() got an exception: Missing columns in the csv file.',
			),
			"no email" => array(
				"no_email.csv",
				"[iTopPersonCsvCollector] The column \"email\", used for reconciliation, is missing in the csv file.",
				"iTopPersonCsvCollector::Collect() got an exception: Missing columns in the csv file.",
			),
			"empty csv" => array(
				"empty_file.csv",
				"[iTopPersonCsvCollector] CSV file is empty. Data collection stops here.",
				"",
			),
			"empty csv with header" => array(
				"empty_file_with_header.csv",
				"[iTopPersonCsvCollector] CSV file is empty. Data collection stops here.",
				"",
			),
			"OK" => array("../nominal/iTopPersonCsvCollector.csv", ""),
		);
	}

	public function testExploode()
	{
		$stest = "primary_key;first_name;name;org_id;phone;mobile_phone;employee_number;email;function;status";
		$aValues = array(
			"primary_key",
			"first_name",
			"name",
			"org_id",
			"phone",
			"mobile_phone",
			"employee_number",
			"email",
			"function",
			"status",
		);
		$this->assertEquals($aValues, explode(";", $stest));
	}
}
