<?php

namespace UnitTestFiles\Test\Support;

use ContractTestCollector;
use RestClient;
use Utils;

require_once __DIR__.'/BaseTestCase.php';
require_once __DIR__.'/ContractTestCollector.php';

/**
 * Scenarios of the dialog between the framework and iTop: REST calls, creation and update of the data source, CSV
 * upload and synchronization. They use the framework's own classes, as a collector does.
 *
 * They run twice:
 * - against FakeItop at every test run (test/FakeItopContractTest.php), which proves the fake behaves like iTop;
 * - against a real iTop in the integration suite (test/integration/RealItopContractTest.php).
 * When a scenario passes on a real iTop but not on the fake, fix the fake. Never weaken a scenario to suit the fake.
 *
 * Every object created in iTop carries a unique run id in its name and is deleted after the test.
 */
abstract class ItopContractTestCase extends BaseTestCase
{
	/** @var string Unique per test, so that runs on a shared iTop do not collide */
	protected $sRunId;

	/**
	 * Point the configuration to the iTop under test, or skip the test.
	 */
	abstract protected function ConnectToItop(): void;

	protected function setUp(): void
	{
		parent::setUp();
		$this->sRunId = date('YmdHis').'-'.bin2hex(random_bytes(3));
		ContractTestCollector::$aRows = [];
		$this->ConnectToItop();
	}

	protected function tearDown(): void
	{
		try {
			// Delete what the test created, while the configuration still points to the iTop under test
			$this->CallRest(['operation' => 'core/delete', 'class' => 'Organization', 'key' => "SELECT Organization WHERE name LIKE '%{$this->sRunId}%'", 'comment' => 'Test cleanup']);
			$this->CallRest(['operation' => 'core/delete', 'class' => 'SynchroDataSource', 'key' => "SELECT SynchroDataSource WHERE name LIKE '%{$this->sRunId}%'", 'comment' => 'Test cleanup']);
		} finally {
			parent::tearDown();
		}
	}

	public function testRestApiVersionIsDetected(): void
	{
		$this->assertContains(RestClient::GetNewestKnownVersion(), ['1.0', '1.1', '1.2', '1.3', '1.4']);
	}

	public function testCollectorAccountCanBeRead(): void
	{
		$aResult = (new RestClient())->Get('User', ['login' => Utils::GetConfigurationValue('itop_login')]);

		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');
		$this->assertCount(1, $aResult['objects']);
		$aUser = reset($aResult['objects']);
		// Orchestrator::InitSynchroDataSources() reads these to resolve $synchro_user$
		$this->assertArrayHasKey('key', $aUser);
		$this->assertArrayHasKey('friendlyname', $aUser['fields']);
		$this->assertArrayHasKey('email', $aUser['fields']);
	}

	public function testWrongCredentialsAreRejected(): void
	{
		$this->SetConfigurationOverrides(['itop_password' => 'wrong-password-'.$this->sRunId, 'itop_token' => '']);

		$aResult = (new RestClient())->Get('User', ['login' => Utils::GetConfigurationValue('itop_login')]);

		$this->assertEquals(1, $aResult['code'], 'Expected RestResult::UNAUTHORIZED');
	}

	public function testDataSourceIsCreatedThenFoundUpToDate(): void
	{
		$oCollector = $this->InstallContractCollector();
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()), 'Creation of the data source');

		$aSource = $this->GetDataSourceFromItop();
		$this->assertSame($this->GetDataSourceName(), $aSource['fields']['name']);
		$this->assertSame('Organization', $aSource['fields']['scope_class']);
		$aCode = $this->GetSynchroAttributeFromItop($oCollector->GetSourceId(), 'code');
		$this->assertEquals(1, $aCode['update']);
		$this->assertEquals(0, $aCode['reconcile']);
		$aName = $this->GetSynchroAttributeFromItop($oCollector->GetSourceId(), 'name');
		$this->assertEquals(1, $aName['reconcile']);

		$this->oLogger->Clear();
		$oCollector = $this->InstallContractCollector();
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()), 'Second initialization');
		$this->assertTrue($this->oLogger->HasMessage(LOG_INFO, '/exists in iTop and is up to date/'), 'An unchanged definition must not be updated');
	}

	/**
	 * A definition exported from iTop holds the 'friendlyname' of each attribute, which
	 * RestClient::GetFullSynchroDataSource() removes from the iTop side before the comparison.
	 * Such a data source is found different, hence updated, at every run.
	 */
	public function testDefinitionWithAttributeFriendlynamesIsUpdatedAtEveryRun(): void
	{
		$this->assertTrue($this->InstallContractCollector(['with_friendlynames' => true])->InitSynchroDataSource($this->GetPlaceholders()));

		$this->oLogger->Clear();
		$this->assertTrue($this->InstallContractCollector(['with_friendlynames' => true])->InitSynchroDataSource($this->GetPlaceholders()));

		$this->assertTrue($this->oLogger->HasMessage(LOG_INFO, '/must be updated in iTop/'));
	}

	public function testChangedDefinitionUpdatesTheDataSource(): void
	{
		$this->assertTrue($this->InstallContractCollector()->InitSynchroDataSource($this->GetPlaceholders()));

		$oCollector = $this->InstallContractCollector(['description' => 'Changed description', 'code_update' => '0']);
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()));

		$this->assertTrue($this->oLogger->HasMessage(LOG_INFO, '/must be updated in iTop/'));
		$this->assertSame('Changed description', $this->GetDataSourceFromItop()['fields']['description']);
		$this->assertEquals(0, $this->GetSynchroAttributeFromItop($oCollector->GetSourceId(), 'code')['update']);
	}

	public function testAttributeUnknownToItopIsReportedAsInconsistentDataModel(): void
	{
		$this->assertTrue($this->InstallContractCollector()->InitSynchroDataSource($this->GetPlaceholders()));

		$oCollector = $this->InstallContractCollector(['extra_attribute' => 'no_such_attribute']);

		$this->assertFalse($oCollector->InitSynchroDataSource($this->GetPlaceholders()));
		$this->assertTrue(
			$this->oLogger->HasMessage(LOG_ERR, "/Inconsistent data model, the attribute 'no_such_attribute' does not exist in iTop/"),
			'Collector::UpdateSDSAttributes() recognizes the "No item found with criteria: sync_source_id" error of iTop'
		);
	}

	public function testCollectedDataIsSynchronized(): void
	{
		$sName = "Contract org {$this->sRunId}";

		$this->RunCollector([['primary_key' => 'org-1', 'name' => $sName, 'code' => 'C1']]);
		$this->assertSame('C1', $this->GetOrganizationFromItop($sName)['code'], 'Created');

		$this->RunCollector([['primary_key' => 'org-1', 'name' => $sName, 'code' => 'C2']]);
		$this->assertSame('C2', $this->GetOrganizationFromItop($sName)['code'], 'Updated');
	}

	public function testNullifiedValueLeavesTheFieldUntouchedWhileEmptyStringResetsIt(): void
	{
		$sName = "Contract org {$this->sRunId}";
		$this->SetConfigurationOverrides(['ContractTestCollector' => ['nullified_attributes' => ['code']]]);
		$this->RunCollector([['primary_key' => 'org-1', 'name' => $sName, 'code' => 'C1']]);

		$this->RunCollector([['primary_key' => 'org-1', 'name' => $sName, 'code' => null]]);
		$this->assertSame('C1', $this->GetOrganizationFromItop($sName)['code'], 'null is sent as <NULL>: iTop keeps the value');

		$this->RunCollector([['primary_key' => 'org-1', 'name' => $sName, 'code' => '']]);
		$this->assertSame('', $this->GetOrganizationFromItop($sName)['code'], 'An empty string resets the value');
	}

	public function testImportOfAnUnknownColumnFails(): void
	{
		$oCollector = $this->InstallContractCollector();
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()));
		// Collect() would drop the unknown column: write the file as a faulty collector could
		file_put_contents(Utils::GetDataFilePath('ContractTestCollector-1.csv'), "primary_key;name;no_such_column\norg-1;Contract org {$this->sRunId};x\n");

		$this->assertFalse($oCollector->Synchronize());
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, '/Failed to import the data from/'));
	}

	public function testSynchronizationErrorsAreReported(): void
	{
		$oCollector = $this->InstallContractCollector(['action_on_zero' => 'error']);
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()));
		ContractTestCollector::$aRows = [['primary_key' => 'org-1', 'name' => "Contract org {$this->sRunId}", 'code' => 'C1']];
		$this->assertTrue($oCollector->Collect());

		$this->assertFalse($oCollector->Synchronize(), 'No object matches and action_on_zero is "error"');
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/Synchronization of data source '.*' answered: Objects .* errors: [1-9]/"));
	}

	/////////////////////////////////////////////////////////////////////////

	protected function GetDataSourceName(): string
	{
		return "Collector base contract test {$this->sRunId}";
	}

	/**
	 * Write the SDS definition of ContractTestCollector (on class Organization) and return a new, initialized instance.
	 *
	 * @param array{description?: string, code_update?: string, extra_attribute?: string, action_on_zero?: string, with_friendlynames?: bool} $aChanges
	 */
	protected function InstallContractCollector(array $aChanges = []): ContractTestCollector
	{
		$aAttributes = [
			['attcode' => 'name', 'update' => '1', 'reconcile' => '1', 'update_policy' => 'master_locked', 'finalclass' => 'SynchroAttribute'],
			['attcode' => 'code', 'update' => $aChanges['code_update'] ?? '1', 'reconcile' => '0', 'update_policy' => 'master_locked', 'finalclass' => 'SynchroAttribute'],
		];
		if (isset($aChanges['extra_attribute'])) {
			$aAttributes[] = ['attcode' => $aChanges['extra_attribute'], 'update' => '1', 'reconcile' => '0', 'update_policy' => 'master_locked', 'finalclass' => 'SynchroAttribute'];
		}
		if ($aChanges['with_friendlynames'] ?? false) {
			foreach ($aAttributes as &$aAttribute) {
				$aAttribute['friendlyname'] = $aAttribute['attcode'];
			}
			unset($aAttribute);
		}

		$aDefinition = [
			'name' => $this->GetDataSourceName(),
			'description' => $aChanges['description'] ?? 'Created by the tests of itop-data-collector-base',
			'status' => 'production',
			'user_id' => '$synchro_user$',
			'notify_contact_id' => '$contact_to_notify$',
			'scope_class' => 'Organization',
			'database_table_name' => '',
			'scope_restriction' => '',
			'full_load_periodicity' => '0',
			'reconciliation_policy' => 'use_attributes',
			'action_on_zero' => $aChanges['action_on_zero'] ?? 'create',
			'action_on_one' => 'update',
			'action_on_multiple' => 'error',
			'delete_policy' => 'ignore',
			'delete_policy_update' => '',
			'delete_policy_retention' => '0',
			'attribute_list' => $aAttributes,
			'user_delete_policy' => 'nobody',
			'url_icon' => '',
			'url_application' => '',
		];
		$this->WriteCollectorFile('json/ContractTestCollector.json', json_encode($aDefinition, JSON_PRETTY_PRINT));

		$oCollector = new ContractTestCollector();
		$oCollector->Init();

		return $oCollector;
	}

	/**
	 * @return array<string, string> What Orchestrator::InitSynchroDataSources() would compute
	 */
	protected function GetPlaceholders(): array
	{
		$aResult = (new RestClient())->Get('User', ['login' => Utils::GetConfigurationValue('itop_login')]);
		$aUser = reset($aResult['objects']);

		return ['$synchro_user$' => (string)$aUser['key'], '$contact_to_notify$' => '0'];
	}

	/**
	 * One run of the collector, as exec.php does it in a new process: a new instance, then configure, collect and
	 * synchronize. (A Collector instance cannot collect twice.)
	 *
	 * @param array<int, array<string, ?string>> $aRows
	 */
	protected function RunCollector(array $aRows): void
	{
		$oCollector = $this->InstallContractCollector();
		$this->assertTrue($oCollector->InitSynchroDataSource($this->GetPlaceholders()), 'Configure');
		ContractTestCollector::$aRows = $aRows;
		$this->assertTrue($oCollector->Collect(), 'Collect');
		$this->assertTrue($oCollector->Synchronize(), 'Synchronize: '.implode(' | ', $this->oLogger->GetMessages(LOG_ERR)));
	}

	protected function GetDataSourceFromItop(): array
	{
		$aResult = (new RestClient())->Get('SynchroDataSource', ['name' => $this->GetDataSourceName()]);
		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');
		$this->assertCount(1, $aResult['objects'] ?? []);

		return reset($aResult['objects']);
	}

	protected function GetSynchroAttributeFromItop(int $iSourceId, string $sAttCode): array
	{
		$aResult = (new RestClient())->Get('SynchroAttribute', ['sync_source_id' => $iSourceId, 'attcode' => $sAttCode]);
		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');
		$this->assertCount(1, $aResult['objects'] ?? []);

		return reset($aResult['objects'])['fields'];
	}

	protected function GetOrganizationFromItop(string $sName): array
	{
		$aResult = (new RestClient())->Get('Organization', ['name' => $sName]);
		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');
		$this->assertCount(1, $aResult['objects'] ?? [], "Organization '$sName' in iTop");

		return reset($aResult['objects'])['fields'];
	}

	/**
	 * Run any REST operation through the framework's client (RestClient::ExecOperation()).
	 */
	protected function CallRest(array $aOperation): array
	{
		return $this->InvokeNonPublicMethod(RestClient::class, 'ExecOperation', [$aOperation, '1.0']);
	}
}
