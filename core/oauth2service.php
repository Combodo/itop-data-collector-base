<?php

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Hybridauth;
use Hybridauth\Storage\StorageImpl;

class OAuth2Service
{
	private string $sProvider;

	//Used for testing purpose only
	private ?OAuth2 $oMockedAdapter = null;

	public function __construct(string $sProvider, ?OAuth2 $oMockedAdapter = null)
	{
		$this->sProvider = $sProvider;
		$this->oMockedAdapter = $oMockedAdapter;
	}

	public function GetOauth2AccessToken(?array $aAuthorizeUrlParameters = null): string
	{
		$sOauthConfPath = $this->GetOauthCachePath();
		if (is_null($aAuthorizeUrlParameters)) {
			if (is_file($sOauthConfPath)) {
				return $this->GetAccessTokenViaStoredCache($sOauthConfPath);
			}

			throw new \Exception("Missing OAuth2 first negociation first. Please use toolkit/init_token.php");
		}

		$oAdapter = $this->GetOauth2($aAuthorizeUrlParameters);
		$oAdapter->authenticate();
		return $this->StoreTokensAndGetAccessToken($oAdapter, $sOauthConfPath);
	}

	public function GetConfiguration(?array $aAuthorizeUrlParameters = null): array
	{
		$aConfig = Utils::GetConfigurationValue('hybridauth_oauth2', null);
		if (is_null($aConfig) || ! is_array($aConfig) | ! array_key_exists('providers', $aConfig)) {
			throw new \Exception("Invalid provider configuration".$this->sProvider);
		}

		if (! array_key_exists($this->sProvider, $aConfig['providers'])) {
			throw new \Exception(sprintf("Missing provider %s configuration", $this->sProvider).$this->sProvider);
		}

		if (! is_null($aAuthorizeUrlParameters)) {
			$aConfig['providers'][$this->sProvider]['authorize_url_parameters'] = $aAuthorizeUrlParameters;
		}

		return $aConfig;
	}

	private function GetOauth2(?array $aAuthorizeUrlParameters = null): OAuth2
	{
		if ($this->oMockedAdapter) {
			return $this->oMockedAdapter;
		}

		$aConfig = $this->GetConfiguration($aAuthorizeUrlParameters);

		$oHybridauth = new Hybridauth($aConfig, null, new StorageImpl(), new HybridauthLoggerWrapperImpl());

		/** @var \Hybridauth\Adapter\OAuth2 $oAdapter */
		$oAdapter = $oHybridauth->getAdapter($this->sProvider);
		return $oAdapter;
	}

	private function GetAccessTokenViaStoredCache(string $sOauthConfPath)
	{
		$sContent = file_get_contents($sOauthConfPath);
		$aTokenData = json_decode($sContent, true);

		if (is_null($aTokenData) || ! is_array($aTokenData)) {
			throw new \Exception(sprintf("Inconsistent last OAuth2 cached response %s. Please use toolkit/init_token.php to fix it", $sOauthConfPath));
		}

		if (! array_key_exists('access_token', $aTokenData)) {
			throw new \Exception(sprintf("Missing access_token in last OAuth2 cached response %s. Please use toolkit/init_token.php to fix it", $sOauthConfPath));
		}

		$oAdapter = $this->GetOauth2();
		$oAdapter->setAccessToken($aTokenData);
		$oAdapter->maintainToken();
		if ($oAdapter->hasAccessTokenExpired() !== true) {
			return $aTokenData['access_token'];
		}

		$oAdapter->refreshAccessToken();
		return $this->StoreTokensAndGetAccessToken($oAdapter, $sOauthConfPath);
	}

	public function StoreTokensAndGetAccessToken(OAuth2 $oAdapter, string $sOauthConfPath): string
	{
		$aTokenData = $oAdapter->getAccessToken();
		if (! array_key_exists('access_token', $aTokenData)) {
			throw new \Exception(sprintf("Missing access_token in provider %s response", $this->sProvider));
		}

		file_put_contents($sOauthConfPath, json_encode($aTokenData));
		return $aTokenData['access_token'];
	}

	public function GetOauthCachePath(): string
	{
		return Utils::GetDataFilePath(str_replace(' ', '', $this->sProvider."_Oauth2.json"));
	}
}
