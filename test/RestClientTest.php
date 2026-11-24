<?php

namespace UnitTestFiles\Test;

use Exception;
use LookupTable;
use RestClient;
use UnitTestFiles\Test\Support\BaseTestCase;
use UnitTestFiles\Test\Support\FakeItop;

require_once __DIR__.'/Support/BaseTestCase.php';

/**
 * What RestClient sends to iTop's REST API and how it reads the answers.
 */
class RestClientTest extends BaseTestCase
{
	/**
	 * @return array The decoded json_data of the last request
	 */
	private function GetLastOperation(): array
	{
		$aRequests = $this->GetFakeHttpServer()->GetRequests();

		return json_decode(end($aRequests)['data']['json_data'], true);
	}

	public function testOperationsArePostedWithTheCredentialsTheLoginModeAndTheVersion(): void
	{
		$this->StartFakeItop();

		(new RestClient())->Get('Organization', ['name' => 'Demo'], 'name,code', 5);

		$aRequest = $this->GetFakeHttpServer()->GetRequests()[0];
		$this->assertSame(FakeItop::URL.'/webservices/rest.php?login_mode=form&version=1.0', $aRequest['url']);
		$this->assertSame(['auth_user', 'auth_pwd', 'json_data'], array_keys($aRequest['data']));
		$this->assertSame(
			['operation' => 'core/get', 'class' => 'Organization', 'key' => ['name' => 'Demo'], 'output_fields' => 'name,code', 'limit' => 5],
			$this->GetLastOperation()
		);
	}

	public function testATrailingSlashInTheUrlIsKept(): void
	{
		$this->StartFakeItop();
		$this->SetConfigurationOverrides(['itop_url' => FakeItop::URL.'/']);
		$this->GetFakeHttpServer()->Route(FakeItop::URL.'//', function () {
			return json_encode(['code' => 0, 'message' => '', 'objects' => null]);
		});

		(new RestClient())->Get('Organization', 1);

		$this->assertSame(FakeItop::URL.'//webservices/rest.php?login_mode=form&version=1.0', $this->GetFakeHttpServer()->GetRequests()[0]['url']);
	}

	public function testCreateUpdateAndCheckCredentialsPayloads(): void
	{
		$this->StartFakeItop();
		$oClient = new RestClient();
		$oClient->SetVersion('1.3');

		$oClient->Create('Organization', ['name' => 'Demo'], 'my comment');
		$this->assertSame(['operation' => 'core/create', 'class' => 'Organization', 'output_fields' => '*', 'fields' => ['name' => 'Demo'], 'comment' => 'my comment'], $this->GetLastOperation());

		$oClient->Update('Organization', ['name' => 'Demo'], ['code' => 'D'], 'my comment');
		$this->assertSame(['operation' => 'core/update', 'class' => 'Organization', 'key' => ['name' => 'Demo'], 'fields' => ['code' => 'D'], 'output_fields' => '*', 'comment' => 'my comment'], $this->GetLastOperation());

		$aResult = $oClient->CheckCredentials(FakeItop::LOGIN, FakeItop::PASSWORD);
		$this->assertSame(['operation' => 'core/check_credentials', 'user' => FakeItop::LOGIN, 'password' => FakeItop::PASSWORD], $this->GetLastOperation());
		$this->assertTrue($aResult['authorized']);

		$this->assertStringEndsWith('version=1.3', $this->GetFakeHttpServer()->GetRequests()[0]['url']);
	}

	public function testNewestKnownVersionIsDetectedFromTheNewestDown(): void
	{
		$oItop = $this->StartFakeItop();
		$oItop->SetRestVersions(['1.0', '1.1', '1.2']);

		$this->assertSame('1.2', RestClient::GetNewestKnownVersion());
		$this->assertSame(['1.4', '1.3', '1.2'], array_map(function ($aRequest) {
			parse_str(parse_url($aRequest['url'], PHP_URL_QUERY), $aQuery);

			return $aQuery['version'];
		}, $oItop->GetRequests('rest')));
	}

	public function testNoKnownVersionIsAnError(): void
	{
		$oItop = $this->StartFakeItop();
		$oItop->SetRestVersions(['2.0']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Unable to detect an iTop API.');

		RestClient::GetNewestKnownVersion();
	}

	public function testAnswerThatIsNotJsonIsAnError(): void
	{
		$this->SetConfigurationOverrides(['itop_url' => 'http://not-itop.test']);
		$this->GetFakeHttpServer()->Route('http://not-itop.test', function () {
			return '<html>Login page</html>';
		});

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('rest.php replied: <html>Login page</html>');

		(new RestClient())->Get('Organization', 1);
	}

	public function testFullSynchroDataSourceReplacesTheAttributesWithTheirWholeDefinition(): void
	{
		$oItop = $this->StartFakeItop();
		$this->WriteSynchroDataSourceDefinition('Whatever');
		$aCreated = (new RestClient())->Create('SynchroDataSource', ['name' => 'Source', 'scope_class' => 'Organization'], '');
		$iSourceId = reset($aCreated['objects'])['key'];
		$aSource = reset($aCreated['objects'])['fields'];
		$this->assertSame(['attcode' => 'parent_id', 'update' => '1', 'reconcile' => '0', 'update_policy' => 'master_locked', 'finalclass' => 'SynchroAttExtKey', 'friendlyname' => 'parent_id'], $aSource['attribute_list'][3], 'What iTop returns in the link set');

		$this->assertTrue(RestClient::GetFullSynchroDataSource($aSource, $iSourceId));

		$this->assertSame(['attcode' => 'parent_id', 'update' => '1', 'reconcile' => '0', 'update_policy' => 'master_locked', 'reconciliation_attcode' => '', 'finalclass' => 'SynchroAttExtKey'], $aSource['attribute_list'][3], 'Read-only fields removed, sub-class fields added');
		$this->assertArrayNotHasKey('friendlyname', $aSource);
		$this->assertArrayNotHasKey('user_id_friendlyname', $aSource);
		$this->assertCount(2, $oItop->GetRestOperations('core/get'), 'One query per class of attribute');
	}

	public function testLookupTableQueriesItopWithOql(): void
	{
		$oItop = $this->StartFakeItop();
		$iDemo = $oItop->AddObject('Organization', ['name' => 'Demo', 'code' => 'D']);
		$oItop->AddObject('Organization', ['name' => 'Other', 'code' => 'O']);

		$oLookup = new LookupTable("SELECT Organization WHERE code IN ('D', 'X')", ['name']);
		$aLine = ['primary_key', 'org'];
		$oLookup->Lookup($aLine, ['org'], 'org', 0);
		$aLine = ['1', 'Demo'];
		$this->assertTrue($oLookup->Lookup($aLine, ['org'], 'org', 1));

		$this->assertSame(['1', $iDemo], $aLine, 'The id is put in the line as an int');
		$this->assertSame(
			['operation' => 'core/get', 'class' => 'Organization', 'key' => "SELECT Organization WHERE code IN ('D', 'X')", 'output_fields' => 'name', 'limit' => 0],
			$this->GetLastOperation()
		);
	}
}
