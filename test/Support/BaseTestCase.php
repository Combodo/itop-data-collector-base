<?php

namespace UnitTestFiles\Test\Support;

use Parameters;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Utils;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/GlobalState.php';
require_once __DIR__.'/RecordingLogger.php';
require_once __DIR__.'/FakeHttpServer.php';
require_once __DIR__.'/FakeItop.php';

GlobalState::Capture();

/**
 * Base class for the tests of the framework.
 *
 * Before and after each test it:
 * - restores the global state (static properties of core/ classes and $argv, see GlobalState);
 * - empties collectors/ (the developer's files are stashed, see WorkingCopyGuard);
 * - records the logs in $this->oLogger instead of printing them;
 * - loads the configuration with data_path set to a private temporary directory.
 *
 * StartFakeItop() points the configuration to an in-memory iTop (see FakeItop): no test reaches the network.
 */
abstract class BaseTestCase extends TestCase
{
	/** @var \UnitTestFiles\Test\Support\RecordingLogger Every message logged through Utils::Log() during the test */
	protected $oLogger;

	/** @var \UnitTestFiles\Test\Support\FakeItop|null Set by StartFakeItop() */
	protected $oFakeItop = null;

	/** @var string|null */
	private $sTempDir = null;

	/** @var \UnitTestFiles\Test\Support\FakeHttpServer|null */
	private $oFakeHttpServer = null;

	/** @var \Parameters|null */
	private $oConfig = null;

	/** @var array<string, mixed> Configuration values applied on top of the files at each LoadConfiguration() */
	private $aConfigOverrides = [];

	protected function setUp(): void
	{
		parent::setUp();

		GlobalState::Restore();
		$this->EmptyCollectorsDir();

		$this->oLogger = new RecordingLogger();
		Utils::MockLog($this->oLogger, LOG_DEBUG);
		// With a mocked logger, Utils::Log() also var_dump()s each message: keep the test output readable
		$this->setOutputCallback(function () {
			return '';
		});

		$this->LoadConfiguration();
	}

	protected function tearDown(): void
	{
		try {
			if ($this->oFakeItop !== null) {
				$this->assertSame([], $this->oFakeItop->GetUnsupportedRequests(), 'The fake iTop received requests it does not emulate: extend FakeItop.');
			}
		} finally {
			GlobalState::Restore();
			$this->EmptyCollectorsDir();
			if ($this->sTempDir !== null) {
				self::DeleteTree($this->sTempDir);
				$this->sTempDir = null;
			}
			parent::tearDown();
		}
	}

	/**
	 * Point the configuration to a new in-memory iTop, and route every HTTP request of the framework to it.
	 *
	 * @return \UnitTestFiles\Test\Support\FakeItop Also available as $this->oFakeItop
	 */
	protected function StartFakeItop(): FakeItop
	{
		$this->oFakeItop = new FakeItop();
		$this->oFakeItop->InstallOn($this->GetFakeHttpServer());
		$this->SetConfigurationOverrides([
			'itop_url' => FakeItop::URL,
			'itop_login' => FakeItop::LOGIN,
			'itop_password' => FakeItop::PASSWORD,
			'itop_token' => '',
			'itop_login_mode' => '',
		]);

		return $this->oFakeItop;
	}

	/**
	 * @return \UnitTestFiles\Test\Support\FakeHttpServer Receives every HTTP request of the framework; add routes
	 *     to it to fake third-party APIs
	 */
	protected function GetFakeHttpServer(): FakeHttpServer
	{
		if ($this->oFakeHttpServer === null) {
			$this->oFakeHttpServer = new FakeHttpServer();
			Utils::MockDoPostRequestService($this->oFakeHttpServer);
		}

		return $this->oFakeHttpServer;
	}

	/**
	 * Set configuration values now, and keep them when the configuration is reloaded during the test.
	 *
	 * @param array<string, mixed> $aValues
	 */
	protected function SetConfigurationOverrides(array $aValues): void
	{
		$this->aConfigOverrides = array_merge($this->aConfigOverrides, $aValues);
		foreach ($aValues as $sCode => $value) {
			$this->oConfig->Set($sCode, $value);
		}
	}

	/**
	 * (Re)load the configuration from the files, like exec.php does: conf/params.distrib.xml, then
	 * collectors/params.distrib.xml, then collectors/extensions/params.distrib.xml, then --config_file or
	 * conf/params.local.xml (which the tests never see). Call it again after installing collector files.
	 *
	 * @param array<string, mixed> $aValues Top-level values to set on top of the files (and of the values given to
	 *     SetConfigurationOverrides())
	 *
	 * @return \Parameters The configuration now used by Utils::GetConfigurationValue()
	 */
	protected function LoadConfiguration(array $aValues = []): Parameters
	{
		// Utils::LoadConfig() appends to the list of loaded files: start from an empty list, as exec.php does
		$this->SetNonPublicProperty(Utils::class, 'aConfigFiles', []);
		$this->oConfig = Utils::LoadConfig();
		$this->oConfig->Set('data_path', $this->GetDataDir());
		foreach (array_merge($this->aConfigOverrides, $aValues) as $sCode => $value) {
			$this->oConfig->Set($sCode, $value);
		}

		return $this->oConfig;
	}

	/**
	 * @return string Private temporary directory of the test, deleted after it
	 */
	protected function GetTempDir(): string
	{
		if ($this->sTempDir === null) {
			$this->sTempDir = sys_get_temp_dir().'/itop-collector-base-tests-'.getmypid().'-'.bin2hex(random_bytes(4));
			mkdir($this->sTempDir, 0700, true);
		}

		return $this->sTempDir;
	}

	/**
	 * @return string The data_path of the test (where CSV files are written), with a trailing slash
	 */
	protected function GetDataDir(): string
	{
		$sDataDir = $this->GetTempDir().'/data/';
		if (!is_dir($sDataDir)) {
			mkdir($sDataDir, 0700, true);
		}

		return $sDataDir;
	}

	/**
	 * Copy a fixture directory, recursively, into collectors/ (or one of its sub-directories).
	 *
	 * @param string $sSourceDir Directory to copy the content of
	 * @param string $sTargetSubDir Sub-directory of collectors/ (e.g. 'json', 'extensions/src'), '' for collectors/ itself
	 */
	protected function InstallCollectorFiles(string $sSourceDir, string $sTargetSubDir = ''): void
	{
		if (!is_dir($sSourceDir)) {
			throw new RuntimeException("Fixture directory '$sSourceDir' does not exist.");
		}
		self::CopyTree($sSourceDir, rtrim(APPROOT.'collectors/'.$sTargetSubDir, '/'));
	}

	/**
	 * Write one file into collectors/, creating the sub-directories.
	 *
	 * @param string $sRelativePath Path relative to collectors/ (e.g. 'json/MyCollector.json')
	 * @param string $sContent
	 *
	 * @return string The absolute path of the file
	 */
	protected function WriteCollectorFile(string $sRelativePath, string $sContent): string
	{
		$sPath = APPROOT.'collectors/'.$sRelativePath;
		if (!is_dir(dirname($sPath))) {
			mkdir(dirname($sPath), 0755, true);
		}
		file_put_contents($sPath, $sContent);

		return $sPath;
	}

	/**
	 * Write collectors/json/<Collector>.json, the SDS definition read by Collector::Init() and
	 * Collector::InitSynchroDataSource(). By default: a data source on Organization named "<Collector> test source",
	 * reconciled on name, also updating code.
	 *
	 * @param string $sCollectorClass
	 * @param array<string, mixed> $aChanges Top-level values replacing the defaults ('attribute_list' replaces the list)
	 *
	 * @return string The path of the file
	 */
	protected function WriteSynchroDataSourceDefinition(string $sCollectorClass, array $aChanges = []): string
	{
		$aDefinition = array_merge([
			'name' => "$sCollectorClass test source",
			'description' => '',
			'status' => 'production',
			'user_id' => '$synchro_user$',
			'notify_contact_id' => '$contact_to_notify$',
			'scope_class' => 'Organization',
			'database_table_name' => '',
			'scope_restriction' => '',
			'full_load_periodicity' => '0',
			'reconciliation_policy' => 'use_attributes',
			'action_on_zero' => 'create',
			'action_on_one' => 'update',
			'action_on_multiple' => 'error',
			'delete_policy' => 'ignore',
			'delete_policy_update' => '',
			'delete_policy_retention' => '0',
			'attribute_list' => [
				self::SynchroAttribute('name', true),
				self::SynchroAttribute('code'),
			],
			'user_delete_policy' => 'nobody',
			'url_icon' => '',
			'url_application' => '',
		], $aChanges);

		return $this->WriteCollectorFile("json/$sCollectorClass.json", json_encode($aDefinition, JSON_PRETTY_PRINT));
	}

	/**
	 * @param string $sAttCode
	 * @param bool $bReconcile
	 * @param bool $bUpdate
	 * @param array<string, string> $aExtra e.g. ['finalclass' => 'SynchroAttExtKey', 'reconciliation_attcode' => 'name']
	 *
	 * @return array<string, string> An entry of the attribute_list of an SDS definition
	 */
	protected static function SynchroAttribute(string $sAttCode, bool $bReconcile = false, bool $bUpdate = true, array $aExtra = []): array
	{
		return array_merge([
			'attcode' => $sAttCode,
			'update' => $bUpdate ? '1' : '0',
			'reconcile' => $bReconcile ? '1' : '0',
			'update_policy' => 'master_locked',
			'finalclass' => 'SynchroAttribute',
		], $aExtra);
	}

	/**
	 * Simulate the command line read by Utils::ReadParameter(), Utils::ReadBooleanParameter() and
	 * Utils::CheckParameters(). Restored after the test.
	 *
	 * @param string[] $aArguments e.g. ['--collect_only', '--max_chunk_size=10']
	 */
	protected function SetCliArguments(array $aArguments): void
	{
		$GLOBALS['argv'] = array_merge(['exec.php'], $aArguments);
	}

	/**
	 * @param object|string $objectOrClass
	 * @param string $sMethod
	 * @param array $aArgs
	 *
	 * @return mixed
	 */
	protected function InvokeNonPublicMethod($objectOrClass, string $sMethod, array $aArgs = [])
	{
		$oMethod = new ReflectionMethod($objectOrClass, $sMethod);
		$oMethod->setAccessible(true);

		return $oMethod->invokeArgs(is_object($objectOrClass) ? $objectOrClass : null, $aArgs);
	}

	/**
	 * @param object|string $objectOrClass
	 * @param string $sProperty
	 *
	 * @return mixed
	 */
	protected function GetNonPublicProperty($objectOrClass, string $sProperty)
	{
		$oProperty = new ReflectionProperty($objectOrClass, $sProperty);
		$oProperty->setAccessible(true);

		return $oProperty->getValue(is_object($objectOrClass) ? $objectOrClass : null);
	}

	/**
	 * @param object|string $objectOrClass
	 * @param string $sProperty
	 * @param mixed $value
	 */
	protected function SetNonPublicProperty($objectOrClass, string $sProperty, $value): void
	{
		$oProperty = new ReflectionProperty($objectOrClass, $sProperty);
		$oProperty->setAccessible(true);
		$oProperty->setValue(is_object($objectOrClass) ? $objectOrClass : null, $value);
	}

	private function EmptyCollectorsDir(): void
	{
		$sCollectorsDir = APPROOT.'collectors';
		if (is_dir($sCollectorsDir) && !is_link($sCollectorsDir)) {
			self::DeleteTree($sCollectorsDir, false);
		} elseif (!file_exists($sCollectorsDir)) {
			mkdir($sCollectorsDir, 0755);
		}
	}

	private static function CopyTree(string $sSource, string $sTarget): void
	{
		if (!is_dir($sTarget)) {
			mkdir($sTarget, 0755, true);
		}
		foreach (scandir($sSource) as $sEntry) {
			if ($sEntry === '.' || $sEntry === '..') {
				continue;
			}
			if (is_dir("$sSource/$sEntry")) {
				self::CopyTree("$sSource/$sEntry", "$sTarget/$sEntry");
			} elseif (!copy("$sSource/$sEntry", "$sTarget/$sEntry")) {
				throw new RuntimeException("Failed to copy '$sSource/$sEntry' to '$sTarget/$sEntry'.");
			}
		}
	}

	private static function DeleteTree(string $sDir, bool $bDeleteDir = true): void
	{
		if (!is_dir($sDir)) {
			return;
		}
		foreach (scandir($sDir) as $sEntry) {
			if ($sEntry === '.' || $sEntry === '..') {
				continue;
			}
			$sPath = "$sDir/$sEntry";
			if (is_dir($sPath) && !is_link($sPath)) {
				self::DeleteTree($sPath);
			} else {
				unlink($sPath);
			}
		}
		if ($bDeleteDir) {
			rmdir($sDir);
		}
	}
}
