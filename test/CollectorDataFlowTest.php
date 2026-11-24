<?php

namespace UnitTestFiles\Test;

use IgnoredRowException;
use InvalidConfigException;
use ScriptableCollector;
use ScriptableCollectorOne;
use UnitTestFiles\Test\Support\BaseTestCase;
use UnitTestFiles\Test\Support\FakeItop;
use Utils;

require_once __DIR__.'/Support/BaseTestCase.php';
require_once __DIR__.'/Support/ScriptableCollector.php';

/**
 * The life of a collector's data: SDS definition checks, creation and update of the data source, CSV files written
 * by Collect() (header, chunks, nullified values, unknown columns), reprocessing before synchro, upload and
 * synchronization by Synchronize().
 */
class CollectorDataFlowTest extends BaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		ScriptableCollector::Reset();
	}

	/**
	 * @param array<string, mixed> $aScript See ScriptableCollector
	 * @param array<string, mixed> $aDefinitionChanges See WriteSynchroDataSourceDefinition()
	 */
	private function CreateCollector(array $aScript = [], array $aDefinitionChanges = []): ScriptableCollectorOne
	{
		ScriptableCollector::$aScripts[ScriptableCollectorOne::class] = $aScript;
		$this->WriteSynchroDataSourceDefinition(ScriptableCollectorOne::class, $aDefinitionChanges);
		$oCollector = new ScriptableCollectorOne();
		$oCollector->Init();

		return $oCollector;
	}

	private function GetDataFile(string $sName): string
	{
		$this->assertFileExists($this->GetDataDir().$sName);

		return file_get_contents($this->GetDataDir().$sName);
	}

	/////////////////////////////////////////////////////////////////////////
	// Collect

	public function testRowsAreWrittenAsCsvWithTheKeysOfTheFirstRowAsHeader(): void
	{
		$oCollector = $this->CreateCollector(['rows' => [
			['primary_key' => '1', 'name' => 'Org A', 'code' => 'A;1'],
			['primary_key' => '2', 'name' => 'Org "B"', 'code' => null],
			['primary_key' => '3', 'name' => 'C', 'code' => 'back\\slash'],
		]]);

		$this->assertTrue($oCollector->Collect());

		$this->assertSame(
			"primary_key;name;code\n"
			."1;\"Org A\";\"A;1\"\n"
			."2;\"Org \"\"B\"\"\";\n"
			."3;C;\"back\\slash\"\n",
			$this->GetDataFile('ScriptableCollectorOne-1.csv')
		);
	}

	public function testNullOfANullifiedAttributeIsWrittenAsTheNullMarker(): void
	{
		$this->SetConfigurationOverrides(['ScriptableCollectorOne' => ['nullified_attributes' => ['code']]]);
		$oCollector = $this->CreateCollector(['rows' => [
			['primary_key' => '1', 'name' => 'A', 'code' => null],
			['primary_key' => '2', 'name' => 'B', 'code' => ''],
		]]);

		$this->assertTrue($oCollector->Collect());

		$this->assertSame("primary_key;name;code\n1;A;<NULL>\n2;B;\n", $this->GetDataFile('ScriptableCollectorOne-1.csv'));
	}

	public function testLegacyLowercaseNullifiedAttributesSettingIsRead(): void
	{
		$this->SetConfigurationOverrides(['scriptablecollectorone_nullified_attributes' => ['code']]);
		$oCollector = $this->CreateCollector();

		$this->assertTrue($oCollector->AttributeIsNullified('code'));
		$this->assertFalse($oCollector->AttributeIsNullified('name'));
	}

	public function testAMaxChunkSizeSplitsTheDataIntoFilesThatAllHaveTheHeader(): void
	{
		$aRows = [];
		for ($i = 1; $i <= 5; $i++) {
			$aRows[] = ['primary_key' => (string)$i, 'name' => "N$i", 'code' => "C$i"];
		}
		$oCollector = $this->CreateCollector(['rows' => $aRows]);

		$this->assertTrue($oCollector->Collect(2));

		$this->assertSame("primary_key;name;code\n1;N1;C1\n2;N2;C2\n", $this->GetDataFile('ScriptableCollectorOne-1.csv'));
		$this->assertSame("primary_key;name;code\n3;N3;C3\n4;N4;C4\n", $this->GetDataFile('ScriptableCollectorOne-2.csv'));
		$this->assertSame("primary_key;name;code\n5;N5;C5\n", $this->GetDataFile('ScriptableCollectorOne-3.csv'));
	}

	public function testCollectRemovesTheFilesOfThePreviousRun(): void
	{
		file_put_contents($this->GetDataDir().'ScriptableCollectorOne-7.csv', 'old');
		file_put_contents($this->GetDataDir().'ScriptableCollectorOne.raw-7.csv', 'old');
		$oCollector = $this->CreateCollector(['rows' => []]);

		$this->assertTrue($oCollector->Collect());

		$this->assertSame([], glob($this->GetDataDir().'ScriptableCollectorOne*'), 'No row: no file at all');
	}

	public function testColumnsUnknownToTheDefinitionAreDroppedWithAWarningUnlessOptional(): void
	{
		$oCollector = $this->CreateCollector(['optional' => ['optional_col'], 'rows' => [
			['primary_key' => '1', 'name' => 'A', 'unknown_col' => 'x', 'optional_col' => 'y', 'code' => 'C'],
		]]);

		$this->assertTrue($oCollector->Collect());

		$this->assertSame("primary_key;name;code\n1;A;C\n", $this->GetDataFile('ScriptableCollectorOne-1.csv'));
		$this->assertSame(["Invalid column 'unknown_col', will be ignored."], $this->oLogger->GetMessages(LOG_WARNING));
	}

	public function testImportColumnsDeclareTheColumnsOfAMultiColumnAttribute(): void
	{
		$oCollector = $this->CreateCollector(['rows' => [
			['primary_key' => '1', 'name' => 'A', 'file_data' => 'ZGF0YQ==', 'file_filename' => 'a.txt', 'file' => 'kept', 'other' => 'dropped'],
		]], ['attribute_list' => [
			self::SynchroAttribute('name', true),
			self::SynchroAttribute('file', false, true, ['import_columns' => 'file_data,file_filename']),
		]]);

		$this->assertTrue($oCollector->Collect());

		// HeaderIsAllowed() falls back to the attcodes: a column named after the attribute itself is kept as well
		$this->assertSame("primary_key;name;file_data;file_filename;file\n1;A;ZGF0YQ==;a.txt;kept\n", $this->GetDataFile('ScriptableCollectorOne-1.csv'));
	}

	public function testRowsGoToRawFilesWhenTheyMustBeProcessedBeforeSynchro(): void
	{
		$oCollector = $this->CreateCollector(['must_process' => true, 'rows' => [['primary_key' => '1', 'name' => 'A', 'code' => 'C']]]);

		$this->assertTrue($oCollector->Collect());

		$this->assertSame("primary_key;name;code\n1;A;C\n", $this->GetDataFile('ScriptableCollectorOne.raw-1.csv'));
		$this->assertFileDoesNotExist($this->GetDataDir().'ScriptableCollectorOne-1.csv');
	}

	public function testAnExceptionDuringCollectIsLoggedAndReturnsFalse(): void
	{
		$oCollector = $this->CreateCollector(['rows' => [['primary_key' => '1', 'name' => 'A', 'code' => 'C']]]);
		$this->SetConfigurationOverrides(['data_path' => '/proc/no-such-dir/']);

		$this->assertFalse($oCollector->Collect());
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, '/^ScriptableCollectorOne::Collect\(\) got an exception: /'));
	}

	/////////////////////////////////////////////////////////////////////////
	// Synchro data source definition

	public function testUseAttributesPolicyRequiresAReconciliationKey(): void
	{
		$this->StartFakeItop();
		$oCollector = $this->CreateCollector([], ['attribute_list' => [self::SynchroAttribute('name'), self::SynchroAttribute('code')]]);

		$this->expectException(InvalidConfigException::class);
		$this->expectExceptionMessage("Missing reconciliation key for data source 'ScriptableCollectorOne test source'");

		$oCollector->InitSynchroDataSource([]);
	}

	public function testDatabaseTableNameMustBeAValidIdentifier(): void
	{
		$this->StartFakeItop();
		$oCollector = $this->CreateCollector([], ['database_table_name' => 'synchro-data']);

		$this->expectException(InvalidConfigException::class);
		$this->expectExceptionMessage("invalid characters in database_table_name, current value is 'synchro-data'");

		$oCollector->InitSynchroDataSource([]);
	}

	public function testPlaceholdersAndVersionAreReplacedInTheDefinition(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateCollector([], ['name' => '$prefix$Source', 'description' => 'Version $version$']);

		$this->assertTrue($oCollector->InitSynchroDataSource(['$prefix$' => 'My ', '$synchro_user$' => '1', '$contact_to_notify$' => '0']));

		$aSource = $oItop->GetSynchroDataSource('My Source');
		$this->assertNotNull($aSource);
		$this->assertSame('Version 1.0.0', $aSource['description'], 'No module file in collectors/: version 1.0.0');
		$this->assertSame('1', $aSource['user_id']);
	}

	public function testDataSourceIsCreatedThenItsAttributesAlignedOnTheDefinition(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateCollector([], ['attribute_list' => [
			self::SynchroAttribute('name', true),
			self::SynchroAttribute('code', false, false),
			self::SynchroAttribute('parent_id', false, true, ['finalclass' => 'SynchroAttExtKey', 'reconciliation_attcode' => 'name']),
		]]);

		$this->assertTrue($oCollector->InitSynchroDataSource(['$synchro_user$' => '1', '$contact_to_notify$' => '0']));

		$this->assertCount(1, $oItop->GetRestOperations('core/create', 'SynchroDataSource'));
		$aAttributes = $oItop->GetObjects('SynchroAttribute', ['sync_source_id' => $oCollector->GetSourceId()]);
		$aByCode = array_column($aAttributes, null, 'attcode');
		$this->assertSame('0', $aByCode['code']['update']);
		$this->assertSame('name', $aByCode['parent_id']['reconciliation_attcode']);
		$this->assertSame('1', $aByCode['status']['update'], 'Attributes absent from the definition keep the defaults of iTop');
		// One update per attribute of the definition: iTop's output lacks the sub-class fields of the attributes
		$this->assertCount(3, $oItop->GetRestOperations('core/update'));
	}

	public function testOptionalAttributesUnknownToItopAreSkipped(): void
	{
		$this->StartFakeItop();
		$oCollector = $this->CreateCollector(['optional' => ['not_in_itop']], ['attribute_list' => [
			self::SynchroAttribute('name', true),
			self::SynchroAttribute('not_in_itop'),
		]]);

		$this->assertTrue($oCollector->InitSynchroDataSource(['$synchro_user$' => '1', '$contact_to_notify$' => '0']));

		$this->assertTrue($this->oLogger->HasMessage(LOG_INFO, "/^Skipping optional \(and not-present\) attribute 'not_in_itop'\.$/"));
		$this->assertSame(['not_in_itop'], $this->GetNonPublicProperty($oCollector, 'aSkippedAttributes'));
	}

	public function testSeveralDataSourcesWithTheSameNameIsAnError(): void
	{
		$oItop = $this->StartFakeItop();
		$oItop->AddObject('SynchroDataSource', ['name' => 'ScriptableCollectorOne test source']);
		$oItop->AddObject('SynchroDataSource', ['name' => 'ScriptableCollectorOne test source']);
		$oCollector = $this->CreateCollector();

		$this->assertFalse($oCollector->InitSynchroDataSource([]));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/^There are 2 Synchro Data Sources named 'ScriptableCollectorOne test source' in iTop\. Cannot continue\.$/"));
	}

	public function testRestErrorWhileLookingForTheDataSourceIsLogged(): void
	{
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['itop_password' => 'wrong']);
		$oCollector = $this->CreateCollector();

		$this->assertFalse($oCollector->InitSynchroDataSource([]));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, '/^Error: Invalid login \(1\)$/'));
	}

	public function testUnreachableItopIsLogged(): void
	{
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['itop_url' => 'http://unreachable.test']);
		$oCollector = $this->CreateCollector();

		$this->assertFalse($oCollector->InitSynchroDataSource([]));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, '/^Problem opening URL: http:\/\/unreachable\.test\/webservices\/rest\.php/'));
	}

	/////////////////////////////////////////////////////////////////////////
	// Synchronize

	/**
	 * @param array<string, mixed> $aScript
	 */
	private function CreateConfiguredCollector(array $aScript): ScriptableCollectorOne
	{
		$oCollector = $this->CreateCollector($aScript);
		$this->assertTrue($oCollector->InitSynchroDataSource(['$synchro_user$' => '1', '$contact_to_notify$' => '0']));

		return $oCollector;
	}

	public function testEachFileIsUploadedThenTheDataSourceSynchronizedOnce(): void
	{
		$oItop = $this->StartFakeItop();
		$this->SetConfigurationOverrides(['date_format' => 'd/m/Y']);
		$oCollector = $this->CreateConfiguredCollector(['rows' => [
			['primary_key' => '1', 'name' => 'A', 'code' => 'C1'],
			['primary_key' => '2', 'name' => 'B', 'code' => 'C2'],
			['primary_key' => '3', 'name' => 'C', 'code' => 'C3'],
		]]);
		$this->assertTrue($oCollector->Collect(2));

		$this->assertTrue($oCollector->Synchronize(50));

		$aImports = $oItop->GetRequests('synchro_import');
		$this->assertCount(2, $aImports);
		$this->assertSame(FakeItop::URL.'/synchro/synchro_import.php?login_mode=form', $aImports[0]['url']);
		$aData = $aImports[0]['data'];
		$this->assertSame("primary_key;name;code\n1;A;C1\n2;B;C2\n", $aData['csvdata']);
		unset($aData['csvdata']);
		$this->assertSame([
			'auth_user' => FakeItop::LOGIN,
			'auth_pwd' => FakeItop::PASSWORD,
			'separator' => ';',
			'data_source_id' => $oCollector->GetSourceId(),
			'synchronize' => '0',
			'no_stop_on_import_error' => 1,
			'output' => 'retcode',
			'charset' => 'UTF-8',
			'date_format' => 'd/m/Y',
		], $aData);

		$aExecs = $oItop->GetRequests('synchro_exec');
		$this->assertCount(1, $aExecs);
		$this->assertSame(['auth_user' => FakeItop::LOGIN, 'auth_pwd' => FakeItop::PASSWORD, 'data_sources' => $oCollector->GetSourceId(), 'max_chunk_size' => 50], $aExecs[0]['data']);
		$this->assertCount(3, $oItop->GetObjects('Organization'));
	}

	public function testTokenAuthenticationIsUsedForTheSynchroPages(): void
	{
		$oItop = $this->StartFakeItop();
		$oItop->SetCredentials(FakeItop::LOGIN, FakeItop::PASSWORD, 'my-token');
		$this->SetConfigurationOverrides(['itop_token' => 'my-token']);
		$oCollector = $this->CreateConfiguredCollector(['rows' => [['primary_key' => '1', 'name' => 'A', 'code' => 'C']]]);
		$this->assertTrue($oCollector->Collect());

		$this->assertTrue($oCollector->Synchronize());

		$aExec = $oItop->GetRequests('synchro_exec')[0];
		$this->assertSame(FakeItop::URL.'/synchro/synchro_exec.php?login_mode=token', $aExec['url']);
		$this->assertSame('my-token', $aExec['data']['auth_token']);
		$this->assertArrayNotHasKey('auth_user', $aExec['data']);
	}

	public function testDetailedImportOutputIsRequestedOnlyAtConsoleLogLevel7(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateConfiguredCollector(['rows' => [['primary_key' => '1', 'name' => 'A', 'code' => 'C']]]);
		$this->assertTrue($oCollector->Collect());
		Utils::$iConsoleLogLevel = 7;

		$this->assertTrue($oCollector->Synchronize());

		$this->assertSame('details', $oItop->GetRequests('synchro_import')[0]['data']['output']);
	}

	public function testImportErrorsStopTheUploadBeforeTheSynchronization(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateConfiguredCollector(['rows' => [['primary_key' => '1', 'name' => 'A', 'code' => 'C'], ['primary_key' => '2', 'name' => 'B', 'code' => 'D']]]);
		$this->assertTrue($oCollector->Collect(1));
		$oItop->ForceImportErrors(2);

		$this->assertFalse($oCollector->Synchronize());

		$this->assertCount(1, $oItop->GetRequests('synchro_import'), 'The second file is not uploaded');
		$this->assertCount(0, $oItop->GetRequests('synchro_exec'));
		$this->assertTrue($this->oLogger->HasMessage(LOG_ERR, "/^Failed to import the data from '.*ScriptableCollectorOne-1\.csv' into iTop\. 2 line\(s\) had errors\.$/"));
	}

	public function testRawFilesAreProcessedLineByLineBeforeTheUpload(): void
	{
		$oItop = $this->StartFakeItop();
		$aSeenLines = [];
		$oCollector = $this->CreateConfiguredCollector([
			'must_process' => true,
			'rows' => [
				['primary_key' => '1', 'name' => 'Keep', 'code' => 'c1'],
				['primary_key' => '2', 'name' => 'Ignore', 'code' => 'c2'],
			],
			'process_line' => function (array &$aLineData, int $iLineIndex) use (&$aSeenLines) {
				$aSeenLines[$iLineIndex] = $aLineData;
				if ($aLineData[1] === 'Ignore') {
					throw new IgnoredRowException('not wanted');
				}
				if ($iLineIndex > 0) {
					$aLineData[2] = strtoupper($aLineData[2]);
				}
			},
		]);
		$this->assertTrue($oCollector->Collect());

		$this->assertTrue($oCollector->Synchronize());

		$this->assertSame(['primary_key', 'name', 'code'], $aSeenLines[0], 'The header line goes through ProcessLineBeforeSynchro() too, as line 0');
		$this->assertSame("primary_key;name;code\n1;Keep;C1\n", $this->GetDataFile('ScriptableCollectorOne-1.csv'));
		$this->assertSame("primary_key;name;code\n1;Keep;C1\n", $oItop->GetRequests('synchro_import')[0]['data']['csvdata']);
		$this->assertTrue($this->oLogger->HasMessage(LOG_DEBUG, '/^Ignoring the line 2\. Reason: not wanted$/'));
	}

	public function testSynchronizationErrorsAreCountedAndKeptAsErrorMessage(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateConfiguredCollector(['rows' => [['primary_key' => '1', 'name' => '', 'code' => 'C']]]);
		$this->assertTrue($oCollector->Collect());

		$this->assertFalse($oCollector->Synchronize(), 'Organization.name is mandatory: the creation fails');

		$this->assertSame("Objects creation errors: 1\n", $oCollector->GetErrorMessage());
	}

	public function testSynchronizeWithoutDataFileOnlyRunsTheSynchronization(): void
	{
		$oItop = $this->StartFakeItop();
		$oCollector = $this->CreateConfiguredCollector([]);

		$this->assertTrue($oCollector->Synchronize());

		$this->assertCount(0, $oItop->GetRequests('synchro_import'));
		$this->assertCount(1, $oItop->GetRequests('synchro_exec'));
	}
}
