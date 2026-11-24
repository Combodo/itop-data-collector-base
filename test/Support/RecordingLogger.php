<?php

namespace UnitTestFiles\Test\Support;

use UtilsLogger;

/**
 * Logger installed by BaseTestCase through Utils::MockLog(): it records every message instead of printing it, so
 * tests can assert on what the framework logged.
 */
class RecordingLogger extends UtilsLogger
{
	/** @var array<int, array{priority: int, message: string}> */
	private $aEntries = [];

	public function Log($iPriority, $sMessage)
	{
		$this->aEntries[] = ['priority' => (int)$iPriority, 'message' => (string)$sMessage];
	}

	/**
	 * @param int|null $iMaxPriority Only the entries at least as severe as this LOG_* level (null: all of them)
	 *
	 * @return array<int, array{priority: int, message: string}>
	 */
	public function GetEntries(?int $iMaxPriority = null): array
	{
		if ($iMaxPriority === null) {
			return $this->aEntries;
		}

		return array_values(array_filter($this->aEntries, function ($aEntry) use ($iMaxPriority) {
			return $aEntry['priority'] <= $iMaxPriority;
		}));
	}

	/**
	 * @param int $iMaxPriority LOG_* level
	 *
	 * @return string[] Messages at least as severe as $iMaxPriority
	 */
	public function GetMessages(int $iMaxPriority): array
	{
		return array_column($this->GetEntries($iMaxPriority), 'message');
	}

	/**
	 * @param int $iPriority Exact LOG_* level
	 * @param string $sRegExp
	 *
	 * @return bool True if a message logged at this exact level matches the regular expression
	 */
	public function HasMessage(int $iPriority, string $sRegExp): bool
	{
		foreach ($this->aEntries as $aEntry) {
			if ($aEntry['priority'] === $iPriority && preg_match($sRegExp, $aEntry['message'])) {
				return true;
			}
		}

		return false;
	}

	public function Clear(): void
	{
		$this->aEntries = [];
	}
}
