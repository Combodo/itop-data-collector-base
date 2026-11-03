<?php

/*!
* Hybridauth
* https://hybridauth.github.io | https://github.com/hybridauth/hybridauth
*  (c) 2020 Hybridauth authors | https://hybridauth.github.io/license.html
*/

namespace Hybridauth\Provider;

use Exception;
use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data\Collection;
use Hybridauth\Exception\InvalidAccessTokenException;
use Hybridauth\Exception\InvalidApplicationCredentialsException;
use Hybridauth\User\Profile;

class Itop extends OAuth2
{
	/**
	 * {@inheritdoc}
	 */
	protected $scope = 'REST/JSON Synchro OAuth2/SSO';

	/**
	 * {@inheritdoc}
	 */
	protected $apiDocumentation = 'https://www.itophub.io/wiki/page?id=start';
	private string $version = "1.3";
	protected $tokenExchangeMethod = 'POST';
	protected string $oAuth2ServerBaseUrl;
	protected bool $headless = false;

	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
		parent::configure();
		if (!$this->config->exists('url')) {
			throw new InvalidApplicationCredentialsException(
				'You must define a provider url'
			);
		}
		$url = $this->config->get('url');

		if ($this->config->exists('headless')) {
			$headless = $this->config->get('headless');
			$this->headless = (trim("$headless") === "true");
		}

		if ($this->config->exists('scope')) {
			$scope = trim($this->config->get('scope'));
			if (($scope !== "")) {
				$this->scope = $scope;
			}
		}

		$this->apiBaseUrl = $url;

		$this->oAuth2ServerBaseUrl = sprintf('%s/pages/exec.php?exec_module=authent-oauth2-server&exec_env=production&exec_page=', $url);
		$this->authorizeUrl = $this->oAuth2ServerBaseUrl."authorize.php";
		$this->accessTokenUrl = $this->oAuth2ServerBaseUrl.'token.php';
	}

	protected function getAuthorizeUrl($parameters = [])
	{
		$url = parent::getAuthorizeUrl($parameters);
		$iPos = strrpos($url, '?');
		return substr_replace($url, '&', $iPos, 1);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function initialize()
	{
		parent::initialize();

		$this->AuthorizeUrlParameters += [
			'headless' => $this->headless ? 'true' : 'false',
		];

		$this->tokenExchangeParameters = [
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
			'grant_type' => 'authorization_code',
			'redirect_uri' => $this->callback,
		];

		$refreshToken = $this->getStoredData('refresh_token');
		if (!empty($refreshToken)) {
			$this->tokenRefreshParameters = [
				'client_id' => $this->clientId,
				'client_secret' => $this->clientSecret,
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
				'redirect_uri' => $this->callback,
			];
		}

		$this->apiRequestParameters = [
			'version' => $this->version,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function authenticate()
	{
		if (! $this->headless) {
			return parent::authenticate();
		}

		$this->logger->info(sprintf('%s::authenticate()', get_class($this)));

		if ($this->isConnected()) {
			return true;
		}

		try {
			$authUrl = $this->getAuthorizeUrl();
			$this->logger->debug(sprintf('%s::authenticateBegin(), redirecting user to:', get_class($this)), [$authUrl]);

			$response = $this->httpClient->request(
				$authUrl,
				'POST',
				$this->AuthorizeUrlParameters,
				$this->tokenExchangeHeaders
			);
			$this->validateApiResponse('Unable to reach authorize endpoint in headless mode');
			$data = (new \Hybridauth\Data\Parser())->parse($response);

			$collection = new \Hybridauth\Data\Collection($data);

			if (!$collection->exists('code')) {
				throw new InvalidAccessTokenException(
					'Provider returned no code: '.htmlentities($response)
				);
			}

			$response = $this->exchangeCodeForAccessToken($collection->get('code'));

			$this->validateAccessTokenExchange($response);

			$this->initialize();

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
		$response = $this->apiRequest($this->oAuth2ServerBaseUrl.'get_user.php', 'POST');

		$data = new Collection($response);

		$userProfile = new Profile();

		$userProfile->email = $data->get('email');
		$userProfile->firstName = $data->get('firstName');
		$userProfile->lastName = $data->get('lastName');
		$userProfile->displayName = $data->get('displayName');
		$userProfile->identifier = $data->get('identifier');
		$userProfile->language = $data->get('language');

		// Collect organization claim if provided in the IDToken
		if ($data->exists('organization')) {
			$userProfile->data['organization'] = $data->get('organization');
		}

		if ($data->exists('organizationCode')) {
			$userProfile->data['organizationCode'] = $data->get('organizationCode');
		}

		if ($data->exists('allowedOrgs')) {
			$userProfile->data['allowedOrgs'] = $data->get('allowedOrgs');
		}

		if ($data->exists('login')) {
			$userProfile->data['login'] = $data->get('login');
		}

		$groupsKey = $this->config->get('groups_key') ?? 'groups';
		if ($data->exists($groupsKey)) {
			$userProfile->data['groups'] = $data->get($groupsKey);
		}

		return $userProfile;
	}
}
