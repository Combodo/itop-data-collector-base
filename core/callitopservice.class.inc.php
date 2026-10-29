<?php

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Hybridauth;
use Hybridauth\Storage\StorageImpl;

require_once(__DIR__.'/oauth2service.php');
require_once(APPROOT.'vendor/autoload.php');
require_once(__DIR__.'/HybridauthLoggerWrapperImpl.php');

if (! defined('CURLINFO_RESPONSE_CODE')) {
	define('CURLINFO_RESPONSE_CODE', 2097154);
}

/**
 * @since 1.3.0
 */
class CallItopService
{
	public function CallItopViaHttp($sUri, $aAdditionalData, $iTimeOut = -1, &$aResponseHeaders = null)
	{
		$oOAuth2Service = Utils::GetItopOauth2Service();
		if ($oOAuth2Service) {
			Utils::Log(LOG_DEBUG, 'Oauth2 enabled');
			$aData = $aAdditionalData;
			$sOptionalHeaders = sprintf('Authorization: Bearer %s', $oOAuth2Service->GetOauth2AccessToken());
		} else {
			Utils::Log(LOG_DEBUG, 'Oauth2 disabled');
			$aData = array_merge(
				Utils::GetCredentials(),
				$aAdditionalData
			);
			$sOptionalHeaders = '';
		}

		$sUrl = Utils::GetConfigurationValue('itop_url', '').$sUri;

		$aCurlOptions = Utils::GetCurlOptions($iTimeOut);

		if (is_null($aResponseHeaders)) {
			$aResponseHeaders = [];
		}

		$sResponse = Utils::DoPostRequest($sUrl, $aData, $sOptionalHeaders, $aResponseHeaders, $aCurlOptions);

		if (is_null($oOAuth2Service)) {
			return $sResponse;
		}

		$iHttpCode = $aResponseHeaders[CURLINFO_RESPONSE_CODE] ?? 200;
		if ($iHttpCode === 498) {
			$aResponseHeaders = [];
			//token may have expired: try refresh and re-post
			$sOptionalHeaders = sprintf('Authorization: Bearer %s', $oOAuth2Service->GetOauth2AccessToken());
			return Utils::DoPostRequest($sUrl, $aData, $sOptionalHeaders, $aResponseHeaders, $aCurlOptions);
		}

		return $sResponse;
	}
}
