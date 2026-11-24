<?php

namespace UnitTestFiles\Test;

use UnitTestFiles\Test\Support\ExecTestCase;
use UnitTestFiles\Test\Support\FakeItop;
use UnitTestFiles\Test\Support\FakeItopServer;

require_once __DIR__.'/Support/ExecTestCase.php';
require_once __DIR__.'/Support/FakeItopServer.php';

/**
 * exec.php end to end, against FakeItop served over HTTP by `php -S`. The same scenarios run against a real iTop in
 * test/integration/RealItopExecTest.php.
 */
class FakeItopExecTest extends ExecTestCase
{
	/** @var \UnitTestFiles\Test\Support\FakeItopServer */
	private $oServer;

	protected function ConnectToItop(): array
	{
		$this->oServer = new FakeItopServer($this->GetTempDir());
		$this->oServer->Start();

		return ['url' => $this->oServer->GetUrl(), 'login' => FakeItop::LOGIN, 'password' => FakeItop::PASSWORD, 'token' => '', 'curl_options' => []];
	}

	protected function tearDown(): void
	{
		try {
			parent::tearDown();
		} finally {
			$this->oServer->Stop();
		}
	}

	public function testRequestsReceivedByTheFakeItop(): void
	{
		$this->assertSame(0, $this->RunExec(['--collect_only'])['exit_code']);
		$this->assertSame([], $this->oServer->GetItop()->GetRequests(), '--collect_only does not contact iTop');

		$this->assertSame(0, $this->RunExec()['exit_code']);

		$oItop = $this->oServer->GetItop();
		$this->assertSame([], $oItop->GetUnsupportedRequests());
		$this->assertCount(1, $oItop->GetRequests('synchro_import'));
		$aExec = $oItop->GetRequests('synchro_exec');
		$this->assertCount(1, $aExec);
		$this->assertArrayNotHasKey('max_chunk_size', $aExec[0]['data'], 'Known quirk: max_chunk_size is not forwarded');
	}
}
