<?php

/**
 * Returned by a "before" hook to skip the hooked method.
 * The wrapped value is used as the result of the method (the "after" hooks still run on it).
 */
final class CollectorHookSkip {
	/**
	 * @param mixed $mResult Result to use instead of calling the hooked method.
	 */
	public function __construct(public readonly mixed $mResult = null) {
	}
}

/**
 * Registry of the hooks which extensions attach to the methods of collectors.
 *
 * Hooks are registered with {@see Collector::AddHook()} (or declaratively, through the files
 * in "collectors/extensions/hooks/") and are executed by {@see Collector::Dispatch()}.
 *
 * Signature of a "before" hook: fn(Collector $oCollector, array &$aArgs): mixed
 *  - The arguments of the hooked method can be altered through $aArgs.
 *  - Returning {@see CollectorHooks::Skip()} skips the remaining "before" hooks and the method itself.
 *  - Any other return value is ignored.
 *
 * Signature of an "after" hook: fn(Collector $oCollector, array $aArgs, mixed &$mResult): void
 *  - The result of the hooked method can be altered through $mResult.
 */
final class CollectorHooks {
	public const WHEN_BEFORE = 'before';
	public const WHEN_AFTER = 'after';

	/** Folder (relative to APPROOT) containing the hook files of the extensions. */
	public const HOOKS_FOLDER = 'collectors/extensions/hooks/';

	/**
	 * @var array<string, array<int, array{sTarget: string, sWhen: string, iPriority: int, iSequence: int, cCallback: callable}>>
	 *  Registered hooks, indexed by method name.
	 */
	private static array $aHooks = [];

	/** @var int Incremented on every change of the registry, to invalidate the per-collector caches. */
	private static int $iVersion = 0;

	/** @var int Registration order, used as tie-breaker for hooks having the same priority. */
	private static int $iSequence = 0;

	private static bool $bFilesLoaded = false;

	/**
	 * Register a hook.
	 *
	 * @param string $sTarget Collector class (or parent class / interface) the hook applies to, '*' for all collectors.
	 * @param string $sMethod One of {@see Collector::HOOKABLE_METHODS}.
	 * @param string $sWhen {@see self::WHEN_BEFORE} or {@see self::WHEN_AFTER}.
	 * @param callable $cCallback The hook.
	 * @param int $iPriority Hooks with a lower value run first. Hooks with the same value run in registration order.
	 *
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	public static function Add(string $sTarget, string $sMethod, string $sWhen, callable $cCallback, int $iPriority = 0): void {
		if(!in_array($sMethod, Collector::HOOKABLE_METHODS, true)) {
			throw new InvalidArgumentException(sprintf("Method '%s' cannot be hooked. Hookable methods: %s.", $sMethod, implode(', ', Collector::HOOKABLE_METHODS)));
		}
		if(($sWhen !== self::WHEN_BEFORE) && ($sWhen !== self::WHEN_AFTER)) {
			throw new InvalidArgumentException(sprintf("Invalid hook moment '%s', expected '%s' or '%s'.", $sWhen, self::WHEN_BEFORE, self::WHEN_AFTER));
		}
		$sTarget = ltrim(trim($sTarget), '\\');
		if($sTarget === '') {
			throw new InvalidArgumentException('A hook requires a target collector class (or \'*\').');
		}

		self::$aHooks[$sMethod][] = [
			'sTarget' => $sTarget,
			'sWhen' => $sWhen,
			'iPriority' => $iPriority,
			'iSequence' => self::$iSequence++,
			'cCallback' => $cCallback,
		];
		self::$iVersion++;
	}

	/**
	 * Create the value which a "before" hook returns to skip the hooked method.
	 *
	 * @param mixed $mResult Result to use instead of calling the hooked method.
	 *
	 * @return \CollectorHookSkip
	 */
	public static function Skip(mixed $mResult = null): CollectorHookSkip {
		return new CollectorHookSkip($mResult);
	}

	/**
	 * @return int Changes whenever the registry changes.
	 */
	public static function GetVersion(): int {
		return self::$iVersion;
	}

	/**
	 * Determine the hooks which apply to a method of a collector.
	 *
	 * @param \Collector $oCollector
	 * @param string $sMethod
	 *
	 * @return array{before: callable[], after: callable[]}|null Null when no hook applies.
	 */
	public static function Resolve(Collector $oCollector, string $sMethod): ?array {
		if(!isset(self::$aHooks[$sMethod])) {
			return null;
		}

		$aEntries = self::$aHooks[$sMethod];
		usort($aEntries, static fn(array $aEntry1, array $aEntry2): int => [$aEntry1['iPriority'], $aEntry1['iSequence']] <=> [$aEntry2['iPriority'], $aEntry2['iSequence']]);

		$aBefore = [];
		$aAfter = [];
		foreach($aEntries as $aEntry) {
			$sTarget = $aEntry['sTarget'];
			if(($sTarget !== '*') && !($oCollector instanceof $sTarget)) {
				continue;
			}
			if($aEntry['sWhen'] === self::WHEN_BEFORE) {
				$aBefore[] = $aEntry['cCallback'];
			}
			else {
				$aAfter[] = $aEntry['cCallback'];
			}
		}

		if(empty($aBefore) && empty($aAfter)) {
			return null;
		}

		return ['before' => $aBefore, 'after' => $aAfter];
	}

	/**
	 * Include every PHP file of the hooks folder of the extensions (once per run) and register the hooks they return.
	 *
	 * A hook file returns an array of hook definitions, each one being an array with the keys:
	 * 'target', 'method', 'when', 'callback' and optionally 'priority'.
	 * A file returning anything else is included, but registers no hooks.
	 *
	 * @return void
	 * @throws \Exception When a hook definition is invalid.
	 */
	public static function LoadHookFiles(): void {
		if(self::$bFilesLoaded) {
			return;
		}
		self::$bFilesLoaded = true;

		$aFiles = glob(APPROOT.self::HOOKS_FOLDER.'*.php');
		if(empty($aFiles)) {
			return;
		}
		sort($aFiles);

		foreach($aFiles as $sFile) {
			$mDefinitions = self::IncludeFile($sFile);
			if(!is_array($mDefinitions)) {
				Utils::Log(LOG_DEBUG, sprintf("Hook file '%s' does not return an array: no hooks registered.", $sFile));
				continue;
			}

			try {
				$iCount = self::RegisterDefinitions($mDefinitions);
			}
			catch(InvalidArgumentException|TypeError $e) {
				throw new Exception(sprintf("Invalid hook definition in '%s': %s", $sFile, $e->getMessage()), 0, $e);
			}
			Utils::Log(LOG_DEBUG, sprintf("Hook file '%s' registered %d hook(s).", $sFile, $iCount));
		}
	}

	/**
	 * Forget every hook and allow the hook files to be loaded again.
	 *
	 * @return void
	 */
	public static function Reset(): void {
		self::$aHooks = [];
		self::$iSequence = 0;
		self::$bFilesLoaded = false;
		self::$iVersion++;
	}

	/**
	 * Include a file in an isolated scope.
	 *
	 * @param string $sFile
	 *
	 * @return mixed
	 */
	private static function IncludeFile(string $sFile): mixed {
		return require $sFile;
	}

	/**
	 * @param array $aDefinitions
	 *
	 * @return int Number of registered hooks.
	 * @throws \InvalidArgumentException
	 */
	private static function RegisterDefinitions(array $aDefinitions): int {
		$iCount = 0;
		foreach($aDefinitions as $mKey => $aDefinition) {
			if(!is_array($aDefinition)) {
				throw new InvalidArgumentException(sprintf("Entry '%s' is not an array.", $mKey));
			}
			foreach(['target', 'method', 'when', 'callback'] as $sRequiredKey) {
				if(!array_key_exists($sRequiredKey, $aDefinition)) {
					throw new InvalidArgumentException(sprintf("Entry '%s' misses the key '%s'.", $mKey, $sRequiredKey));
				}
			}
			if(!is_callable($aDefinition['callback'])) {
				throw new InvalidArgumentException(sprintf("The callback of entry '%s' is not callable.", $mKey));
			}

			self::Add((string)$aDefinition['target'], (string)$aDefinition['method'], (string)$aDefinition['when'], $aDefinition['callback'], (int)($aDefinition['priority'] ?? 0));
			$iCount++;
		}

		return $iCount;
	}
}
