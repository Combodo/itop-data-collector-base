<?php

namespace UnitTestFiles\Test\Unitary\Core;

use UnitTestFiles\Test\ItopContractTestCase;

require_once dirname(__DIR__, 2).'/src/BaseTestCase/ItopContractTestCase.php';

/**
 * The iTop contract scenarios against the in-memory FakeItop. The same scenarios run against a real iTop in
 * integration-tests/RealItopContractTest.php.
 */
class FakeItopContractTest extends ItopContractTestCase
{
	protected function ConnectToItop(): void
	{
		$this->StartFakeItop();
	}
}
