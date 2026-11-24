<?php

/**
 * Collector whose behaviour each test scripts, and which records the calls it receives.
 *
 * Script keys (all optional), per class in ScriptableCollector::$aScripts[<class>]:
 * - rows: rows returned by Fetch();
 * - optional: attcodes for which AttributeIsOptional() returns true;
 * - must_process: return value of MustProcessBeforeSynchro();
 * - process_line: callable(array &$aLineData, int $iLineIndex) run by ProcessLineBeforeSynchro();
 * - check_to_launch: return value of CheckToLaunch();
 * - skip_init: do not read the SDS definition in Init();
 * - init_sds_result, collect_result, synchronize_result: return these instead of running the real methods.
 *
 * Use the sub-classes (One, Two, Three) to register several collectors.
 */
class ScriptableCollector extends Collector
{
	/** @var array<string, array<string, mixed>> */
	public static $aScripts = [];

	/** @var array<int, array{0: string, 1: string, 2: array}> [class, method, arguments] in call order */
	public static $aCalls = [];

	/** @var int */
	private $iRowIndex = 0;

	public static function Reset(): void
	{
		self::$aScripts = [];
		self::$aCalls = [];
	}

	/**
	 * @return string[] "Class::Method" in call order, optionally only for one method
	 */
	public static function GetCalls(?string $sMethod = null): array
	{
		$aCalls = [];
		foreach (self::$aCalls as [$sClass, $sCalledMethod]) {
			if ($sMethod === null || $sMethod === $sCalledMethod) {
				$aCalls[] = "$sClass::$sCalledMethod";
			}
		}

		return $aCalls;
	}

	/**
	 * @return mixed
	 */
	protected function Script(string $sKey, $default = null)
	{
		return self::$aScripts[static::class][$sKey] ?? $default;
	}

	protected function HasScript(string $sKey): bool
	{
		return array_key_exists($sKey, self::$aScripts[static::class] ?? []);
	}

	public function Init(): void
	{
		self::$aCalls[] = [static::class, 'Init', []];
		if (!$this->Script('skip_init', false)) {
			parent::Init();
		}
	}

	public function InitSynchroDataSource($aPlaceholders)
	{
		self::$aCalls[] = [static::class, 'InitSynchroDataSource', func_get_args()];

		return $this->HasScript('init_sds_result') ? $this->Script('init_sds_result') : parent::InitSynchroDataSource($aPlaceholders);
	}

	public function Collect($iMaxChunkSize = 0)
	{
		self::$aCalls[] = [static::class, 'Collect', func_get_args()];

		return $this->HasScript('collect_result') ? $this->Script('collect_result') : parent::Collect($iMaxChunkSize);
	}

	public function Synchronize($iMaxChunkSize = 0)
	{
		self::$aCalls[] = [static::class, 'Synchronize', func_get_args()];

		return $this->HasScript('synchronize_result') ? $this->Script('synchronize_result') : parent::Synchronize($iMaxChunkSize);
	}

	public function CheckToLaunch(array $aOrchestratedCollectors): bool
	{
		self::$aCalls[] = [static::class, 'CheckToLaunch', func_get_args()];

		return $this->Script('check_to_launch', true);
	}

	public function AttributeIsOptional($sAttCode)
	{
		return in_array($sAttCode, $this->Script('optional', []), true);
	}

	protected function Prepare()
	{
		$this->iRowIndex = 0;

		return parent::Prepare();
	}

	protected function Fetch()
	{
		return $this->Script('rows', [])[$this->iRowIndex++] ?? false;
	}

	protected function MustProcessBeforeSynchro()
	{
		return $this->Script('must_process', false);
	}

	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex)
	{
		$fProcess = $this->Script('process_line');
		if ($fProcess !== null) {
			$fProcess($aLineData, $iLineIndex);
		}
	}

	/**
	 * @return int|null
	 */
	public function GetSourceId()
	{
		return $this->iSourceId;
	}
}

class ScriptableCollectorOne extends ScriptableCollector
{
}

class ScriptableCollectorTwo extends ScriptableCollector
{
}

class ScriptableCollectorThree extends ScriptableCollector
{
}
