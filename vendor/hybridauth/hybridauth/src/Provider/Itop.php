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
	protected string $auth2ServerBaseUrl;
	protected bool $headless = false;
	protected string $uiLogoutUrl;

    /**
     * Authorization POSTED Parameters
     *
     * @var array
     */
    protected $authorizePostedParameters = [];
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

        if ($this->config->exists('authorize_posted_parameters')) {
            $this->authorizePostedParameters = (array)$this->config->get('authorize_posted_parameters');
        }

		$this->apiBaseUrl = $url;

		$this->auth2ServerBaseUrl = sprintf('%s/pages/exec.php?exec_module=authent-oauth2-server&exec_env=production&exec_page=', $url);
		$this->authorizeUrl = $this->auth2ServerBaseUrl."authorize.php";
		$this->accessTokenUrl = $this->auth2ServerBaseUrl.'token.php';
		$this->uiLogoutUrl = $this->auth2ServerBaseUrl.'ui_logout.php';
		$this->isLogoutRequiredBeforeDisconnect = true;
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
				$this->authorizePostedParameters,
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

		$data = (new Collection($response))->toArray(true);

		$userProfile = new Profile();

		$userProfile->email = $data['email'];
		$userProfile->firstName = $data['firstName'];
		$userProfile->lastName = $data['lastName'];
		$userProfile->displayName = $data['displayName'];
		$userProfile->identifier = $data['identifier'];
		$userProfile->language = $data['language'];

		// Collect organization claim if provided in the IDToken
		if (isset($data['organization'])) {
			$userProfile->data['organization'] = $data['organization'];
		}

		if (isset($data['organizationCode'])) {
			$userProfile->data['organizationCode'] = $data['organizationCode'];
		}

		if (isset($data['allowedOrgs'])) {
			$receivedAllowedOrgs = $data['allowedOrgs'];
			$allowedOrgs = [];
			if (is_array($receivedAllowedOrgs)) {
				foreach ($receivedAllowedOrgs as $receivedAllowedOrg) {
					$orgName = trim($receivedAllowedOrg->name ?? "");
					if (strlen($orgName) > 0) {
						$allowedOrgs[] = $orgName;
					}
				}
			}
			$userProfile->data['allowedOrgs'] = $allowedOrgs;
		}

		if (isset($data['login'])) {
			$userProfile->data['login'] = $data['login'];
		}

		$groupsKey = $this->config->get('groups_key') ?? 'groups';
		if (isset($data[$groupsKey])) {
			$userProfile->data['groups'] = $data[$groupsKey];
		}

		return $userProfile;
	}

	public function logout()
	{
		//LoginWebPage::ResetSession();
		header('HTTP/1.1 307 Temporary Redirect');
		header('Location: '.$this->uiLogoutUrl);
		exit;
	}
}
