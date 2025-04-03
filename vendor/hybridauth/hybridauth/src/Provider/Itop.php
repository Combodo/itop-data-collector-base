<?php
/*!
* Hybridauth
* https://hybridauth.github.io | https://github.com/hybridauth/hybridauth
*  (c) 2020 Hybridauth authors | https://hybridauth.github.io/license.html
*/

namespace Hybridauth\Provider;

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data\Collection;
use Hybridauth\Exception\Exception;
use Hybridauth\Exception\InvalidApplicationCredentialsException;
use Hybridauth\Exception\UnexpectedApiResponseException;
use Hybridauth\User\Profile;

/**
 * Itop Oauth2 Connect provider adapter.
 *
 * Example:
 *         'Itop' => [
 *             'enabled' => true,
 *             'url' => 'your-itop-url', // depending on your setup you might need to add '/auth'
 *             'realm' => 'your-realm',
 *              'environnement' => 'your-environnement', //default is production
 *             'keys' => [
 *                 'id' => 'client-id',
 *                 'secret' => 'client-secret'
 *             ],
 * 				'consent' => true, //default is consent mode enabled
 *         ]
 *
 */
class Itop extends OAuth2
{
	/**
	 * {@inheritdoc}
	 */
	protected $scope = 'REST/JSON Synchro Oauth2/GetUser';

	/**
	 * {@inheritdoc}
	 */
	protected $apiDocumentation = 'https://www.itophub.io/wiki/page?id=start';
	private string $version = "1.3";
	private string $environnement = "production";
	protected $tokenExchangeMethod = 'POST';
	protected string $authentTokenBaseUrl;
	protected bool $consentRequired = true;

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

		if ($this->config->exists('version')) {
			$sVersion = $this->config->get('version');
			if (strlen($sVersion) != 0){
				$this->version = $sVersion;
			}
		}

		if ($this->config->exists('environnement')) {
			$sEnv = $this->config->get('environnement');
			if (strlen($sEnv) != 0){
				$this->environnement = $sEnv;
			}
		}

        if ($this->config->exists('consent')) {
            $this->consentRequired = $this->config->get('consent');
        }

		$this->apiBaseUrl = $url;


		$this->authentTokenBaseUrl = sprintf('%s/pages/exec.php?exec_module=authent-token&exec_env=%s&exec_page=', $url, $this->environnement);
		$this->authorizeUrl = $this->authentTokenBaseUrl."authorize.php";
		$this->accessTokenUrl = $this->authentTokenBaseUrl.'token.php';
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
            'prompt' => $this->consentRequired ? 'consent' : 'noconsent'
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
        if ($this->consentRequired){
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
            $this->validateApiResponse('Unable to reach authorize endpoint in no consent form mode');
            $data = (new \Hybridauth\Data\Parser())->parse($response);

            $collection = new \Hybridauth\Data\Collection($data);

            if (!$collection->exists('code')) {
                throw new InvalidAccessTokenException(
                    'Provider returned no code: ' . htmlentities($response)
                );
            }

            $response = $this->exchangeCodeForAccessToken($collection->get('code'));

            var_dump($response);
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
		$response = $this->apiRequest($this->authentTokenBaseUrl.'get_user.php', 'POST');

		$data = new Collection($response);

		$userProfile = new Profile();

		$userProfile->email = $data->get('email');
		$userProfile->firstName = $data->get('firstName');
		$userProfile->lastName = $data->get('lastName');
		$userProfile->displayName = $data->get('displayName');
		$userProfile->identifier = $data->get('identifier');
		$userProfile->displayName = $data->get('language');

		// Collect organization claim if provided in the IDToken
		if ($data->exists('organization')) {
			$userProfile->data['organization'] = $data->get('organization');
		}

		return $userProfile;
	}
}
