<?php

namespace UnitTestFiles\Test\OAuth2;

use Hybridauth\Adapter\OAuth2;
use PHPUnit\Framework\TestCase;

@define('APPROOT', dirname(__FILE__, 3).'/');

require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');

class OAuth2ServiceTest extends TestCase
{
	public function testGetItopConfiguration()
	{
		$aConf = (new \OAuth2Service("Itop"))->GetConfiguration();

		$expected = [
			'providers' => [
				"Itop" => [
					'enabled' => 'true',
					'headless' => 'true',
					'url' => "https://localhost/iTop",
					'keys' => [
						'id' => "ID",
						'secret' => "SECRET",
					],
					'callback' => 'https://mycollector.org',
					'scope' => "REST/JSON,Synchro",
					'debug' => "false",
				],
			],
		];
		self::assertEquals($expected, $aConf);
	}

	public function testGetItopConfigurationToInitToken()
	{
		$aAuthorizeUrlParameters = [
			'auth_user' => 'AUTH_USER',
			'auth_pwd' => 'AUTH_PWD',
			'response_type' => 'code',
		];

		$aConf = (new \OAuth2Service("Itop"))->GetConfiguration($aAuthorizeUrlParameters);

		$expected = [
			'providers' => [
				"Itop" => [
					'enabled' => 'true',
					'headless' => 'true',
					'url' => "https://localhost/iTop",
					'keys' => [
						'id' => "ID",
						'secret' => "SECRET",
					],
					'callback' => 'https://mycollector.org',
					'scope' => "REST/JSON,Synchro",
					'debug' => "false",
					'authorize_url_parameters' => $aAuthorizeUrlParameters,
				],
			],
		];
		self::assertEquals($expected, $aConf);
	}

	public function testInitSuccessfull()
	{
		$oMockedOauth2 = $this->createMock(OAuth2::class);
		$oOAuth2Service = new \OAuth2Service("Itop", $oMockedOauth2);

		$sPath = $oOAuth2Service->GetOauthCachePath();
		if (is_file($sPath)) {
			unlink($sPath);
		};

		$oMockedOauth2->expects(self::once())->method('authenticate');

		$aTokenData = [
			'access_token' => "ACCESSTOKEN123",
			'access_token_secret' => "ACCESSTOKENSECRET456",
			'token_type' => 'BEARER',
			'refresh_token' => "REFRESHTOKEN789",
			'expires_in' => 666,
			'expires_at' => time() + 666,
		];
		$oMockedOauth2->expects(self::once())->method('getAccessToken')
			->willReturn($aTokenData);

		$aAuthorizeUrlParameters = [
			'auth_user' => "LOGIN",
			'auth_pwd' => "PWD",
			'response_type' => 'code',
		];

		self::assertEquals('ACCESSTOKEN123', $oOAuth2Service->GetOauth2AccessToken($aAuthorizeUrlParameters));
		self::assertEquals(json_encode($aTokenData), file_get_contents($sPath));
	}

	public function testGetAccessTokenInitSuccessfull()
	{
		$oMockedOauth2 = $this->createMock(OAuth2::class);
		$oOAuth2Service = new \OAuth2Service("Itop", $oMockedOauth2);

		$sPath = $oOAuth2Service->GetOauthCachePath();
		if (is_file($sPath)) {
			unlink($sPath);
		};

		$oMockedOauth2->expects(self::once())->method('authenticate');

		$aTokenData = $this->GivenOAuth2TokenTokenData();
		$oMockedOauth2->expects(self::once())->method('getAccessToken')
			->willReturn($aTokenData);

		$aAuthorizeUrlParameters = [
			'auth_user' => "LOGIN",
			'auth_pwd' => "PWD",
			'response_type' => 'code',
		];

		self::assertEquals('ACCESSTOKEN123', $oOAuth2Service->GetOauth2AccessToken($aAuthorizeUrlParameters));
		self::assertEquals(json_encode($aTokenData), file_get_contents($sPath));
	}

	public function testGetAccessTokenFailWithoutInitBefore()
	{
		$oMockedOauth2 = $this->createMock(OAuth2::class);
		$oOAuth2Service = new \OAuth2Service("Itop", $oMockedOauth2);

		$sPath = $oOAuth2Service->GetOauthCachePath();
		if (is_file($sPath)) {
			unlink($sPath);
		};

		$oMockedOauth2->expects(self::never())->method('authenticate');

		$this->expectExceptionMessage("Missing OAuth2 first negociation first. Please use toolkit/init_token.php");
		$oOAuth2Service->GetOauth2AccessToken();
	}

	public function testGetAccessTokenFromCache()
	{
		$oMockedOauth2 = $this->createMock(OAuth2::class);
		$oOAuth2Service = new \OAuth2Service("Itop", $oMockedOauth2);

		$sPath = $oOAuth2Service->GetOauthCachePath();
		$aTokenData = $this->GivenOAuth2TokenTokenData();
		file_put_contents($sPath, json_encode($aTokenData));

		$oMockedOauth2->expects(self::never())->method('authenticate');
		$oMockedOauth2->expects(self::once())->method('setAccessToken')
			->with($aTokenData);
		$oMockedOauth2->expects(self::once())->method('maintainToken');

		$oMockedOauth2->expects(self::once())->method('hasAccessTokenExpired')
			->willReturn(false);

		$oMockedOauth2->expects(self::never())->method('refreshAccessToken');

		self::assertEquals("ACCESSTOKEN123", $oOAuth2Service->GetOauth2AccessToken());
	}

	public function testGetAccessTokenWithExpiredToken()
	{
		$oMockedOauth2 = $this->createMock(OAuth2::class);
		$oOAuth2Service = new \OAuth2Service("Itop", $oMockedOauth2);

		$sPath = $oOAuth2Service->GetOauthCachePath();
		$aTokenData = $this->GivenOAuth2TokenTokenData();
		file_put_contents($sPath, json_encode($aTokenData));

		$oMockedOauth2->expects(self::never())->method('authenticate');
		$oMockedOauth2->expects(self::once())->method('setAccessToken')
			->with($aTokenData);
		$oMockedOauth2->expects(self::once())->method('maintainToken');

		$oMockedOauth2->expects(self::once())->method('hasAccessTokenExpired')
			->willReturn(true);

		$aNewTokenData = $this->GivenOAuth2TokenTokenData();
		$aNewTokenData['access_token'] = 'ACCESSTOKEN123456';
		$oMockedOauth2->expects(self::once())->method('getAccessToken')
			->willReturn($aNewTokenData);

		$oMockedOauth2->expects(self::once())->method('refreshAccessToken');

		self::assertEquals("ACCESSTOKEN123456", $oOAuth2Service->GetOauth2AccessToken());
		self::assertEquals(json_encode($aNewTokenData), file_get_contents($sPath));
	}

	private function GivenOAuth2TokenTokenData(): array
	{
		return [
			'access_token' => "ACCESSTOKEN123",
			'access_token_secret' => "ACCESSTOKENSECRET123",
			'token_type' => 'BEARER',
			'refresh_token' => "REFRESHTOKEN123",
			'expires_in' => 666,
			'expires_at' => time() + 666,
		];
	}
}
