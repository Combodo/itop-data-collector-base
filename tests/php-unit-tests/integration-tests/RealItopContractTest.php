<?php

namespace UnitTestFiles\Test\Integration;

use UnitTestFiles\Test\Support\ItopContractTestCase;

require_once dirname(__DIR__).'/src/BaseTestCase/ItopContractTestCase.php';
require_once __DIR__.'/RealItop.php';

/**
 * The iTop contract scenarios against a real iTop (see RealItop for the configuration). The same scenarios run against
 * FakeItop in unitary-tests/core/FakeItopContractTest.php: when one passes here but not there, fix the fake.
 */
class RealItopContractTest extends ItopContractTestCase
{
	protected function ConnectToItop(): void
	{
		$aConnection = RealItop::GetConnection();
		if (is_string($aConnection)) {
			$this->markTestSkipped($aConnection);
		}

		$this->SetConfigurationOverrides([
			'itop_url' => $aConnection['url'],
			'itop_login' => $aConnection['login'],
			'itop_password' => $aConnection['password'],
			'itop_token' => $aConnection['token'],
			'itop_login_mode' => '',
		]);
		if (!empty($aConnection['curl_options'])) {
			$this->SetConfigurationOverrides(['curl_options' => $aConnection['curl_options']]);
		}
	}
}
