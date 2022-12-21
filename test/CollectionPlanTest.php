<?php

namespace UnitTestFiles\Test;

use ExtendedCollector;
use Orchestrator;
use PHPUnit\Framework\TestCase;
use StandardCollector;
use TestCollectionPlan;

@define('APPROOT', dirname(__FILE__).'/../');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collectionplan.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class CollectionPlanTest extends TestCase
{
	private static $sCollectorPath = APPROOT."/collectors/";
	private $oMockedLogger;

	public function setUp():void
	{
		parent::setUp();

		$this->CleanCollectorsFiles();
	}

	public function tearDown():void
	{
		parent::tearDown();
		$this->CleanCollectorsFiles();
	}

	/**
	 * @return void
	 * @throws \IOException
	 */
	public function testGetSortedLaunchSequence()
	{
		$this->CopyCollectorsFiles();

		require_once self::$sCollectorPath."src/TestCollectionPlan.class.inc.php";
		$oTestCollectionPlan = new TestCollectionPlan();
		$oTestCollectionPlan->Init();

		$aCollectorsLaunchSequence = $oTestCollectionPlan->GetSortedLaunchSequence();

		foreach ($aCollectorsLaunchSequence as $iKey => $aCollector) {
			$this->assertFalse($aCollector['name'] == 'StandardCollectorWithNoRank');
		}
		$this->assertTrue($aCollectorsLaunchSequence[1]['name'] == 'ExtendedCollector');
	}

	/**
	 * @return void
	 * @throws \IOException
	 */
	public function testGetCollectorDefinitionFile()
	{
		$this->CopyCollectorsFiles();

		require_once self::$sCollectorPath."src/TestCollectionPlan.class.inc.php";
		$oTestCollectionPlan = new TestCollectionPlan();
		$oTestCollectionPlan->Init();

		$this->assertTrue($oTestCollectionPlan->GetCollectorDefinitionFile('ExtendedCollector'));
		$this->assertTrue($oTestCollectionPlan->GetCollectorDefinitionFile('StandardCollector'));
		$this->assertTrue($oTestCollectionPlan->GetCollectorDefinitionFile('LegacyCollector'));
		$this->assertFalse($oTestCollectionPlan->GetCollectorDefinitionFile('OtherCollector'));
	}

	/**
	 * @return void
	 * @throws \IOException
	 */
	public function testAddCollectorsToOrchestrator()
	{
		$aCollector = ['ExtendedCollector', 'StandardCollector', 'LegacyCollector', 'OtherCollector'];

		$this->CopyCollectorsFiles();

		require_once self::$sCollectorPath."src/TestCollectionPlan.class.inc.php";
		$oTestCollectionPlan = new TestCollectionPlan();
		$oTestCollectionPlan->Init();
		$oTestCollectionPlan->AddCollectorsToOrchestrator();

		$oOrchestrator = new Orchestrator();
		$aOrchestratedCollectors = $oOrchestrator->ListCollectors();

		$this->assertArrayHasKey(0, $aOrchestratedCollectors);
		$this->assertTrue(($aOrchestratedCollectors[0] instanceof StandardCollector) || ($aOrchestratedCollectors[0] instanceof ExtendedCollector));
		$this->assertArrayHasKey(1, $aOrchestratedCollectors);
		$this->assertTrue(($aOrchestratedCollectors[1] instanceof StandardCollector) || ($aOrchestratedCollectors[1] instanceof ExtendedCollector));
	}

	private function CopyCollectorsFiles()
	{
		$aPatterns = [
			'' => '/test/collectionplan/collectors_files/*',
			'extensions/' => '/test/collectionplan/collectors_files/extensions/*',
			'extensions/src/' => '/test/collectionplan/collectors_files/extensions/src/*',
			'extensions/json/' => '/test/collectionplan/collectors_files/extensions/json/*',
			'src/' => '/test/collectionplan/collectors_files/src/*',
			'json/' => '/test/collectionplan/collectors_files/json/*',
		];
		foreach ($aPatterns as $sDir => $sPattern) {
			if (!is_dir(self::$sCollectorPath.$sDir)) {
				mkdir(self::$sCollectorPath.$sDir);
			}
			$this->CopyFile($sDir, APPROOT.$sPattern);
		}
	}

	private function CopyFile($sDir, $sPattern)
	{
		$aFiles = glob($sPattern);
		foreach ($aFiles as $sFile) {
			if (is_file($sFile)) {
				$bRes = copy($sFile, self::$sCollectorPath.'/'.$sDir.basename($sFile));
				if (!$bRes) {
					throw new Exception("Failed copying $sFile to ".self::$sCollectorPath.'/'.$sDir.basename($sFile));
				}
			}
		}
	}

	private function CleanCollectorsFiles()
	{
		$aPatterns = [
			'extensions/src/' => 'extensions/src/*',
			'extensions/json/' => 'extensions/json/*',
			'extensions/' => 'extensions/*',
			'src/' => 'src/*',
			'json/' => 'json/*',
			'' => '*',
		];
		foreach ($aPatterns as $sDir => $sPattern) {
			$aCollectorFiles = glob(self::$sCollectorPath.$sPattern);
			foreach ($aCollectorFiles as $sFile) {
				unlink($sFile);
			}

			if (is_dir(self::$sCollectorPath.$sDir)) {
				rmdir(self::$sCollectorPath.$sDir);
			}
		}
	}

}
