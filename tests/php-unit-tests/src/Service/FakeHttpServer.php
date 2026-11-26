<?php

namespace UnitTestFiles\Test\Support;

use DoPostRequestService;
use IOException;

/**
 * Stands in for the network: Utils::DoPostRequest() hands every request to this object (through
 * Utils::MockDoPostRequestService()), which routes it to a fake server by URL prefix and records it.
 *
 * Routes are callables `function (string $sUrl, array $aData): string` returning the response body. FakeItop is one
 * of them; a test can add others for third-party APIs (e.g. a `jsonurl`).
 */
class FakeHttpServer extends DoPostRequestService
{
	/** @var array<string, callable> URL prefix => handler */
	private $aRoutes = [];

	/** @var array<int, array{url: string, data: array, headers: mixed, curl_options: array}> */
	private $aRequests = [];

	/**
	 * @param string $sUrlPrefix Requests whose URL starts with this prefix go to $fHandler (the longest prefix wins)
	 * @param callable $fHandler function (string $sUrl, array $aData): string
	 */
	public function Route(string $sUrlPrefix, callable $fHandler): void
	{
		$this->aRoutes[$sUrlPrefix] = $fHandler;
		uksort($this->aRoutes, function ($s1, $s2) {
			return strlen($s2) - strlen($s1);
		});
	}

	public function DoPostRequest($sUrl, $aData, $sOptionnalHeaders = null, &$aResponseHeaders = null, $aCurlOptions = [])
	{
		$aData = is_array($aData) ? $aData : [];
		$this->aRequests[] = ['url' => $sUrl, 'data' => $aData, 'headers' => $sOptionnalHeaders, 'curl_options' => $aCurlOptions];

		foreach ($this->aRoutes as $sPrefix => $fHandler) {
			if (strpos($sUrl, $sPrefix) === 0) {
				return $fHandler($sUrl, $aData);
			}
		}

		// What Utils::DoPostRequest() does when the host cannot be reached
		throw new IOException("Problem opening URL: $sUrl".PHP_EOL.'    error msg: (FakeHttpServer) no route for this URL');
	}

	/**
	 * @param string|null $sUrlPrefix Only the requests whose URL starts with this prefix
	 *
	 * @return array<int, array{url: string, data: array, headers: mixed, curl_options: array}> In the order they were sent
	 */
	public function GetRequests(?string $sUrlPrefix = null): array
	{
		if ($sUrlPrefix === null) {
			return $this->aRequests;
		}

		return array_values(array_filter($this->aRequests, function ($aRequest) use ($sUrlPrefix) {
			return strpos($aRequest['url'], $sUrlPrefix) === 0;
		}));
	}
}
