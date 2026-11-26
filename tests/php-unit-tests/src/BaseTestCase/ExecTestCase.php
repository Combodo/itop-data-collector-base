<?php

namespace UnitTestFiles\Test\Support;

use RestClient;
use Utils;

require_once __DIR__.'/BaseTestCase.php';

/**
 * End-to-end scenarios: exec.php runs in its own process, with a CSV collector installed in collectors/ (on class
 * Organization), against an iTop reached over HTTP.
 *
 * They run against FakeItop served by `php -S` (unitary-tests/FakeItopExecTest.php) and against a real iTop
 * (integration-tests/RealItopExecTest.php).
 */
abstract class ExecTestCase extends BaseTestCase
{
	/** @var string Unique per test, so that runs on a shared iTop do not collide */
	protected $sRunId;

	/** @var array{url: string, login: string, password: string, token: string, curl_options: array<string, string>}|null Null when ConnectToItop() skipped the test */
	protected $aItop = null;

	/**
	 * Start or reach the iTop under test, or skip the test.
	 *
	 * @return array{url: string, login: string, password: string, token: string, curl_options: array<string, string>}
	 */
	abstract protected function ConnectToItop(): array;

	protected function setUp(): void
	{
		parent::setUp();
		$this->sRunId = date('YmdHis').'-'.bin2hex(random_bytes(3));
		$this->aItop = $this->ConnectToItop();
		// The test itself reads iTop with the framework's REST client, over HTTP too
		$this->SetConfigurationOverrides([
			'itop_url' => $this->aItop['url'],
			'itop_login' => $this->aItop['login'],
			'itop_password' => $this->aItop['password'],
			'itop_token' => $this->aItop['token'],
			'itop_login_mode' => '',
		]);
		if (!empty($this->aItop['curl_options'])) {
			$this->SetConfigurationOverrides(['curl_options' => $this->aItop['curl_options']]);
		}
		$this->InstallCsvCollector();
	}

	protected function tearDown(): void
	{
		try {
			if ($this->aItop === null) {
				return;
			}
			$this->CallRest(['operation' => 'core/delete', 'class' => 'Organization', 'key' => "SELECT Organization WHERE name LIKE '%{$this->sRunId}%'", 'comment' => 'Test cleanup']);
			$this->CallRest(['operation' => 'core/delete', 'class' => 'SynchroDataSource', 'key' => "SELECT SynchroDataSource WHERE name LIKE '%{$this->sRunId}%'", 'comment' => 'Test cleanup']);
		} finally {
			parent::tearDown();
		}
	}

	public function testFullRunCreatesTheObjectsThenTheNextRunFindsTheDataSourceUpToDate(): void
	{
		$aRun = $this->RunExec();

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertSame(['A' => 'EA', 'B' => 'EB'], $this->GetOrganizationCodes());

		$this->WriteCsv([['1', 'A', 'EA2'], ['2', 'B', 'EB']]);
		$aRun = $this->RunExec();

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertStringContainsString("Ok, the Synchro Data Source '{$this->GetDataSourceName()}' exists in iTop and is up to date", $aRun['output']);
		$this->assertSame(['A' => 'EA2', 'B' => 'EB'], $this->GetOrganizationCodes());
	}

	public function testCollectOnlyWritesTheDataFilesWithoutContactingItop(): void
	{
		$aRun = $this->RunExec(['--collect_only']);

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertFileExists($this->GetDataDir().'ExecTestCsvCollector-1.csv');
		$this->assertNull($this->GetDataSourceFromItop());
	}

	public function testConfigureOnlyCreatesTheDataSourceOnly(): void
	{
		$aRun = $this->RunExec(['--configure_only']);

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertNotNull($this->GetDataSourceFromItop());
		$this->assertSame([], $this->GetOrganizationCodes());
		$this->assertFileDoesNotExist($this->GetDataDir().'ExecTestCsvCollector-1.csv');
	}

	public function testSynchroOnlyConfiguresThenUploadsTheFilesOfAPreviousCollect(): void
	{
		$this->assertSame(0, $this->RunExec(['--collect_only'])['exit_code']);
		$this->WriteCsv([['1', 'A', 'changed after the collect']]);

		$aRun = $this->RunExec(['--synchro_only']);

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertSame(['A' => 'EA', 'B' => 'EB'], $this->GetOrganizationCodes());
	}

	public function testDumpConfigOnlyPrintsTheFilesAndTheMergedConfiguration(): void
	{
		$aRun = $this->RunExec(['--dump_config_only']);

		$this->assertSame(0, $aRun['exit_code'], $aRun['output']);
		$this->assertStringContainsString("The following configuration files were loaded (in this order):\n\n\t1. ".APPROOT.'conf/params.distrib.xml', $aRun['output']);
		$this->assertStringContainsString("\t2. ".APPROOT.'collectors/params.distrib.xml', $aRun['output']);
		$this->assertStringContainsString('<itop_url>'.htmlspecialchars($this->aItop['url']).'</itop_url>', $aRun['output']);
	}

	public function testUnknownOptionPrintsTheUsageAndFails(): void
	{
		$aRun = $this->RunExec(['--no_such_option']);

		$this->assertSame(1, $aRun['exit_code']);
		$this->assertStringContainsString('Unknown parameter(s): --no_such_option', $aRun['output']);
		$this->assertStringContainsString("Usage:\nphp exec.php[--configure_only][--collect_only]", $aRun['output']);
	}

	public function testWrongCredentialsFailTheRun(): void
	{
		$aRun = $this->RunExec([], ['itop_password' => 'wrong-'.$this->sRunId, 'itop_token' => '']);

		$this->assertSame(1, $aRun['exit_code']);
		// RestClient::GetNewestKnownVersion() is called to build a debug message, even when debug is off
		$this->assertStringContainsString('Exception: Unable to detect an iTop API.', $aRun['output']);
	}

	public function testMissingMainFileIsLoggedButTheRunSucceeds(): void
	{
		unlink(APPROOT.'collectors/main.php');

		$aRun = $this->RunExec();

		$this->assertSame(0, $aRun['exit_code'], 'Known quirk: no collector at all is not a failure');
		$this->assertStringContainsString("The file '".APPROOT."collectors/main.php' is missing (or unreadable).", $aRun['output']);
	}

	/////////////////////////////////////////////////////////////////////////

	protected function GetDataSourceName(): string
	{
		return "Collector base exec test {$this->sRunId}";
	}

	/**
	 * collectors/: main.php registering ExecTestCsvCollector, its class, its SDS definition (named with the
	 * $run_id$ placeholder), its params.distrib.xml and the CSV file it reads.
	 */
	private function InstallCsvCollector(): void
	{
		$this->WriteCollectorFile('main.php', "<?php\nrequire_once APPROOT.'collectors/src/ExecTestCsvCollector.class.inc.php';\nOrchestrator::AddCollector(1, 'ExecTestCsvCollector');\n");
		$this->WriteCollectorFile('src/ExecTestCsvCollector.class.inc.php', "<?php\nclass ExecTestCsvCollector extends CSVCollector\n{\n}\n");
		$this->WriteSynchroDataSourceDefinition('ExecTestCsvCollector', ['name' => 'Collector base exec test $run_id$']);
		$this->WriteCollectorFile('params.distrib.xml', '<?xml version="1.0" encoding="UTF-8"?>
<parameters>
  <ExecTestCsvCollector>
    <csv_file>'.htmlspecialchars(APPROOT.'collectors/organizations.csv').'</csv_file>
  </ExecTestCsvCollector>
</parameters>
');
		$this->WriteCsv([['1', 'A', 'EA'], ['2', 'B', 'EB']]);
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2: string}> $aRows [primary_key, name suffix, code]
	 */
	protected function WriteCsv(array $aRows): void
	{
		$sCsv = "primary_key;name;code\n";
		foreach ($aRows as [$sKey, $sName, $sCode]) {
			$sCsv .= "$sKey;Exec org $sName {$this->sRunId};$sCode\n";
		}
		$this->WriteCollectorFile('organizations.csv', $sCsv);
	}

	/**
	 * Run exec.php in a new process, with a configuration file pointing to the iTop under test.
	 *
	 * @param string[] $aArguments
	 * @param array<string, mixed> $aConfigChanges Values of the configuration file
	 *
	 * @return array{exit_code: int, output: string} Output: stdout and stderr
	 */
	protected function RunExec(array $aArguments = [], array $aConfigChanges = []): array
	{
		$aConfig = array_merge([
			'itop_url' => $this->aItop['url'],
			'itop_login' => $this->aItop['login'],
			'itop_password' => $this->aItop['password'],
			'itop_token' => $this->aItop['token'],
			'data_path' => $this->GetDataDir(),
			'console_log_level' => '6',
			'json_placeholders' => ['run_id' => $this->sRunId],
		], $aConfigChanges);
		if (!empty($this->aItop['curl_options'])) {
			// An empty <curl_options/> would replace the default hash with '' and make exec.php crash
			$aConfig['curl_options'] = $this->aItop['curl_options'];
		}
		$sConfigFile = $this->GetTempDir().'/exec-config.xml';
		file_put_contents($sConfigFile, '<?xml version="1.0" encoding="UTF-8"?>'."\n<parameters>\n".self::ToXml($aConfig)."</parameters>\n");

		$aCommand = array_merge([PHP_BINARY, APPROOT.'exec.php', "--config_file=$sConfigFile"], $aArguments);
		$sOutputFile = $this->GetTempDir().'/exec-output.txt';
		$hProcess = proc_open($aCommand, [0 => ['pipe', 'r'], 1 => ['file', $sOutputFile, 'w'], 2 => ['file', $sOutputFile, 'a']], $aPipes, APPROOT);
		fclose($aPipes[0]);
		$iExitCode = proc_close($hProcess);

		return ['exit_code' => $iExitCode, 'output' => (string)file_get_contents($sOutputFile)];
	}

	private static function ToXml(array $aValues): string
	{
		$sXml = '';
		foreach ($aValues as $sTag => $value) {
			$sXml .= is_array($value) ? "<$sTag>".self::ToXml($value)."</$sTag>\n" : "<$sTag>".htmlspecialchars((string)$value)."</$sTag>\n";
		}

		return $sXml;
	}

	/**
	 * @return array<string, string> Name suffix => code of the organizations created by this test
	 */
	protected function GetOrganizationCodes(): array
	{
		$aResult = (new RestClient())->Get('Organization', "SELECT Organization WHERE name LIKE '%{$this->sRunId}%'", 'name,code');
		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');
		$aCodes = [];
		foreach ($aResult['objects'] ?? [] as $aObject) {
			$sSuffix = trim(str_replace(['Exec org ', $this->sRunId], '', $aObject['fields']['name']));
			$aCodes[$sSuffix] = $aObject['fields']['code'];
		}
		ksort($aCodes);

		return $aCodes;
	}

	protected function GetDataSourceFromItop(): ?array
	{
		$aResult = (new RestClient())->Get('SynchroDataSource', ['name' => $this->GetDataSourceName()]);
		$this->assertEquals(0, $aResult['code'], $aResult['message'] ?? '');

		return ($aResult['objects'] === null) ? null : reset($aResult['objects']);
	}

	protected function CallRest(array $aOperation): array
	{
		return $this->InvokeNonPublicMethod(RestClient::class, 'ExecOperation', [$aOperation, '1.0']);
	}
}
