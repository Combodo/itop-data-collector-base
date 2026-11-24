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

GlobalState::Capture();

/**
 * Base class for the tests of the framework.
 *
 * Before and after each test it:
 * - restores the global state (static properties of core/ classes and $argv, see GlobalState);
 * - empties collectors/ (the developer's files are stashed, see WorkingCopyGuard);
 * - records the logs in $this->oLogger instead of printing them;
 * - loads the configuration with data_path set to a private temporary directory.
 */
abstract class BaseTestCase extends TestCase
{
	/** @var \UnitTestFiles\Test\Support\RecordingLogger Every message logged through Utils::Log() during the test */
	protected $oLogger;

	/** @var string|null */
	private $sTempDir = null;

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
		GlobalState::Restore();
		$this->EmptyCollectorsDir();
		if ($this->sTempDir !== null) {
			self::DeleteTree($this->sTempDir);
			$this->sTempDir = null;
		}

		parent::tearDown();
	}

	/**
	 * (Re)load the configuration from the files, like exec.php does: conf/params.distrib.xml, then
	 * collectors/params.distrib.xml, then collectors/extensions/params.distrib.xml, then --config_file or
	 * conf/params.local.xml (which the tests never see). Call it again after installing collector files.
	 *
	 * @param array<string, mixed> $aValues Top-level values to set on top of the files
	 *
	 * @return \Parameters The configuration now used by Utils::GetConfigurationValue()
	 */
	protected function LoadConfiguration(array $aValues = []): Parameters
	{
		// Utils::LoadConfig() appends to the list of loaded files: start from an empty list, as exec.php does
		$this->SetNonPublicProperty(Utils::class, 'aConfigFiles', []);
		$oConfig = Utils::LoadConfig();
		$oConfig->Set('data_path', $this->GetDataDir());
		foreach ($aValues as $sCode => $value) {
			$oConfig->Set($sCode, $value);
		}

		return $oConfig;
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
