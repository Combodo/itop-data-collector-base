<?php

namespace UnitTestFiles\Test;

use Collector;
use PHPUnit\Framework\TestCase;
use Utils;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');
require_once(__DIR__.'/FakeCollector.php');

class CollectorSynchroTest extends TestCase
{
	private $oMockedCallItopService;

	public function SynchroOutputProvider(){
		return [
			'default output' => [ LOG_INFO, 'retcode' ],
			'debug level' => [ LOG_DEBUG, 'details' ],
		];
	}

	/**
	 * @dataProvider SynchroOutputProvider
	 */
	public function testSynchroOutput($iConsoleLogLevel, $sExpectedOutputRequiredToItopSynchro){
		$oCollector = new \FakeCollector();

		Utils::$iConsoleLogLevel = $iConsoleLogLevel;

		$aAdditionalData = [
			'separator' => ';',
		    'data_source_id' => 666,
		    'synchronize' => '0',
		    'no_stop_on_import_error' => 1,
		    'output' => $sExpectedOutputRequiredToItopSynchro,
		    'csvdata' => 'FAKECSVCONTENT',
		    'charset' => 'UTF-8'
		];
		$this->oMockedCallItopService->expects($this->exactly(2))
			->method('CallItopViaHttp')
			->withConsecutive(
				['/synchro/synchro_import.php?login_mode=form', $aAdditionalData],
				['/synchro/synchro_exec.php?login_mode=form', ['data_sources' => 666], -1]
			)
			->willReturn("0")
		;

		$oCollector->Synchronize();
	}

	public function setUp(): void
	{
		parent::setUp();

		$this->oMockedCallItopService = $this->createMock("CallItopService");
		Collector::SetCallItopService($this->oMockedCallItopService);


		$dataFilePath = Utils::GetDataFilePath('FakeCollector-*.csv');
		foreach(glob($dataFilePath) as $file){
			unlink($file);
		}

		file_put_contents(APPROOT . 'data/FakeCollector-1.csv', 'FAKECSVCONTENT');
	}

    public function tearDown(): void
	{
		parent::tearDown();
	}

	public function ParseSynchroOutputProvider(){
		$sRetcodeOutput = <<<OUTPUT
...
%s
OUTPUT;
		$sDetailedNoError = <<<OUTPUT
...
 #Output format: details
  #Simulate: 0
  #Change tracking comment: 
  #Issues (before synchro): %s
  #Created (before synchro): 0
  #Updated (before synchro): 1
OUTPUT;

		return [
			'retcode no error' => [
				'sOutput' => sprintf($sRetcodeOutput, 0),
				'bDetailedOutput' => false,
				'sExpectecCount' => 0
			],
			'retcode few errors' => [
				'sOutput' => sprintf($sRetcodeOutput, 10),
				'bDetailedOutput' => false,
				'sExpectecCount' => 10
			],
			'detailed no error' => [
				'sOutput' => sprintf($sDetailedNoError, 0),
				'bDetailedOutput' => true,
				'sExpectecCount' => 0
			],
			'detailed few errors' => [
				'sOutput' => sprintf($sDetailedNoError, 10),
				'bDetailedOutput' => true,
				'sExpectecCount' => 10
			],
		];
	}

	/**
	 * @dataProvider ParseSynchroOutputProvider
	 */
	public function testParseSynchroOutput($sOutput, $bDetailedOutput, $sExpectecCount){
		$this->assertEquals($sExpectecCount, Collector::ParseSynchroOutput($sOutput, $bDetailedOutput), $sOutput);
	}

}
