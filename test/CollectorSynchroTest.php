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
			'default output' => [
				'iConsoleLogLevel' => LOG_INFO,
				'sExpectedOutputRequiredToItopSynchro' => 'retcode',
				'sCallItopViaHttpOutput' => sprintf($sRetcodeOutput, 0)
			],
			'debug level' => [
				'iConsoleLogLevel' => 7,
				'sExpectedOutputRequiredToItopSynchro' => 'details',
				'sCallItopViaHttpOutput' => sprintf($sDetailedNoError, 0)
			],
		];
	}

	/**
	 * @dataProvider SynchroOutputProvider
	 */
	public function testSynchroOutput($iConsoleLogLevel, $sExpectedOutputRequiredToItopSynchro, $sCallItopViaHttpOutput){
		$oCollector = new \FakeCollector();

		Utils::$iConsoleLogLevel = $iConsoleLogLevel;

		$aAdditionalData = [
			'separator' => ';',
		    'data_source_id' => 666,
		    'synchronize' => '0',
		    'no_stop_on_import_error' => 1,
		    'output' => $sExpectedOutputRequiredToItopSynchro,
		    'csvdata' => 'FAKECSVCONTENT',
		    'charset' => 'UTF-8',
            'date_format' => 'Y-m-d'
		];
		$this->oMockedCallItopService->expects($this->exactly(2))
			->method('CallItopViaHttp')
			->withConsecutive(
				['/synchro/synchro_import.php?login_mode=form', $aAdditionalData],
				['/synchro/synchro_exec.php?login_mode=form', ['data_sources' => 666], -1]
			)
			->willReturn($sCallItopViaHttpOutput)
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

	public function ParseSynchroImportOutputProvider(){
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
			'weird output' => [
				'sOutput' => "weird output",
				'bDetailedOutput' => true,
				'sExpectecCount' => -1
			],
		];
	}

	/**
	 * @dataProvider ParseSynchroImportOutputProvider
	 */
	public function testParseSynchroImportOutput($sOutput, $bDetailedOutput, $sExpectecCount){
		$this->assertEquals($sExpectecCount, Collector::ParseSynchroImportOutput($sOutput, $bDetailedOutput), $sOutput);
	}

	public function ParseSynchroExecOutput(){
		$sFailedOutput = <<<TXT
<p>Working on Synchro LDAP Person (id=3)...<p>Replicas: 14</p><p>Replicas touched since last synchro: 0</p><p>Objects deleted: 0</p><p>Objects deletion errors: 1</p><p>Objects obsoleted: 0</p><p>Objects obsolescence errors: 2</p><p>Objects created: 0 (0 warnings)</p><p>Objects creation errors: 3</p><p>Objects updated: 0 (0 warnings)</p><p>Objects update errors: 4</p><p>Objects reconciled (updated): 0 (0 warnings)</p><p>Objects reconciled (unchanged): 0 (0 warnings)</p><p>Objects reconciliation errors: 5</p><p>Replica disappeared, no action taken: 0</p>
TXT;

		$sFailedOutputWithNoErrorCount = <<<TXT
<p>Working on Synchro LDAP Person (id=3)...</p><p>ERROR: All records have been untouched for some time (all of the objects could be deleted). Please check that the process that writes into the synchronization table is still running. Operation cancelled.</p><p>Replicas: 14</p><p>Replicas touched since last synchro: 0</p><p>Objects deleted: 0</p><p>Objects deletion errors: 0</p><p>Objects obsoleted: 0</p><p>Objects obsolescence errors: 0</p><p>Objects created: 0 (0 warnings)</p><p>Objects creation errors: 0</p><p>Objects updated: 0 (0 warnings)</p><p>Objects update errors: 0</p><p>Objects reconciled (updated): 0 (0 warnings)</p><p>Objects reconciled (unchanged): 0 (0 warnings)</p><p>Objects reconciliation errors: 0</p><p>Replica disappeared, no action taken: 0</p>
TXT;
		$sFailedNoMatch = <<<TXT
NOMATCH
TXT;

		$sMsgOK = <<<TXT
<p>Working on Synchro LDAP Person (id=3)...<p>Replicas: 14</p><p>Replicas touched since last synchro: 0</p><p>Objects deleted: 0</p><p>Objects deletion errors: 0</p><p>Objects obsoleted: 0</p><p>Objects obsolescence errors: 0</p><p>Objects created: 0 (0 warnings)</p><p>Objects creation errors: 0</p><p>Objects updated: 0 (0 warnings)</p><p>Objects update errors: 0</p><p>Objects reconciled (updated): 0 (0 warnings)</p><p>Objects reconciled (unchanged): 0 (0 warnings)</p><p>Objects reconciliation errors: 0</p><p>Replica disappeared, no action taken: 0</p>
TXT;

		return [
				'login failed' => [
					'sOutput' => 'eeeee<input type="hidden" name="loginop" value="login" ffff',
					'iExpectedErrorCount' => 1,
					'sErrorMessage' => "Failed to login to iTop. Invalid (or insufficent) credentials.\n",
				],
				'weird output' => [
					'sOutput' => $sFailedNoMatch,
					'iExpectedErrorCount' => 1,
					'sErrorMessage' => "NOMATCH",
				],
				'synchro error count parsing' => [
					'sOutput' => $sFailedOutput,
					'iExpectedErrorCount' => 5,
					'sErrorMessage' => "Objects deleted: 0</p><p>Objects deletion errors: 1</p><p>Objects obsoleted: 0</p><p>Objects obsolescence errors: 2</p><p>Objects created: 0 (0 warnings)</p><p>Objects creation errors: 3</p><p>Objects updated: 0 (0 warnings)</p><p>Objects update errors: 4</p><p>Objects reconciled (updated): 0 (0 warnings)</p><p>Objects reconciled (unchanged): 0 (0 warnings)</p><p>Objects reconciliation errors: 5\n",
				],
				'records have been untouched' => [
					'sOutput' => $sFailedOutputWithNoErrorCount,
					'iExpectedErrorCount' => 1,
					'sErrorMessage' => "All records have been untouched for some time (all of the objects could be deleted). Please check that the process that writes into the synchronization table is still running. Operation cancelled\n",
				],
				'synchro ok' => [
					'sOutput' => $sMsgOK,
					'iExpectedErrorCount' => 0,
					'sErrorMessage' => "",
				],
			];
	}

	/**
	 * @dataProvider ParseSynchroExecOutput
	 */
	public function testParseSynchroExecOutput($sOutput, $iExpectedErrorCount, $sErrorMessage=null){
		$oCollector = new \FakeCollector();
		$this->assertEquals($iExpectedErrorCount, $oCollector->ParseSynchroExecOutput($sOutput), $sOutput);
		$this->assertEquals($sErrorMessage, $oCollector->GetErrorMessage(), $sOutput);
	}

}
