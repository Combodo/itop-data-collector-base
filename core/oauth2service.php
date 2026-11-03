<?php

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Hybridauth;
use Hybridauth\Logger\Logger;
use Hybridauth\Storage\StorageImpl;

class OAuth2Service
{
	private string $sProvider;

	private ?OAuth2 $oAdapter = null;

	public function __construct(string $sProvider, ?OAuth2 $oMockedAdapter = null)
	{
		$this->sProvider = $sProvider;
		$this->oAdapter = $oMockedAdapter;
	}

	public function InitAndGetOauth2AccessToken(string $sLogin, string $sPassword): string
	{
		$aAuthorizePostedParameters = [
			'auth_user' => $sLogin,
			'auth_pwd' => $sPassword,
			'headless' => 'true',
		];

		$sOauthConfPath = $this->GetOauthCachePath();
		$oAdapter = $this->GetOauth2($aAuthorizePostedParameters);

		$oAdapter->authenticate();
		return $this->StoreTokensAndGetAccessToken($oAdapter, $sOauthConfPath);
	}

	public function GetOauth2AccessToken(bool $bForceRefresh = false): string
	{
		$sOauthConfPath = $this->GetOauthCachePath();
		if (is_file($sOauthConfPath)) {
			return $this->GetAccessTokenViaStoredCache($sOauthConfPath, $bForceRefresh);
		}

		throw new \Exception("Missing OAuth2 first negociation first. Please use toolkit/init_token.php");
	}

	public function GetConfiguration(?array $aAuthorizePostedParameters = null): array
	{
		$aConfig = Utils::GetConfigurationValue('hybridauth_oauth2', null);
		if (is_null($aConfig) || ! is_array($aConfig) || ! array_key_exists('providers', $aConfig)) {
			throw new \Exception("Invalid provider configuration".$this->sProvider);
		}

		if (! array_key_exists($this->sProvider, $aConfig['providers'])) {
			throw new \Exception(sprintf("Missing provider %s configuration", $this->sProvider).$this->sProvider);
		}

		if (! is_null($aAuthorizePostedParameters)) {
			$aConfig['providers'][$this->sProvider]['authorize_posted_parameters'] = $aAuthorizePostedParameters;
			if (array_key_exists('', $aConfig['providers'][$this->sProvider])) {
				$aConfig['providers'][$this->sProvider]['authorize_url_parameters'] += ["login_mode" => "form"];
			} else {
				$aConfig['providers'][$this->sProvider]['authorize_url_parameters'] = ["login_mode" => "form"];
			}
		}

		return $aConfig;
	}

	private function GetOauth2(?array $aAuthorizePostedParameters = null): OAuth2
	{
		if ($this->oAdapter) {
			return $this->oAdapter;
		}

		$aConfig = $this->GetConfiguration($aAuthorizePostedParameters);
		$aProviderConf = $aConfig['providers'][$this->sProvider] ?? [];
		$sHybridauthLogLevel = $aProviderConf['log_level'] ?? "none";
		if (! in_array($sHybridauthLogLevel, [Logger::DEBUG, Logger::INFO, Logger::ERROR])) {
			$sHybridauthLogLevel = "none";
		}

		Utils::Log(LOG_DEBUG, "Hybridauth (".$this->sProvider.") log level: ".$sHybridauthLogLevel);

		if (! array_key_exists('REMOTE_ADDR', $_SERVER)) {
			//avoid warning with logger
			$_SERVER['REMOTE_ADDR'] = "";
		}
		$oLogger = new Logger($sHybridauthLogLevel, APPROOT.'log/hybridauth.log');
		$oHybridauth = new Hybridauth($aConfig, null, new StorageImpl(), $oLogger);

		/** @var \Hybridauth\Adapter\OAuth2 $oAdapter */
		$oAdapter = $oHybridauth->getAdapter($this->sProvider);

		$this->oAdapter = $oAdapter;
		return $this->oAdapter;
	}

	private function GetAccessTokenViaStoredCache(string $sOauthConfPath, bool $bForceRefresh = false)
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
		if (! $bForceRefresh && $oAdapter->hasAccessTokenExpired() !== true) {
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

		$sTokenData = json_encode($aTokenData, JSON_THROW_ON_ERROR);
		if (file_put_contents($sOauthConfPath, $sTokenData) !== strlen($sTokenData)) {
			throw new \Exception(sprintf("Failed to persist OAuth2 tokens to %s", $sOauthConfPath));
		}
		return $aTokenData['access_token'];
	}

	public function GetOauthCachePath(): string
	{
		return Utils::GetDataFilePath(str_replace(' ', '', $this->sProvider."_Oauth2.json"));
	}
}
