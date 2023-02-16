<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;
use Collector;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/collector.class.inc.php');

class CallItopServiceTest extends TestCase
{
	/** @var \CallItopService $oCallItopService */
	private $oCallItopService;

	public function testGetCurlOptions(){
		$aRawCurlOptions = [
			CURLOPT_SSLVERSION => CURL_SSLVERSION_SSLv3,
			CURLOPT_COOKIE => 'itop',
			CURLOPT_COOKIESESSION => true
		];
		$aCurlOptions = $this->oCallItopService->GetCurlOptions($aRawCurlOptions, 600);

		$aExpectedOptions = [
			32 => 3,
		    78 => 600,
		    13 => 600,
			10022 => 'itop',
            96 => true,
		];

		$this->assertEquals($aExpectedOptions, $aCurlOptions);
	}

	public function setUp(): void
	{
		parent::setUp();

		$this->oCallItopService = new \CallItopService();
	}
}
