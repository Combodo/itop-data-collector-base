<?php

namespace UnitTestFiles\Test;

use UnitTestFiles\Test\Support\ItopContractTestCase;

require_once __DIR__.'/Support/ItopContractTestCase.php';

/**
 * The iTop contract scenarios against the in-memory FakeItop. The same scenarios run against a real iTop in
 * test/integration/RealItopContractTest.php.
 */
class FakeItopContractTest extends ItopContractTestCase
{
	protected function ConnectToItop(): void
	{
		$this->StartFakeItop();
	}
}
