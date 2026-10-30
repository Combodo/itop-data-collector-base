<?php

/*
 * @copyright   Copyright (C) 2010-2023 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace UnitTestFiles\Test;

use Parameters;
use PHPUnit\Framework\TestCase;

@define('APPROOT', dirname(__DIR__).'/');

require_once(APPROOT.'core/parameters.class.inc.php');

class ParametersTest extends TestCase
{
	private function GetNonPublicProperty(object $oObject, string $sProperty)
	{
		$oProperty = $this->GetProperty(get_class($oObject), $sProperty);
		return $oProperty->getValue($oObject);
	}

	private function SetNonPublicProperty(object $oObject, string $sProperty, $value)
	{
		$oProperty = $this->GetProperty(get_class($oObject), $sProperty);
		$oProperty->setValue($oObject, $value);
	}

	private function GetProperty(string $sClass, string $sProperty): \ReflectionProperty
	{
		$class = new \ReflectionClass($sClass);
		return  $class->getProperty($sProperty);
	}

	/**
	 * @dataProvider ToXMLProvider
	 */
	public function testToXML(array $aData, string $sExpectedDump)
	{
		$oParameters = new Parameters();
		$this->SetNonPublicProperty($oParameters, 'aData', $aData);

		$sDumpParameters = $oParameters->Dump();

		$this->assertStringContainsString($sExpectedDump, $sDumpParameters);
	}

	public function ToXMLProvider()
	{
		return [
			'Parameter with &amp;' => [
				'aData' => ['escaped_param' => '(&(objectClass=person)(mail=*))'],
				'sExpectedDump' => '<escaped_param>(&amp;(objectClass=person)(mail=*))</escaped_param>',
			],
			'Parameter with array' => [
				'aData' => ['paramroot' => ['param1' => 'param1val', 'param2' => 'param2val']],
				'sExpectedDump' => "<paramroot>\n    <param1>param1val</param1>\n    <param2>param2val</param2>\n  </paramroot>",
			],
		];
	}

	/**
	 * @dataProvider MergeProvider
	 */
	public function testMerge(array $aBase, array $aOverride, array $aExpected)
	{
		$oBase = new Parameters();
		$this->SetNonPublicProperty($oBase, 'aData', $aBase);
		$oOverride = new Parameters();
		$this->SetNonPublicProperty($oOverride, 'aData', $aOverride);

		$oBase->Merge($oOverride);

		$this->assertSame($aExpected, $this->GetNonPublicProperty($oBase, 'aData'));
	}

	public function MergeProvider()
	{
		return [
			'shorter array replaces' => [
				'aBase'     => ['list' => ['a', 'b', 'c']],
				'aOverride' => ['list' => ['x']],
				'aExpected' => ['list' => ['x']],
			],
			'empty array replaces' => [
				'aBase'     => ['list' => ['a', 'b']],
				'aOverride' => ['list' => []],
				'aExpected' => ['list' => []],
			],
			'longer array replaces' => [
				'aBase'     => ['list' => ['a']],
				'aOverride' => ['list' => ['x', 'y']],
				'aExpected' => ['list' => ['x', 'y']],
			],
			'hashes still merge' => [
				'aBase'     => ['hash' => ['kept' => 1, 'overridden' => 2]],
				'aOverride' => ['hash' => ['overridden' => 3]],
				'aExpected' => ['hash' => ['kept' => 1, 'overridden' => 3]],
			],
			'nested hash merges, nested array replaces' => [
				'aBase'     => ['hash' => ['list' => ['a', 'b'], 'kept' => 1]],
				'aOverride' => ['hash' => ['list' => ['x']]],
				'aExpected' => ['hash' => ['list' => ['x'], 'kept' => 1]],
			],
			'empty array over hash keeps hash' => [
				'aBase'     => ['hash' => ['kept' => 1]],
				'aOverride' => ['hash' => []],
				'aExpected' => ['hash' => ['kept' => 1]],
			],
			'scalar replaces array' => [
				'aBase'     => ['list' => ['a', 'b']],
				'aOverride' => ['list' => 'x'],
				'aExpected' => ['list' => 'x'],
			],
			'array replaces scalar' => [
				'aBase'     => ['scalar' => 'x'],
				'aOverride' => ['scalar' => ['a']],
				'aExpected' => ['scalar' => ['a']],
			],
			'scalar replaces scalar' => [
				'aBase'     => ['scalar' => 'x'],
				'aOverride' => ['scalar' => 'y'],
				'aExpected' => ['scalar' => 'y'],
			],
			'missing key in override is kept' => [
				'aBase'     => ['kept' => 1, 'overridden' => 2],
				'aOverride' => ['overridden' => 3],
				'aExpected' => ['kept' => 1, 'overridden' => 3],
			],
			'new key is added' => [
				'aBase'     => ['existing' => 1],
				'aOverride' => ['added' => 2],
				'aExpected' => ['existing' => 1, 'added' => 2],
			],
		];
	}
}
