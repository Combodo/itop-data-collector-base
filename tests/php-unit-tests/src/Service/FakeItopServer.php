<?php

namespace UnitTestFiles\Test\Service;

use RuntimeException;

/**
 * Serves a FakeItop over real HTTP with PHP's built-in web server, for tests that run exec.php in another process.
 * The fake's state lives in a file: SetItop() before the run, GetItop() to inspect it afterwards.
 */
class FakeItopServer
{
	/** @var resource|null */
	private $hProcess = null;

	/** @var string */
	private $sStateFile;

	/** @var int */
	private $iPort = 0;

	/** @var string */
	private $sLogFile;

	public function __construct(string $sWorkDir)
	{
		$this->sStateFile = $sWorkDir.'/fake-itop.state';
		$this->sLogFile = $sWorkDir.'/fake-itop-server.log';
		$this->SetItop(new FakeItop());
	}

	public function __destruct()
	{
		$this->Stop();
	}

	/**
	 * @throws \RuntimeException When the server does not start
	 */
	public function Start(): void
	{
		$this->iPort = self::FindFreePort();
		$sCommand = [PHP_BINARY, '-S', "127.0.0.1:{$this->iPort}", __DIR__.'/fake-itop-router.php'];
		$aEnv = array_merge(getenv(), ['FAKE_ITOP_STATE' => $this->sStateFile]);
		$this->hProcess = proc_open($sCommand, [0 => ['pipe', 'r'], 1 => ['file', $this->sLogFile, 'a'], 2 => ['file', $this->sLogFile, 'a']], $aPipes, null, $aEnv);
		if (!is_resource($this->hProcess)) {
			throw new RuntimeException('Cannot start the fake iTop server.');
		}

		for ($i = 0; $i < 100; $i++) {
			$hSocket = @fsockopen('127.0.0.1', $this->iPort, $iErrNo, $sErrStr, 0.1);
			if ($hSocket !== false) {
				fclose($hSocket);

				return;
			}
			usleep(20000);
		}
		$this->Stop();
		throw new RuntimeException('The fake iTop server did not start: '.@file_get_contents($this->sLogFile));
	}

	public function Stop(): void
	{
		if (is_resource($this->hProcess)) {
			proc_terminate($this->hProcess);
			proc_close($this->hProcess);
		}
		$this->hProcess = null;
	}

	/**
	 * @return string The itop_url to configure
	 */
	public function GetUrl(): string
	{
		return "http://127.0.0.1:{$this->iPort}".parse_url(FakeItop::URL, PHP_URL_PATH);
	}

	/**
	 * @return \UnitTestFiles\Test\Service\FakeItop The current state of the fake iTop (a copy: call SetItop() to change it)
	 */
	public function GetItop(): FakeItop
	{
		return unserialize(file_get_contents($this->sStateFile));
	}

	public function SetItop(FakeItop $oItop): void
	{
		file_put_contents($this->sStateFile, serialize($oItop), LOCK_EX);
	}

	private static function FindFreePort(): int
	{
		$hSocket = stream_socket_server('tcp://127.0.0.1:0');
		$sName = stream_socket_get_name($hSocket, false);
		fclose($hSocket);

		return (int)substr($sName, strrpos($sName, ':') + 1);
	}
}
