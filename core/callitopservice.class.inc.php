<?php

class CallItopService
{
	public function CallItopViaHttp($sUri, $aAdditionalData, $iTimeOut = -1)
	{
		$iCurrentTimeOut = ($iTimeOut !== -1) ? $iTimeOut : (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600); // timeout in seconds, for a synchro to run

		$sUrl = Utils::GetConfigurationValue('itop_url', '').$sUri;

		$aResponseHeaders = null;

		$aData = array_merge(
			array(
				'auth_user' => Utils::GetConfigurationValue('itop_login', ''),
				'auth_pwd' => Utils::GetConfigurationValue('itop_password', ''),
			),
			$aAdditionalData
		);

		$aRawCurlOptions = Utils::GetConfigurationValue('curl_options', array(CURLOPT_SSLVERSION => CURL_SSLVERSION_SSLv3));
		$aCurlOptions = array();
		foreach ($aRawCurlOptions as $key => $value) {
			// Convert strings like 'CURLOPT_SSLVERSION' to the value of the corresponding define i.e CURLOPT_SSLVERSION = 32 !
			$iKey = (!is_numeric($key)) ? constant((string)$key) : (int)$key;
			$iValue = (!is_numeric($value)) ? constant((string)$value) : (int)$value;
			$aCurlOptions[$iKey] = $iValue;
		}
		$aCurlOptions[CURLOPT_CONNECTTIMEOUT] = $iCurrentTimeOut;
		$aCurlOptions[CURLOPT_TIMEOUT] = $iCurrentTimeOut;

		return Utils::DoPostRequest($sUrl, $aData, '', $aResponseHeaders, $aCurlOptions);
	}
}
