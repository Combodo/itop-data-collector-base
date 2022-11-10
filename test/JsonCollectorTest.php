<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;

@define('APPROOT', dirname(__FILE__).'/../');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/jsoncollector.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class JsonCollectorTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";
	private $oMockedLogger;

	public function setUp()
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $fFile) {
			unlink($fFile);
		}

		$this->oMockedLogger = $this->createMock("UtilsLogger");
		\Utils::MockLog($this->oMockedLogger);

	}

	public function tearDown()
	{
		parent::tearDown();
		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $file) {
			unlink($file);
		}
	}

	public function testIOException()
	{
		$this->assertFalse(is_a(new Exception(""), "IOException"));
		$this->assertTrue(is_a(new \IOException(""), "IOException"));
	}

	private function copy($sPattern)
	{
		if (!is_dir(self::$sCollectorPath)) {
			mkdir(self::$sCollectorPath);
		}

		$aFiles = glob($sPattern);
		foreach ($aFiles as $fFile) {
			if (is_file($fFile)) {
				$bRes = copy($fFile, self::$sCollectorPath.basename($fFile));
				if (!$bRes) {
					throw new \Exception("Failed copying $fFile to ".JsonCollectorTest::COLLECTOR_PATH.basename($fFile));
				}
			}
		}
	}

	/*
	 * @param string $initDir initial directory of file params.distrib.xml
	 */
	private function replaceTranslateRelativePathInParam($initDir)
	{
		$sContent = str_replace("APPROOT", APPROOT, file_get_contents(APPROOT.$initDir."/params.distrib.xml"));
		print_r($sContent);
		$rHandle = fopen(APPROOT."/collectors/params.distrib.xml", "w");
		fwrite($rHandle, $sContent);
		fclose($rHandle);

	}

	/**
	 * @param bool $bAdditionalDir
	 *
	 * @dataProvider OrgCollectorProvider
	 * @throws \Exception
	 */
	public function testOrgCollector($sAdditionalDir = '')
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		$this->copy(APPROOT."/test/single_json/".$sAdditionalDir."/*");
		$this->replaceTranslateRelativePathInParam("/test/single_json/".$sAdditionalDir);

		require_once self::$sCollectorPath."ITopPersonJsonCollector.class.inc.php";

		$this->oMockedLogger->expects($this->exactly(0))
			->method("Log");

		\Utils::LoadConfig();
		$oOrgCollector = new \ITopPersonJsonCollector();

		$this->assertTrue($oOrgCollector->Collect());

		$sExpected_content = file_get_contents(self::$sCollectorPath."expected_generated.csv");

		$this->assertEquals($sExpected_content, file_get_contents(APPROOT."/data/ITopPersonJsonCollector-1.csv"));
	}

	public function OrgCollectorProvider()
	{
		return array(
			"default_value" => array("default_value"),
			"format_json_1" => array("format_json_1"),
			"format_json_2" => array("format_json_2"),
			"format_json_3" => array("format_json_3"),
			"first row nullified function" => array("nullified_json_1"),
			"another row nullified function" => array("nullified_json_2"),
		);
	}

	/**
	 * @param $additional_dir
	 * @param $error_msg
	 * @param bool $exception_msg
	 *
	 * @throws \Exception
	 * @dataProvider ErrorFileProvider
	 */
	public function testJsonErrors($sAdditionalDir, $sErrorMsg, $sExceptionMsg = false, $sExceptionMsg3 = false)
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		$this->copy(APPROOT."/test/single_json/json_error/".$sAdditionalDir."/*");

		$this->replaceTranslateRelativePathInParam("/test/single_json/json_error/".$sAdditionalDir);

		require_once self::$sCollectorPath."ITopPersonJsonCollector.class.inc.php";

		\Utils::LoadConfig();
		$oOrgCollector = new \ITopPersonJsonCollector();

		if ($sExceptionMsg3) {
			$this->oMockedLogger->expects($this->exactly(3))
				->method("Log")
				->withConsecutive(array(LOG_ERR, $sErrorMsg), array(LOG_ERR, $sExceptionMsg), array(LOG_ERR, $sExceptionMsg3));
		} elseif ($sExceptionMsg) {
			$this->oMockedLogger->expects($this->exactly(2))
				->method("Log")
				->withConsecutive(array(LOG_ERR, $sErrorMsg), array(LOG_ERR, $sExceptionMsg));
		} elseif ($sErrorMsg) {
			$this->oMockedLogger->expects($this->exactly(1))
				->method("Log")
				->withConsecutive(array(LOG_ERR, $sErrorMsg));
		} else {
			$this->oMockedLogger->expects($this->exactly(0))
				->method("Log");
		}
		try {
			$bResult = $oOrgCollector->Collect();

			$this->assertEquals($sErrorMsg ? false : true, $bResult);
		} catch (Exception $e) {
			$this->assertEquals($sExceptionMsg, $e->getMessage());
		}
	}

	public function ErrorFileProvider()
	{
		return array(
			"error_json_1" => array(
				"error_json_1",
				"[ITopPersonJsonCollector] The column \"first_name\", used for reconciliation, is missing in the json file.",
				"ITopPersonJsonCollector::Collect() got an exception: Missing columns in the json file.",
			),
			"error_json_2" => array(
				"error_json_2",
				"[ITopPersonJsonCollector] Failed to find path objects/*/blop until data in json file:  ".APPROOT."/collectors/dataTest.json.",
				"ITopPersonJsonCollector::Prepare() returned false",
			),
			"error_json_3" => array(
				"error_json_3",
				'[ITopPersonJsonCollector] Failed to translate data from JSON file: \''.APPROOT.'/collectors/dataTest.json\'. Reason: Syntax error',
				"ITopPersonJsonCollector::Prepare() returned false",
			),
		);
	}

	public function testFetchWithEmptyJson()
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		require_once self::$sCollectorPath."ITopPersonJsonCollector.class.inc.php";

		$oiTopCollector = new \ITopPersonJsonCollector();
		try {
			$bResult = $oiTopCollector->Fetch();
			$this->assertEquals(false, $bResult, "JsonCollector::Fetch returns true though CollectoClass::aJson is empty");
		} catch (Exception $e) {
			$this->fail($e->getMessage());
		}

	}
}
