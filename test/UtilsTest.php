<?php

namespace UnitTestFiles\Test;

use PHPUnit\Framework\TestCase;

@define('APPROOT', dirname(__FILE__, 2).'/');

require_once(APPROOT.'core/utils.class.inc.php');

class UtilsTest extends TestCase
{
	public function ComputeCurlOptionsProvider(){
		return [
			'with timeout' => [
				'aExpectedReturnedOptions' => [
					32 => 3,
					78 => 600,
					13 => 600,
					10022 => 'itop',
					96 => true,
				],
				'iTimeout' => 600
			],
			'without timeout' => [
				'aExpectedReturnedOptions' => [
					32 => 3,
					10022 => 'itop',
					96 => true,
				],
				'iTimeout' => -1
			],
		];
	}

	/**
	 * @dataProvider ComputeCurlOptionsProvider
	 */
	public function testComputeCurlOptions($aExpectedReturnedOptions, $iTimeout){
		$aRawCurlOptions = [
			CURLOPT_SSLVERSION => CURL_SSLVERSION_SSLv3,
			'CURLOPT_COOKIE' => 'itop',
			CURLOPT_COOKIESESSION => true
		];

		$aCurlOptions = \Utils::ComputeCurlOptions($aRawCurlOptions, $iTimeout);

		$this->assertEquals($aExpectedReturnedOptions, $aCurlOptions);
	}

	public function setUp(): void
	{
		parent::setUp();
	}
}
