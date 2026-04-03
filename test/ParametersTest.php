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
			'Parameter with integer' => [
				'aData' => ['my_int' => 42],
				'sExpectedDump' => '<my_int type="int">42</my_int>',
			],
		];
	}

	public function SetNonPublicProperty(object $oObject, string $sProperty, $value)
	{
		$oProperty = $this->GetProperty(get_class($oObject), $sProperty);
		$oProperty->setValue($oObject, $value);
	}
	private function GetProperty(string $sClass, string $sProperty): \ReflectionProperty
	{
		$class = new \ReflectionClass($sClass);
		$property = $class->getProperty($sProperty);
		$property->setAccessible(true);

		return $property;
	}
}
