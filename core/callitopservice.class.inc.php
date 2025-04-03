<?php

require_once(APPROOT.'vendor/autoload.php');
require_once(__DIR__.'/HybridauthLoggerWrapperImpl.php');
/**
 * @since 1.3.0
 */
class CallItopService
{
	public function CallItopViaHttp($sUri, $aAdditionalData, $iTimeOut = -1, &$aResponseHeaders = null)
	{
		if (Utils::UseOauth2()){
			$aData = $aAdditionalData;
			$sOptionnalHeaders = sprintf('Authorization: Bearer %s', $this->GetOauth2AccessToken());
		} else {
			$aData = array_merge(
				Utils::GetCredentials(),
				$aAdditionalData
			);
			$sOptionnalHeaders = '';
		}

		$sUrl = Utils::GetConfigurationValue('itop_url', '').$sUri;

		// timeout in seconds, for a synchro to run
		$iCurrentTimeOut = ($iTimeOut === -1) ? (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600) : $iTimeOut;
		$aCurlOptions = Utils::GetCurlOptions($iCurrentTimeOut);

		if (is_null($aResponseHeaders)){
			$aResponseHeaders=[];
		}

		$sResponse = Utils::DoPostRequest($sUrl, $aData, $sOptionnalHeaders, $aResponseHeaders, $aCurlOptions);
		if (false !== strpos($sResponse, 'Invalid login') &&
			Utils::UseOauth2()
		){
			//token may have expired: try refresh and re-post
			$sOptionnalHeaders = sprintf('Authorization: Bearer %s', $this->GetOauth2AccessToken(true));
			$sResponse = Utils::DoPostRequest($sUrl, $aData, $sOptionnalHeaders, $aResponseHeaders, $aCurlOptions);
		}

		return $sResponse;
	}

	public function GetOauth2AccessToken(bool $bForceRefresh=false, $sProvider="Itop") : string
	{
		$sOauthConfPath = $this->GetOauthCachePath($sProvider);
		if (is_file($sOauthConfPath)){
			return $this->GetAccessTokenViaStoredCache($sProvider, $sOauthConfPath, $bForceRefresh);
		}

		$oAdapter = $this->GetOauth2($sProvider);
		$oAdapter->authenticate();
		return $this->StoreTokensAndGetAccessToken($oAdapter, $sOauthConfPath);
	}

	public function StoreTokensAndGetAccessToken(\Hybridauth\Adapter\OAuth2 $oAdapter, string $sOauthConfPath) : string
	{
		$aTokenData = $oAdapter->getAccessToken();
		file_put_contents($sOauthConfPath, json_encode($aTokenData));
		return $aTokenData['access_token'] ?? '';
	}

	private function GetOauth2(string $sProvider) : \Hybridauth\Adapter\OAuth2
	{
		$aConfig = Utils::GetOauth2Config($sProvider);
		$oHybridauth = new \Hybridauth\Hybridauth($aConfig, null, new \Hybridauth\Storage\StorageImpl(), new HybridauthLoggerWrapperImpl());

		/** @var \Hybridauth\Adapter\OAuth2 $oAdapter */
		$oAdapter = $oHybridauth->getAdapter($sProvider);
		return $oAdapter;
	}

	private function GetOauthCachePath(string $sProvider) : string {
		return Utils::GetDataFilePath("$sProvider-Oauth2.json");
	}

	private function GetAccessTokenViaStoredCache(string $sProvider, string $sOauthConfPath, bool $bForceRefresh=false) {
		$sContent = file_get_contents($sOauthConfPath);
		$aTokenData = json_decode($sContent, true);
		if (! is_array($aTokenData)){
			throw new Exception("Invalid JSON content ($sOauthConfPath): \n$sContent");
		}

		if (! array_key_exists('access_token', $aTokenData)){
			throw new Exception("Missing access_token ($sOauthConfPath): \n$sContent");
		}

		$oAdapter = $this->GetOauth2($sProvider);
		$oAdapter->setAccessToken($aTokenData);
		$oAdapter->maintainToken();
		if (!$bForceRefresh && $oAdapter->hasAccessTokenExpired() !== true) {
			return $aTokenData['access_token'];
		}

		$oAdapter->refreshAccessToken();
		return $this->StoreTokensAndGetAccessToken($oAdapter, $sOauthConfPath);
	}
}
