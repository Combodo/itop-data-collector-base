<?php
/*!
* Hybridauth
* https://hybridauth.github.io | https://github.com/hybridauth/hybridauth
*  (c) 2020 Hybridauth authors | https://hybridauth.github.io/license.html
*/

namespace Hybridauth\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Exception\Exception;
use Hybridauth\Exception\InvalidApplicationCredentialsException;

/**
 * Itop OAuth2 Identity Provider adapter.
 * This provider is not interactive, the User's credentials are given during the connection along with application credentials
 */
class Headless extends OAuth2
{
	/**
	 * {@inheritdoc}
	 */
	protected $scope = 'account_info.read';

	/**
	 * {@inheritdoc}
	 */
	protected $apiDocumentation = 'https://www.itophub.io/wiki/page?id=start';
	private string $username;
	private string $password;
	private string $version = "1.3";

	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{

		if (!$this->config->exists('url')) {
			throw new InvalidApplicationCredentialsException(
				'You must define a provider url'
			);
		}
		$url = $this->config->get('url');

		if (!$this->config->exists('username')) {
			throw new InvalidApplicationCredentialsException(
				'You must define a provider username'
			);
		}
		$this->username = $this->config->get('username');

		if (!$this->config->exists('password')) {
			throw new InvalidApplicationCredentialsException(
				'You must define a provider password'
			);
		}
		$this->password = $this->config->get('password');

		parent::configure();

		if ($this->config->exists('version')) {
			$this->version = $this->config->get('version');
		}

		$this->apiBaseUrl = $url;

		$this->authorizeUrl = $url.'/pages/exec.php?exec_module=authent-oauth&exec_page=auth.php';
		$this->accessTokenUrl = $this->authorizeUrl;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function initialize()
	{
		$this->tokenExchangeParameters = [
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
			'username' => $this->username,
			'password' => $this->password,
			'grant_type' => 'password',
			'redirect_uri' => $this->callback,
		];

		$refreshToken = $this->getStoredData('refresh_token');
		if (!empty($refreshToken)) {
			$this->tokenRefreshParameters = [
				'client_id' => $this->clientId,
				'client_secret' => $this->clientSecret,
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
			];
		}

		$this->apiRequestHeaders = [
			'Content-Type' => 'application/json',
		];

		$this->apiRequestParameters = [
			'version' => $this->version,
		];

		$this->tokenExchangeHeaders = [
			'Content-Type' => 'application/json',
		];

		$this->tokenRefreshHeaders = [
			'Content-Type' => 'application/json',
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function authenticate()
	{
		$this->logger->info(sprintf('%s::authenticate()', get_class($this)));

		if ($this->isConnected()) {
			return true;
		}

		try {
			$response = $this->exchangeCodeForAccessToken('');

			$this->validateAccessTokenExchange($response);

		} catch (Exception $e) {
			$this->clearStoredData();

			throw $e;
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUserProfile()
	{
		// Not implemented with this kind of connection
		return [];
	}
}
