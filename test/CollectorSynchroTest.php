<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;
use Utils;
use Collector;

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

}
