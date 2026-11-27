<?php

namespace UnitTestFiles\Test\Unitary;

use UnitTestFiles\Test\ExecTestCase;
use UnitTestFiles\Test\Service\FakeItop;
use UnitTestFiles\Test\Service\FakeItopServer;

require_once dirname(__DIR__).'/src/BaseTestCase/ExecTestCase.php';
require_once dirname(__DIR__).'/src/Service/FakeItopServer.php';

/**
 * exec.php end to end, against FakeItop served over HTTP by `php -S`. The same scenarios run against a real iTop in
 * integration-tests/RealItopExecTest.php.
 *
 * As with the in-memory fake, a request the fake does not emulate fails the test (checked in tearDown()), even when
 * exec.php ignores the error it gets back.
 */
class FakeItopExecTest extends ExecTestCase
{
	/** @var \UnitTestFiles\Test\Service\FakeItopServer|null */
	private $oServer = null;

	protected function ConnectToItop(): array
	{
		$this->oServer = new FakeItopServer($this->GetTempDir());
		$this->oServer->Start();

		return ['url' => $this->oServer->GetUrl(), 'login' => FakeItop::LOGIN, 'password' => FakeItop::PASSWORD, 'token' => '', 'curl_options' => []];
	}

	protected function tearDown(): void
	{
		try {
			try {
				// Before parent::tearDown(), which deletes the temporary directory holding the state of the fake
				if ($this->oServer !== null) {
					$this->assertSame([], $this->oServer->GetItop()->GetUnsupportedRequests(), 'The fake iTop received requests it does not emulate: extend FakeItop.');
				}
			} finally {
				// Cleans up through the server, so before stopping it
				parent::tearDown();
			}
		} finally {
			if ($this->oServer !== null) {
				$this->oServer->Stop();
			}
		}
	}

	public function testRequestsReceivedByTheFakeItop(): void
	{
		$this->assertSame(0, $this->RunExec(['--collect_only'])['exit_code']);
		$this->assertSame([], $this->oServer->GetItop()->GetRequests(), '--collect_only does not contact iTop');

		$this->assertSame(0, $this->RunExec()['exit_code']);

		$oItop = $this->oServer->GetItop();
		$this->assertCount(1, $oItop->GetRequests('synchro_import'));
		$aExec = $oItop->GetRequests('synchro_exec');
		$this->assertCount(1, $aExec);
		$this->assertArrayNotHasKey('max_chunk_size', $aExec[0]['data'], 'Known behaviour KB-02: max_chunk_size is not forwarded');
	}
}
