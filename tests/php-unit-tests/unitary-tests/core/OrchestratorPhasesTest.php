<?php

namespace UnitTestFiles\Test\Unitary\Core;

use Exception;
use Orchestrator;
use ScriptableCollector;
use ScriptableCollectorOne;
use ScriptableCollectorThree;
use ScriptableCollectorTwo;
use stdClass;
use UnitTestFiles\Test\BaseTestCase;

require_once dirname(__DIR__, 2).'/src/BaseTestCase/BaseTestCase.php';
require_once dirname(__DIR__, 2).'/src/Collector/ScriptableCollector.php';

abstract class AbstractScriptableCollector extends ScriptableCollector
{
}

/**
 * How Orchestrator registers the collectors and runs the three phases of exec.php: configure
 * (InitSynchroDataSources), collect and synchronize.
 */
class OrchestratorPhasesTest extends BaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		ScriptableCollector::Reset();
	}

	/**
	 * @param array<int, array{0: float, 1: string, 2?: array}> $aCollectors [rank, class, script]
	 *
	 * @return \Collector[] What exec.php gets from ListCollectors()
	 */
	private function RegisterCollectors(array $aCollectors): array
	{
		foreach ($aCollectors as $aCollector) {
			Orchestrator::AddCollector($aCollector[0], $aCollector[1]);
			ScriptableCollector::$aScripts[$aCollector[1]] = ($aCollector[2] ?? []) + ['skip_init' => true];
		}
		$aInstances = (new Orchestrator())->ListCollectors();
		ScriptableCollector::$aCalls = [];

		return $aInstances;
	}

	public function testCollectorsAreInstantiatedAndInitializedInRankOrder(): void
	{
		Orchestrator::AddCollector(3, ScriptableCollectorTwo::class);
		Orchestrator::AddCollector(1, ScriptableCollectorOne::class);
		Orchestrator::AddCollector(2.5, ScriptableCollectorThree::class);
		foreach ([ScriptableCollectorOne::class, ScriptableCollectorTwo::class, ScriptableCollectorThree::class] as $sClass) {
			ScriptableCollector::$aScripts[$sClass] = ['skip_init' => true];
		}

		$aCollectors = (new Orchestrator())->ListCollectors();

		$this->assertSame([ScriptableCollectorOne::class, ScriptableCollectorThree::class, ScriptableCollectorTwo::class], array_map('get_class', $aCollectors));
		$this->assertSame(['ScriptableCollectorOne::Init', 'ScriptableCollectorThree::Init', 'ScriptableCollectorTwo::Init'], ScriptableCollector::GetCalls('Init'));
	}

	public function testOnlyCollectorClassesCanBeRegistered(): void
	{
		$this->expectExceptionMessage('Cannot register a collector class (stdClass) which is not derived from Collector.');

		Orchestrator::AddCollector(1, stdClass::class);
	}

	public function testAbstractCollectorClassesCannotBeRegistered(): void
	{
		$this->expectExceptionMessage('Cannot register an abstract class');

		Orchestrator::AddCollector(1, AbstractScriptableCollector::class);
	}

	public function testDeclaredPhpRequirementIsOnlyPhp7(): void
	{
		// Known quirk: the code needs PHP 8, but only PHP 7.0 is declared (by Orchestrator itself)
		$this->assertSame(['PHP' => '7.0', 'simplexml' => '7.0', 'dom' => '1'], Orchestrator::$aMinVersions);
		$this->assertTrue(Orchestrator::CheckRequirements());
	}

	public function testStricterRequirementWins(): void
	{
		Orchestrator::AddRequirement('7.4');
		Orchestrator::AddRequirement('7.1');

		$this->assertSame('7.4', Orchestrator::$aMinVersions['PHP']);
	}

	public function testUnmetRequirementsAreReported(): void
	{
		Orchestrator::AddRequirement('99.0');
		Orchestrator::AddRequirement('1.0', 'no_such_extension');

		$this->assertFalse(Orchestrator::CheckRequirements());
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, '/^The required PHP version to run this application is 99\.0\./'));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/^The missing extension 'no_such_extension' \(version >= 1\.0\) is required/"));
	}

	public function testConfigurePhaseResolvesTheJsonPlaceholders(): void
	{
		$oItop = $this->StartFakeItop();
		$iContactId = $oItop->AddObject('Person', ['name' => 'Ops', 'email' => 'ops@example.com']);
		$this->SetConfigurationOverrides([
			'contact_to_notify' => 'ops@example.com',
			'synchro_user' => '',
			'json_placeholders' => ['prefix' => 'Test ', 'full_load_interval' => '86400'],
		]);
		$aCollectors = $this->RegisterCollectors([[1, ScriptableCollectorOne::class, ['init_sds_result' => true]]]);

		$this->assertTrue((new Orchestrator())->InitSynchroDataSources($aCollectors));

		$aPlaceholders = ScriptableCollector::$aCalls[0][2][0];
		$this->assertSame([
			'$contact_to_notify$' => $iContactId,
			'$synchro_user$' => 1, // no synchro_user: the user of itop_login (FakeItop::LOGIN, id 1)
			'$prefix$' => 'Test ',
			'$full_load_interval$' => '86400',
		], $aPlaceholders);
	}

	public function testSynchroUserTakesPrecedenceOverTheLogin(): void
	{
		$oItop = $this->StartFakeItop();
		$iUserId = $oItop->AddObject('UserLocal', ['login' => 'synchro', 'email' => '', 'friendlyname' => 'Synchro']);
		$this->SetConfigurationOverrides(['synchro_user' => 'synchro']);
		$aCollectors = $this->RegisterCollectors([[1, ScriptableCollectorOne::class, ['init_sds_result' => true]]]);

		(new Orchestrator())->InitSynchroDataSources($aCollectors);

		$this->assertSame($iUserId, ScriptableCollector::$aCalls[0][2][0]['$synchro_user$']);
	}

	public function testUnknownContactToNotifyGivesZeroWithAWarning(): void
	{
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['contact_to_notify' => 'nobody@example.com']);
		$aCollectors = $this->RegisterCollectors([[1, ScriptableCollectorOne::class, ['init_sds_result' => true]]]);

		$this->assertTrue((new Orchestrator())->InitSynchroDataSources($aCollectors));

		$this->assertSame(0, ScriptableCollector::$aCalls[0][2][0]['$contact_to_notify$']);
		$this->assertTrue($this->oLogger->HasMessage(LOG_WARNING, '/^Contact to notify \(nobody@example\.com\) not found in iTop/'));
	}

	public function testUnknownSynchroUserRaisesAPhpWarning(): void
	{
		// Known quirk: unlike the contact, an unknown synchro_user is not checked (foreach on a null "objects")
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['synchro_user' => 'ghost']);
		$aCollectors = $this->RegisterCollectors([[1, ScriptableCollectorOne::class, ['init_sds_result' => true]]]);

		$aWarnings = [];
		set_error_handler(function ($iErrNo, $sMessage) use (&$aWarnings) {
			$aWarnings[] = $sMessage;

			return true;
		}, E_WARNING);
		try {
			$bResult = (new Orchestrator())->InitSynchroDataSources($aCollectors);
		} finally {
			restore_error_handler();
		}

		$this->assertTrue($bResult);
		$this->assertSame(0, ScriptableCollector::$aCalls[0][2][0]['$synchro_user$']);
		$this->assertCount(1, $aWarnings);
		$this->assertStringContainsString('foreach() argument must be of type array|object', $aWarnings[0]);
	}

	public function testRestErrorsDuringPlaceholderResolutionAreLogged(): void
	{
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['contact_to_notify' => 'ops@example.com', 'itop_password' => 'wrong']);
		$aCollectors = $this->RegisterCollectors([[1, ScriptableCollectorOne::class, ['init_sds_result' => true]]]);

		$this->assertTrue((new Orchestrator())->InitSynchroDataSources($aCollectors), 'The configure phase goes on');

		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/^Unable to find the contact with email = 'ops@example\.com'\./"));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/^Unable to use synchro user with login = 'collector'\. Error: Invalid login$/"));
		$this->assertSame(['$contact_to_notify$' => 0, '$synchro_user$' => 0], ScriptableCollector::$aCalls[0][2][0]);
	}

	public function testConfigurePhaseStopsAtTheFirstFailingCollector(): void
	{
		$this->StartFakeItop();
		$aCollectors = $this->RegisterCollectors([
			[1, ScriptableCollectorOne::class, ['init_sds_result' => false]],
			[2, ScriptableCollectorTwo::class, ['init_sds_result' => true]],
		]);

		$this->assertFalse((new Orchestrator())->InitSynchroDataSources($aCollectors));
		$this->assertSame(['ScriptableCollectorOne::InitSynchroDataSource'], ScriptableCollector::GetCalls());
	}

	public function testCollectPhaseStopsAtTheFirstFailingCollector(): void
	{
		$aCollectors = $this->RegisterCollectors([
			[1, ScriptableCollectorOne::class, ['collect_result' => true]],
			[2, ScriptableCollectorTwo::class, ['collect_result' => false]],
			[3, ScriptableCollectorThree::class, ['collect_result' => true]],
		]);

		$this->assertFalse((new Orchestrator())->Collect($aCollectors, 10, true));

		$this->assertSame(['ScriptableCollectorOne::Collect', 'ScriptableCollectorTwo::Collect'], ScriptableCollector::GetCalls());
		// Known quirk: the second argument ($bCollectOnly) is passed but Collector::Collect() ignores it
		$this->assertSame([10, true], ScriptableCollector::$aCalls[0][2]);
	}

	public function testSynchronizePhaseGoesOnAfterAFailureAndReportsSuccessByDefault(): void
	{
		$aCollectors = $this->RegisterCollectors([
			[1, ScriptableCollectorOne::class, ['synchronize_result' => false]],
			[2, ScriptableCollectorTwo::class, ['synchronize_result' => true]],
		]);

		$this->assertTrue((new Orchestrator())->Synchronize($aCollectors), 'stop_on_synchro_error=no: the failure does not change the exit code');

		$this->assertSame(['ScriptableCollectorOne::Synchronize', 'ScriptableCollectorTwo::Synchronize'], ScriptableCollector::GetCalls());
		// Known quirk: no chunk size is passed, so max_chunk_size never reaches synchro_exec.php
		$this->assertSame([], ScriptableCollector::$aCalls[0][2]);
	}

	public function testSynchronizePhaseStopsOnErrorWhenConfigured(): void
	{
		$this->SetConfigurationOverrides(['stop_on_synchro_error' => 'yes']);
		$aCollectors = $this->RegisterCollectors([
			[1, ScriptableCollectorOne::class, ['synchronize_result' => false]],
			[2, ScriptableCollectorTwo::class, ['synchronize_result' => true]],
		]);

		$this->assertFalse((new Orchestrator())->Synchronize($aCollectors));
		$this->assertSame(['ScriptableCollectorOne::Synchronize'], ScriptableCollector::GetCalls());
	}

	public function testUnexpectedStopOnErrorValueIsWarnedAndTreatedAsNo(): void
	{
		$this->SetConfigurationOverrides(['stop_on_synchro_error' => 'true']);
		$aCollectors = $this->RegisterCollectors([
			[1, ScriptableCollectorOne::class, ['synchronize_result' => false]],
			[2, ScriptableCollectorTwo::class, ['synchronize_result' => true]],
		]);

		$this->assertTrue((new Orchestrator())->Synchronize($aCollectors));
		$this->assertCount(2, ScriptableCollector::GetCalls());
		$this->assertTrue($this->oLogger->HasMessage(LOG_WARNING, "/^Unexpected value 'true' for the parameter 'stop_on_synchro_error'/"));
	}
}
