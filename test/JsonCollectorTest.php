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
require_once(APPROOT.'core/polyfill.inc.php');

class JsonCollectorTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";
	private $oMockedLogger;

	public function setUp(): void
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $fFile) {
			unlink($fFile);
		}

		$this->oMockedLogger = $this->createMock("UtilsLogger");
		\Utils::MockLog($this->oMockedLogger);

	}

	public function tearDown(): void
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
				$sPath = self::$sCollectorPath.basename($fFile);
				$bRes = copy($fFile, $sPath);
				if (!$bRes) {
					throw new \Exception("Failed copying $fFile to $sPath");
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

		// WARNING: must call LoadConfig before Init.
		\Utils::LoadConfig();
		$oOrgCollector = new \ITopPersonJsonCollector();
		$oOrgCollector->Init();

		$this->assertTrue($oOrgCollector->Collect());

		$sExpected_content = file_get_contents(self::$sCollectorPath."expected_generated.csv");

		$this->assertEquals($sExpected_content, file_get_contents(APPROOT."/data/ITopPersonJsonCollector-1.csv"));
	}

	public static function OrgCollectorProvider()
	{
		return [
			"multicolumns_attachment" => [ "multicolumns_attachment" ],
			"default_value" => [ "default_value" ],
			"format_json_1" => [ "format_json_1" ],
			"format_json_2" => [ "format_json_2" ],
			"format_json_3" => [ "format_json_3" ],
			"sort of object xpath parsing via a key" => [ "format_json_4" ],
			"sort of object xpath parsing via an index" => [ "format_json_5" ],
			"first row nullified function" => [ "nullified_json_1" ],
			"another row nullified function" => [ "nullified_json_2" ],
			"json file with relative path" => [ "json_file_with_relative_path" ],
		];
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
		$oOrgCollector = new \ITopPersonJsonCollector();
		\Utils::LoadConfig();
		$oOrgCollector->Init();

		if ($sExceptionMsg3) {
			$this->oMockedLogger->expects($this->exactly(3))
				->method("Log")
				->withConsecutive([LOG_ERR, $sErrorMsg], [LOG_ERR, $sExceptionMsg], [LOG_ERR, $sExceptionMsg3]);
		} elseif ($sExceptionMsg) {
			$this->oMockedLogger->expects($this->exactly(2))
				->method("Log")
				->withConsecutive([LOG_ERR, $sErrorMsg], [LOG_ERR, $sExceptionMsg]);
		} elseif ($sErrorMsg) {
			$this->oMockedLogger->expects($this->exactly(1))
				->method("Log")
				->withConsecutive([LOG_ERR, $sErrorMsg]);
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

	public static function ErrorFileProvider()
	{
		return [
			"error_json_1" => [
				"error_json_1",
				"[ITopPersonJsonCollector] The field \"first_name\", used for reconciliation, has missing column(s) in the json file.",
				"ITopPersonJsonCollector::Collect() got an exception: Missing columns in the json file.",
			],
			"error_json_2" => [
				"error_json_2",
				"[ITopPersonJsonCollector] Failed to find path objects/*/blop until data in json file:  ".APPROOT."/collectors/dataTest.json.",
				"ITopPersonJsonCollector::Prepare() returned false",
			],
			"error_json_3" => [
				"error_json_3",
				'[ITopPersonJsonCollector] Failed to translate data from JSON file: \''.APPROOT.'/collectors/dataTest.json\'. Reason: Syntax error',
				"ITopPersonJsonCollector::Prepare() returned false",
			],
		];
	}

	public function testFetchWithEmptyJson()
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		require_once self::$sCollectorPath."ITopPersonJsonCollector.class.inc.php";

		$oiTopCollector = new \ITopPersonJsonCollector();
		$oiTopCollector->Init();
		try {
			$bResult = $oiTopCollector->Fetch();
			$this->assertEquals(false, $bResult, "JsonCollector::Fetch returns true though CollectoClass::aJson is empty");
		} catch (Exception $e) {
			$this->fail($e->getMessage());
		}
	}

	public function testSearchByKey()
	{
		$sJson = <<<JSON
{
  "Id": "1",
  "Shadok": {
    "name": "gabuzomeu"
  }
}
JSON;
		$aFieldPaths = [
			'primary_key' => "Id",
			'name' => "Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchByKeyAndStar()
	{
		$sJson = <<<JSON
[
  {
    "Id": "1",
    "Shadok": {
      "name": "gabuzomeu"
    }
  }
]
JSON;
		$aFieldPaths = [
			'primary_key' => "*/Id",
			'name' => "*/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchInItopJsonStructure()
	{
		$sJson = <<<JSON
{
	"Obj::1": {
	  "Id": 1,
	  "Shadok": {
	    "name": "gabuzomeu"
	  }
	}
}
JSON;

		$aFieldPaths = [
			'primary_key' => "*/Id",
			'name' => "*/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchByKeyAndStar2()
	{
		$sJson = <<<JSON
[
  {
    "Id": "1"
  },
  {
    "Shadok": {
      "name": "gabuzomeu"
    }
  }
]
JSON;
		$aFieldPaths = [
			'primary_key' => "*/Id",
			'name' => "*/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchByKeyAndStar3()
	{
		$sJson = <<<JSON
{
  "XXX": {
    "Id": "1"
  },
  "YYY": {
    "Shadok": {
      "name": "gabuzomeu"
    }
  }
}
JSON;
		$aFieldPaths = [
			'primary_key' => "*/Id",
			'name' => "*/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchByKeyAndStar4()
	{
		$sJson = <<<JSON
{
  "XXX": {
    "Id": "1"
  },
  "YYY": {
    "Shadok": {
      "name": "gabuzomeu"
    }
  }
}
JSON;
		$aFieldPaths = [
			'primary_key' => "*/Id",
			'name' => "*/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function testSearchByKeyAndIndex()
	{
		$sJson = <<<JSON
[
  {
    "Id": "1"
  },
  {
    "Shadok": {
      "name": "gabuzomeu"
    }
  }
]
JSON;
		$aFieldPaths = [
			'primary_key' => "0/Id",
			'name' => "1/Shadok/name",
		];

		$aFetchedFields = $this->CallSearchFieldValues($sJson, $aFieldPaths);
		$this->assertEquals(
			['primary_key' => '1', 'name' => 'gabuzomeu'],
			$aFetchedFields,
			var_export($aFetchedFields, true)
		);
	}

	public function CallSearchFieldValues($sJson, $aFieldPaths)
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		$this->copy(APPROOT."/test/single_json/format_json_1/*");
		$this->replaceTranslateRelativePathInParam("/test/single_json/format_json_1");

		require_once self::$sCollectorPath."ITopPersonJsonCollector.class.inc.php";

		$this->oMockedLogger->expects($this->exactly(0))
			->method("Log");

		\Utils::LoadConfig();
		$oOrgCollector = new \ITopPersonJsonCollector();
		$oOrgCollector->Init();

		$this->assertTrue($oOrgCollector->Prepare());

		$aData = json_decode($sJson, true);

		$class = new \ReflectionClass("JsonCollector");
		$method = $class->getMethod("SearchFieldValues");
		$method->setAccessible(true);
		return $method->invokeArgs($oOrgCollector, [$aData, $aFieldPaths]);
	}

	public function testFetchWithIgnoredAttributesJson()
	{
		$this->copy(APPROOT."/test/single_json/common/*");
		$this->copy(APPROOT."/test/single_json/ignored_attributes/*");
		require_once self::$sCollectorPath."/ITopPersonJsonCollector.class.inc.php";
		$this->replaceTranslateRelativePathInParam("/test/single_json/ignored_attributes/");

		$this->oMockedLogger->expects($this->exactly(0))->method("Log");

		// WARNING: must call LoadConfig before Init.
		\Utils::LoadConfig();

		$oiTopCollector = new \ITopPersonJsonCollector();
		$oiTopCollector->Init();
		//test private method DataSourcesAreEquivalent
		//this method  filled the array aSkippedAttributes used in Collect method
		$oiTopCollector->testDataSourcesAreEquivalent([]);

		$this->assertTrue($oiTopCollector->Collect());

		$sExpected_content = file_get_contents(self::$sCollectorPath."expected_generated.csv");

		$this->assertEquals($sExpected_content, file_get_contents(APPROOT."/data/ITopPersonJsonCollector-1.csv"));

	}
}
