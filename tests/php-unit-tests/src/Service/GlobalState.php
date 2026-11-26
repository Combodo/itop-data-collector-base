<?php

namespace UnitTestFiles\Test\Service;

use ReflectionClass;
use ReflectionProperty;

/**
 * Snapshot of the global state the framework keeps between calls: the static properties of every class defined in
 * core/ (configuration, mocks, registered collectors, caches…) and the CLI arguments ($argv).
 *
 * All tests run in one PHP process, so a test that changes this state leaks it into the next tests. BaseTestCase
 * restores the snapshot before and after each test. Being generic, it also covers static properties added later.
 */
final class GlobalState
{
	/** @var array<string, array<string, mixed>>|null Class => property => initial value */
	private static $aStaticProperties = null;

	/** @var array|null */
	private static $aArgv = null;

	/**
	 * Record the initial state. Only the first call counts.
	 */
	public static function Capture(): void
	{
		if (self::$aStaticProperties !== null) {
			return;
		}

		self::$aStaticProperties = [];
		foreach (self::GetStaticProperties() as $oProperty) {
			self::$aStaticProperties[$oProperty->getDeclaringClass()->getName()][$oProperty->getName()] = $oProperty->getValue();
		}
		self::$aArgv = $GLOBALS['argv'] ?? [];
	}

	/**
	 * Put back the state recorded by Capture().
	 */
	public static function Restore(): void
	{
		self::Capture();

		foreach (self::GetStaticProperties() as $oProperty) {
			$sClass = $oProperty->getDeclaringClass()->getName();
			$sName = $oProperty->getName();
			if (array_key_exists($sClass, self::$aStaticProperties) && array_key_exists($sName, self::$aStaticProperties[$sClass])) {
				$oProperty->setValue(null, self::$aStaticProperties[$sClass][$sName]);
			}
		}
		$GLOBALS['argv'] = self::$aArgv;
	}

	/**
	 * @return \ReflectionProperty[] Static properties declared by the classes of core/
	 */
	private static function GetStaticProperties(): array
	{
		$sCoreDir = realpath(APPROOT.'core').DIRECTORY_SEPARATOR;
		$aProperties = [];
		foreach (get_declared_classes() as $sClass) {
			$oClass = new ReflectionClass($sClass);
			$sFile = $oClass->getFileName();
			if ($sFile === false || strpos($sFile, $sCoreDir) !== 0) {
				continue;
			}
			foreach ($oClass->getProperties(ReflectionProperty::IS_STATIC) as $oProperty) {
				if ($oProperty->getDeclaringClass()->getName() !== $sClass) {
					continue;
				}
				$oProperty->setAccessible(true);
				$aProperties[] = $oProperty;
			}
		}

		return $aProperties;
	}
}
