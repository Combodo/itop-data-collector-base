<?php

/**
 * @since 1.3.0
 */
class CallItopService
{
	public function CallItopViaHttp($sUri, $aAdditionalData, $iTimeOut = -1)
	{

		$sUrl = Utils::GetConfigurationValue('itop_url', '').$sUri;

		$aData = array_merge(
			Utils::GetCredentials(),
			$aAdditionalData
		);

		// timeout in seconds, for a synchro to run
		$iCurrentTimeOut = ($iTimeOut === -1) ? (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600) : $iTimeOut;
		$aCurlOptions = Utils::GetCurlOptions($iCurrentTimeOut);

		$aResponseHeaders = null;
		return Utils::DoPostRequest($sUrl, $aData, '', $aResponseHeaders, $aCurlOptions);
	}
}
