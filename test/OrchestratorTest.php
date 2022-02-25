<?php

namespace UnitTestFiles\Test;

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

class OrchestratorTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";
	private $oMockedLogger;

	public function setUp()
	{
		parent::setUp();

		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}

		$this->oMockedLogger = $this->createMock("UtilsLogger");
		Utils::MockLog($this->oMockedLogger);
	}

	public function tearDown()
	{
		parent::tearDown();
		$aCollectorFiles = glob(self::$sCollectorPath."*");
		foreach ($aCollectorFiles as $sFile) {
			unlink($sFile);
		}
	}

	/**
	 * @param bool $sAdditionalDir
	 *
	 * @dataProvider OrgCollectorProvider
	 * @throws \Exception
	 */
	public function testOrgCollectorGetProjectName($sExpectedProjectName, $sAdditionalDir = false)
	{
		$this->copy(APPROOT."/test/getproject/common/*");
		$this->copy(APPROOT."/test/getproject/$sAdditionalDir/*");

		require_once self::$sCollectorPath."iTopPersonCsvCollector.class.inc.php";


		$this->oMockedLogger->expects($this->exactly(0))
			->method("Log");

		$oOrgCollector = new iTopPersonCsvCollector();
		$this->assertEquals($sExpectedProjectName, $oOrgCollector->GetProjectName());
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
			"empty_module_file" => array("myproject"),
			"module"            => array("centreon-collector", "module"),
		);
	}
}
