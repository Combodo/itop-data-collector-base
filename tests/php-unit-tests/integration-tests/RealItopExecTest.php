<?php

namespace UnitTestFiles\Test\Integration;

use UnitTestFiles\Test\Support\ExecTestCase;

require_once dirname(__DIR__).'/src/BaseTestCase/ExecTestCase.php';
require_once __DIR__.'/RealItop.php';

/**
 * exec.php end to end against a real iTop (see RealItop for the configuration). The same scenarios run against FakeItop
 * in unitary-tests/FakeItopExecTest.php.
 */
class RealItopExecTest extends ExecTestCase
{
	protected function ConnectToItop(): array
	{
		$aConnection = RealItop::GetConnection();
		if (is_string($aConnection)) {
			$this->markTestSkipped($aConnection);
		}

		return $aConnection;
	}
}
