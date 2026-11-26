<?php

namespace UnitTestFiles\Test\Support;

use Exception;

/**
 * In-memory iTop for the tests. It answers the HTTP requests the framework sends to iTop the way iTop 3.2 does:
 * - webservices/rest.php: list_operations, core/check_credentials, core/get, core/create, core/update, core/delete;
 * - synchro/synchro_import.php: loads the CSV into the replica table of the data source;
 * - synchro/synchro_exec.php: reconciles the replicas with the objects, then creates or updates them.
 *
 * Response formats, error codes and messages come from the iTop 3.2 sources (webservices/rest.php,
 * core/restservices.class.inc.php, application/applicationextension.inc.php, synchro/*.php). The integration tests
 * (integration-tests) run the same scenarios against a real iTop: keep both in line when changing this class.
 *
 * Data model: the fake knows the classes declared with DefineClass(), plus a minimal Organization and Person.
 * A request it cannot emulate is recorded (GetUnsupportedRequests()) and answered with an error: BaseTestCase fails
 * the test, so that a gap in the fake is never mistaken for a bug in the framework.
 */
class FakeItop
{
	public const URL = 'http://fake-itop.test/itop';
	public const LOGIN = 'collector';
	public const PASSWORD = 'collector-password';

	/** Attributes of a SynchroDataSource and their default value, as defined in iTop 3.2 */
	private const SDS_ATTRIBUTES = [
		'name' => '',
		'description' => '',
		'status' => 'implementation',
		'user_id' => '0',
		'notify_contact_id' => '0',
		'scope_class' => '',
		'database_table_name' => '',
		'scope_restriction' => '',
		'full_load_periodicity' => '0',
		'reconciliation_policy' => 'use_attributes',
		'action_on_zero' => 'create',
		'action_on_one' => 'update',
		'action_on_multiple' => 'error',
		'delete_policy' => 'ignore',
		'delete_policy_update' => '',
		'delete_policy_retention' => '0',
		'user_delete_policy' => 'nobody',
		'url_icon' => '',
		'url_application' => '',
	];

	/** Read-only (external) fields that iTop adds to a SynchroDataSource in REST outputs */
	private const SDS_EXTERNAL_FIELDS = [
		'user_id_friendlyname' => '',
		'user_id_finalclass_recall' => '',
		'notify_contact_id_friendlyname' => '',
		'notify_contact_id_finalclass_recall' => '',
		'notify_contact_id_obsolescence_flag' => '',
	];

	/** Attributes of the SynchroAttribute classes and their default value, as defined in iTop 3.2 */
	private const SYNCHRO_ATTRIBUTE_ATTRIBUTES = [
		'SynchroAttribute' => ['attcode' => '', 'update' => '1', 'reconcile' => '0', 'update_policy' => 'master_locked'],
		'SynchroAttExtKey' => ['reconciliation_attcode' => ''],
		'SynchroAttLinkSet' => ['row_separator' => '|', 'attribute_separator' => ';', 'value_separator' => ':', 'attribute_qualifier' => '\''],
	];

	private const REST_OPERATIONS = [
		'list_operations',
		'core/create',
		'core/update',
		'core/apply_stimulus',
		'core/get',
		'core/delete',
		'core/get_related',
		'core/check_credentials',
	];

	/** @var string[] REST API versions supported by iTop 3.2 */
	private $aRestVersions = ['1.0', '1.1', '1.2', '1.3'];

	/** @var string */
	private $sLogin = self::LOGIN;
	/** @var string */
	private $sPassword = self::PASSWORD;
	/** @var string */
	private $sToken = '';

	/** @var array<string, string> Class => parent class */
	private $aParentClasses = [
		'UserLocal' => 'User',
		'Person' => 'Contact',
		'Team' => 'Contact',
		'SynchroAttExtKey' => 'SynchroAttribute',
		'SynchroAttLinkSet' => 'SynchroAttribute',
	];

	/** @var array<string, array{attributes: array<string, array{type: string, target: ?string, mandatory: bool, columns: string[]}>, reconc_keys: string[]}> */
	private $aClassModels = [];

	/** @var array<int, array{class: string, fields: array<string, mixed>}> Every object, by id */
	private $aObjects = [];

	/** @var int */
	private $iNextId = 1;

	/** @var array<int, array<string, array{values: array<string, ?string>, seen: bool, dest_id: int}>> SDS id => primary_key => replica */
	private $aReplicas = [];

	/** @var array<int, array{page: string, url: string, data: array, operation: ?array}> */
	private $aRequests = [];

	/** @var string[] */
	private $aUnsupportedRequests = [];

	/** @var int */
	private $iForcedImportErrors = 0;

	public function __construct()
	{
		$this->DefineClass('Organization', [
			'name' => ['mandatory' => true],
			'code' => [],
			'status' => [],
			'parent_id' => ['type' => 'extkey', 'target' => 'Organization'],
		], ['name']);
		$this->DefineClass('Person', [
			'name' => ['mandatory' => true],
			'first_name' => [],
			'email' => [],
			'org_id' => ['type' => 'extkey', 'target' => 'Organization', 'mandatory' => true],
			'status' => [],
			'phone' => [],
			'mobile_phone' => [],
			'employee_number' => [],
			'function' => [],
			'notify' => [],
			'location_id' => ['type' => 'extkey', 'target' => 'Location'],
			'manager_id' => ['type' => 'extkey', 'target' => 'Person'],
			'team_list' => ['type' => 'linkset'],
			'cis_list' => ['type' => 'linkset'],
		], ['name', 'first_name', 'org_id']);

		$this->AddObject('UserLocal', ['login' => self::LOGIN, 'email' => 'collector@example.com', 'friendlyname' => 'Collector Account']);
	}

	/**
	 * Serve the requests sent to FakeItop::URL.
	 */
	public function InstallOn(FakeHttpServer $oServer): void
	{
		$oServer->Route(self::URL.'/', function (string $sUrl, array $aData): string {
			return $this->Handle($sUrl, $aData);
		});
	}

	/**
	 * Declare (or redefine) a class of the data model, with the attributes a data source can synchronize.
	 *
	 * @param string $sClass
	 * @param array<string, array{type?: string, target?: string, mandatory?: bool, columns?: string[]}> $aAttributes
	 *     attcode => spec. type: 'string' (default), 'extkey' (with 'target' class) or 'linkset'. columns: the replica
	 *     columns of a multi-column attribute (e.g. an attachment), [attcode] by default.
	 * @param string[] $aReconcKeys Attributes flagged "reconcile" when iTop creates the SynchroAttributes
	 * @param string|null $sParentClass
	 */
	public function DefineClass(string $sClass, array $aAttributes, array $aReconcKeys = [], ?string $sParentClass = null): void
	{
		$aModel = ['attributes' => [], 'reconc_keys' => $aReconcKeys];
		foreach ($aAttributes as $sAttCode => $aSpec) {
			$aModel['attributes'][$sAttCode] = [
				'type' => $aSpec['type'] ?? 'string',
				'target' => $aSpec['target'] ?? null,
				'mandatory' => $aSpec['mandatory'] ?? false,
				'columns' => $aSpec['columns'] ?? [$sAttCode],
			];
		}
		$this->aClassModels[$sClass] = $aModel;
		if ($sParentClass !== null) {
			$this->aParentClasses[$sClass] = $sParentClass;
		}
	}

	/**
	 * Declare the class targeted by an SDS definition file, from its attribute_list: SynchroAttExtKey gives an external
	 * key (to an unknown class), SynchroAttLinkSet a link set, anything else a string. `import_columns` gives the
	 * columns of multi-column attributes.
	 *
	 * @param string $sJsonFile Path of the <Collector>.json file
	 */
	public function DefineClassFromSynchroDataSource(string $sJsonFile): void
	{
		$aDefinition = json_decode(file_get_contents($sJsonFile), true);
		$aAttributes = [];
		$aReconcKeys = [];
		foreach ($aDefinition['attribute_list'] as $aAttr) {
			$aSpec = [];
			if ($aAttr['finalclass'] === 'SynchroAttExtKey') {
				$aSpec['type'] = 'extkey';
			} elseif ($aAttr['finalclass'] === 'SynchroAttLinkSet') {
				$aSpec['type'] = 'linkset';
			}
			if (isset($aAttr['import_columns'])) {
				$aSpec['columns'] = explode(',', $aAttr['import_columns']);
			}
			$aAttributes[$aAttr['attcode']] = $aSpec;
			if ($aAttr['reconcile'] == '1') {
				$aReconcKeys[] = $aAttr['attcode'];
			}
		}
		$this->DefineClass($aDefinition['scope_class'], $aAttributes, $aReconcKeys);
	}

	/**
	 * @param string $sLogin
	 * @param string $sPassword
	 * @param string $sToken Accepted as auth_token when not empty
	 */
	public function SetCredentials(string $sLogin, string $sPassword, string $sToken = ''): void
	{
		$this->sLogin = $sLogin;
		$this->sPassword = $sPassword;
		$this->sToken = $sToken;
	}

	/**
	 * @param string[] $aVersions REST API versions accepted (iTop 3.2: 1.0 to 1.3)
	 */
	public function SetRestVersions(array $aVersions): void
	{
		$this->aRestVersions = $aVersions;
	}

	/**
	 * Make the next synchro_import.php report this number of lines in error.
	 */
	public function ForceImportErrors(int $iCount): void
	{
		$this->iForcedImportErrors = $iCount;
	}

	/**
	 * @param string $sClass
	 * @param array<string, mixed> $aFields
	 *
	 * @return int The id of the new object
	 */
	public function AddObject(string $sClass, array $aFields): int
	{
		$iId = $this->iNextId++;
		if (!array_key_exists('friendlyname', $aFields)) {
			$aFields['friendlyname'] = $aFields['name'] ?? ($aFields['login'] ?? "$sClass::$iId");
		}
		$this->aObjects[$iId] = ['class' => $sClass, 'fields' => $aFields];

		return $iId;
	}

	/**
	 * @param string $sClass Class, including its sub-classes
	 * @param array<string, mixed> $aCriteria attcode => value, compared as strings
	 *
	 * @return array<int, array<string, mixed>> id => fields
	 */
	public function GetObjects(string $sClass, array $aCriteria = []): array
	{
		$aResult = [];
		foreach ($this->aObjects as $iId => $aObject) {
			if (!$this->IsSameOrSubClass($aObject['class'], $sClass)) {
				continue;
			}
			foreach ($aCriteria as $sAttCode => $value) {
				if ((string)($aObject['fields'][$sAttCode] ?? '') !== (string)$value) {
					continue 2;
				}
			}
			$aResult[$iId] = $aObject['fields'];
		}

		return $aResult;
	}

	/**
	 * @return array<string, mixed>|null The fields of the data source, attribute_list included, as core/get returns them
	 */
	public function GetSynchroDataSource(string $sName): ?array
	{
		foreach ($this->GetObjects('SynchroDataSource', ['name' => $sName]) as $iId => $aFields) {
			return $this->GetOutputFields($iId);
		}

		return null;
	}

	/**
	 * @return array<string, array{values: array<string, ?string>, seen: bool, dest_id: int}> primary_key => replica
	 */
	public function GetReplicas(int $iSourceId): array
	{
		return $this->aReplicas[$iSourceId] ?? [];
	}

	/**
	 * @param string|null $sPage 'rest', 'synchro_import' or 'synchro_exec'; null for all
	 *
	 * @return array<int, array{page: string, url: string, data: array, operation: ?array}>
	 */
	public function GetRequests(?string $sPage = null): array
	{
		if ($sPage === null) {
			return $this->aRequests;
		}

		return array_values(array_filter($this->aRequests, function ($aRequest) use ($sPage) {
			return $aRequest['page'] === $sPage;
		}));
	}

	/**
	 * @param string|null $sVerb e.g. 'core/get'
	 * @param string|null $sClass
	 *
	 * @return array<int, array> The decoded json_data of the matching REST requests, in order
	 */
	public function GetRestOperations(?string $sVerb = null, ?string $sClass = null): array
	{
		$aOperations = [];
		foreach ($this->GetRequests('rest') as $aRequest) {
			$aOperation = $aRequest['operation'];
			if ($aOperation === null) {
				continue;
			}
			if ($sVerb !== null && ($aOperation['operation'] ?? null) !== $sVerb) {
				continue;
			}
			if ($sClass !== null && ($aOperation['class'] ?? null) !== $sClass) {
				continue;
			}
			$aOperations[] = $aOperation;
		}

		return $aOperations;
	}

	/**
	 * @return string[] Requests the fake could not emulate (a test relying on them would be meaningless)
	 */
	public function GetUnsupportedRequests(): array
	{
		return $this->aUnsupportedRequests;
	}

	/**
	 * Answer one HTTP request.
	 *
	 * @param string $sUrl
	 * @param array $aData POSTed fields
	 *
	 * @return string The response body
	 */
	public function Handle(string $sUrl, array $aData): string
	{
		$aUrl = parse_url($sUrl);
		$sPath = substr($aUrl['path'] ?? '', strlen((string)parse_url(self::URL, PHP_URL_PATH)));
		parse_str($aUrl['query'] ?? '', $aQuery);

		switch ($sPath) {
			case '/webservices/rest.php':
				return $this->HandleRest($sUrl, $aQuery, $aData);

			case '/synchro/synchro_import.php':
				return $this->HandleSynchroImport($sUrl, $aData);

			case '/synchro/synchro_exec.php':
				return $this->HandleSynchroExec($sUrl, $aData);

			default:
				$this->aRequests[] = ['page' => 'other', 'url' => $sUrl, 'data' => $aData, 'operation' => null];
				$this->aUnsupportedRequests[] = "No such page: $sUrl";

				return '<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
		}
	}

	/////////////////////////////////////////////////////////////////////////
	// REST API (webservices/rest.php)
	/////////////////////////////////////////////////////////////////////////

	private function HandleRest(string $sUrl, array $aQuery, array $aData): string
	{
		$aOperation = isset($aData['json_data']) ? json_decode($aData['json_data'], true) : null;
		$this->aRequests[] = ['page' => 'rest', 'url' => $sUrl, 'data' => $aData, 'operation' => is_array($aOperation) ? $aOperation : null];

		try {
			if (!$this->IsAuthenticated($aData)) {
				throw new FakeItopException('Invalid login', 1);
			}
			$sVersion = $aQuery['version'] ?? null;
			if ($sVersion === null) {
				throw new FakeItopException("Missing parameter 'version' (e.g. '1.0')", 2);
			}
			if (!isset($aData['json_data'])) {
				throw new FakeItopException("Missing parameter 'json_data'", 3);
			}
			if (!is_array($aOperation)) {
				throw new FakeItopException("Parameter json_data is not a valid JSON structure", 4);
			}
			if (!in_array($sVersion, $this->aRestVersions, true)) {
				throw new FakeItopException("There is no service available for version '$sVersion'", 10);
			}
			$aResult = $this->ExecRestOperation($aOperation, $sVersion);
		} catch (FakeItopException $e) {
			$aResult = ['code' => ($e->getCode() === 0) ? 100 : $e->getCode(), 'message' => 'Error: '.$e->getMessage()];
		}

		return json_encode($aResult);
	}

	/**
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function ExecRestOperation(array $aOperation, string $sVersion): array
	{
		$sVerb = $aOperation['operation'] ?? '';
		switch ($sVerb) {
			case 'list_operations':
				$aOperations = [];
				foreach (self::REST_OPERATIONS as $sOperation) {
					$aOperations[] = ['verb' => $sOperation, 'description' => '', 'extension' => 'CoreServices'];
				}

				return ['version' => $sVersion, 'operations' => $aOperations, 'code' => 0, 'message' => 'Operations: '.count($aOperations)];

			case 'core/check_credentials':
				$bAuthorized = (($aOperation['user'] ?? null) === $this->sLogin) && (($aOperation['password'] ?? null) === $this->sPassword);

				return ['code' => 0, 'message' => '', 'authorized' => $bAuthorized];

			case 'core/get':
				$sClass = $this->GetMandatoryParam($aOperation, 'class');
				$aIds = $this->FindIds($sClass, $this->GetMandatoryParam($aOperation, 'key'));
				$iLimit = (int)($aOperation['limit'] ?? 0);
				if ($iLimit > 0) {
					$aIds = array_slice($aIds, 0, $iLimit);
				}
				$aResult = ['objects' => null, 'code' => 0, 'message' => 'Found: '.count($aIds)];
				foreach ($aIds as $iId) {
					$this->AddObjectToResult($aResult, $iId, '', $aOperation['output_fields'] ?? '*');
				}

				return $aResult;

			case 'core/create':
				$sClass = $this->GetMandatoryParam($aOperation, 'class');
				$aFields = $this->GetMandatoryParam($aOperation, 'fields');
				$iId = ($sClass === 'SynchroDataSource') ? $this->CreateSynchroDataSource($aFields) : $this->CreateObject($sClass, $aFields);
				$aResult = ['objects' => null, 'code' => 0, 'message' => ''];
				$this->AddObjectToResult($aResult, $iId, 'created', $aOperation['output_fields'] ?? '*');

				return $aResult;

			case 'core/update':
				$sClass = $this->GetMandatoryParam($aOperation, 'class');
				$iId = $this->FindSingleId($sClass, $this->GetMandatoryParam($aOperation, 'key'));
				$this->UpdateObject($iId, $this->GetMandatoryParam($aOperation, 'fields'));
				$aResult = ['objects' => null, 'code' => 0, 'message' => ''];
				$this->AddObjectToResult($aResult, $iId, 'updated', $aOperation['output_fields'] ?? '*');

				return $aResult;

			case 'core/delete':
				$sClass = $this->GetMandatoryParam($aOperation, 'class');
				$aIds = $this->FindIds($sClass, $this->GetMandatoryParam($aOperation, 'key'));
				$aResult = ['objects' => null, 'code' => 0, 'message' => 'Deleted: '.count($aIds)];
				foreach ($aIds as $iId) {
					$sObjClass = $this->aObjects[$iId]['class'];
					$aResult['objects']["$sObjClass::$iId"] = ['code' => 0, 'message' => 'Deleted', 'class' => $sObjClass, 'key' => (string)$iId, 'fields' => []];
					$this->DeleteObject($iId);
				}

				return $aResult;

			default:
				if (in_array($sVerb, self::REST_OPERATIONS, true)) {
					$this->aUnsupportedRequests[] = "REST operation not emulated: $sVerb";
				}

				throw new FakeItopException("Unknown verb '$sVerb' in version '$sVersion'", 11);
		}
	}

	/**
	 * @return mixed
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function GetMandatoryParam(array $aOperation, string $sParam)
	{
		if (!array_key_exists($sParam, $aOperation)) {
			throw new FakeItopException("Missing parameter '$sParam'", 100);
		}

		return $aOperation[$sParam];
	}

	private function AddObjectToResult(array &$aResult, int $iId, string $sMessage, string $sOutputFields): void
	{
		$sClass = $this->aObjects[$iId]['class'];
		$aFields = $this->GetOutputFields($iId);
		if ($sOutputFields !== '*' && $sOutputFields !== '*+') {
			$aFields = array_intersect_key($aFields, array_flip(array_map('trim', explode(',', $sOutputFields))));
		}
		// DBObject::GetKey() is a string, both for loaded and for new objects
		$aResult['objects']["$sClass::$iId"] = ['code' => 0, 'message' => $sMessage, 'class' => $sClass, 'key' => (string)$iId, 'fields' => $aFields];
	}

	/**
	 * @return array<string, mixed> The fields of an object as iTop outputs them with output_fields = '*'
	 */
	private function GetOutputFields(int $iId): array
	{
		$aObject = $this->aObjects[$iId];
		$aFields = $aObject['fields'];
		if ($aObject['class'] === 'SynchroDataSource') {
			// ObjectResult::MakeResultValue(): the attributes of the linked class (SynchroAttribute, not of the actual
			// sub-class), without the ones pointing to the data source
			$aFields['attribute_list'] = [];
			foreach ($this->GetObjects('SynchroAttribute', ['sync_source_id' => $iId]) as $aAttFields) {
				$aLinkFields = array_intersect_key($aAttFields, self::SYNCHRO_ATTRIBUTE_ATTRIBUTES['SynchroAttribute']);
				$aLinkFields['finalclass'] = $aAttFields['finalclass'];
				$aLinkFields['friendlyname'] = $aAttFields['friendlyname'];
				$aFields['attribute_list'][] = $aLinkFields;
			}
		}

		return $aFields;
	}

	/**
	 * @param string $sClass
	 * @param mixed $key An id, a hash of criteria or an OQL query
	 *
	 * @return int[]
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function FindIds(string $sClass, $key): array
	{
		if (is_int($key) || (is_string($key) && ctype_digit($key))) {
			$iId = (int)$key;

			return (isset($this->aObjects[$iId]) && $this->IsSameOrSubClass($this->aObjects[$iId]['class'], $sClass)) ? [$iId] : [];
		}
		if (is_array($key)) {
			if (isset($key['finalclass'])) {
				$sClass = $key['finalclass'];
				unset($key['finalclass']);
			}

			return array_keys($this->GetObjects($sClass, $key));
		}
		if (is_string($key)) {
			return $this->RunOql($key);
		}

		throw new FakeItopException('Wrong format for key: '.json_encode($key), 100);
	}

	/**
	 * Like RestUtils::FindObjectFromKey(): exactly one object must match.
	 *
	 * @param mixed $key
	 *
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function FindSingleId(string $sClass, $key): int
	{
		$aIds = $this->FindIds($sClass, $key);
		if (count($aIds) === 1) {
			return $aIds[0];
		}

		if (is_array($key)) {
			$aCriteriaReport = [];
			foreach ($key as $sAttCode => $value) {
				$aCriteriaReport[] = "$sAttCode: $value ($value)";
			}
			$sCriteria = 'with criteria: '.implode(', ', $aCriteriaReport);
		} elseif (is_string($key) && !ctype_digit($key)) {
			$sCriteria = "for query: $key";
		} else {
			throw new FakeItopException("Invalid object $sClass::$key", 100);
		}

		if (count($aIds) === 0) {
			throw new FakeItopException("No item found $sCriteria", 100);
		}
		throw new FakeItopException('Several items found ('.count($aIds).") $sCriteria", 100);
	}

	/**
	 * Supports `SELECT <Class> [WHERE <cond> [AND <cond>]...]` with `<attcode> = <value>`, `<attcode> != <value>`,
	 * `<attcode> LIKE <value>` and `<attcode> IN (<value>, ...)`, values being quoted strings or numbers.
	 *
	 * @return int[]
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function RunOql(string $sOql): array
	{
		$sValue = '(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?[0-9]+(?:\.[0-9]+)?)';
		if (!preg_match('/^\s*SELECT\s+([A-Za-z0-9_]+)(?:\s+WHERE\s+(.+?))?\s*$/is', $sOql, $aMatches)) {
			return $this->Unsupported("OQL not emulated: $sOql");
		}
		$sClass = $aMatches[1];
		$aConditions = [];
		if (isset($aMatches[2])) {
			foreach (preg_split('/\s+AND\s+/i', $aMatches[2]) as $sCondition) {
				if (preg_match("/^\s*([A-Za-z0-9_]+)\s*(=|!=|LIKE)\s*($sValue)\s*$/i", $sCondition, $aCond)) {
					$aConditions[] = [$aCond[1], strtoupper($aCond[2]), [$this->ParseOqlValue($aCond[3])]];
				} elseif (preg_match("/^\s*([A-Za-z0-9_]+)\s+IN\s*\((.*)\)\s*$/i", $sCondition, $aCond)) {
					preg_match_all("/$sValue/", $aCond[2], $aValues);
					$aConditions[] = [$aCond[1], 'IN', array_map([$this, 'ParseOqlValue'], $aValues[0])];
				} else {
					return $this->Unsupported("OQL condition not emulated: $sCondition (in $sOql)");
				}
			}
		}

		$aIds = [];
		foreach ($this->GetObjects($sClass) as $iId => $aFields) {
			foreach ($aConditions as [$sAttCode, $sOperator, $aValues]) {
				$sFieldValue = (string)($aFields[$sAttCode] ?? ($sAttCode === 'id' ? $iId : ''));
				switch ($sOperator) {
					case '=':
					case 'IN':
						$bMatch = in_array($sFieldValue, $aValues, true);
						break;
					case '!=':
						$bMatch = ($sFieldValue !== $aValues[0]);
						break;
					default: // LIKE
						$sRegExp = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($aValues[0], '/')).'$/is';
						$bMatch = (bool)preg_match($sRegExp, $sFieldValue);
				}
				if (!$bMatch) {
					continue 2;
				}
			}
			$aIds[] = $iId;
		}

		return $aIds;
	}

	private function ParseOqlValue(string $sToken): string
	{
		if ($sToken[0] === '\'' || $sToken[0] === '"') {
			return stripcslashes(substr($sToken, 1, -1));
		}

		return $sToken;
	}

	/**
	 * @return array Never returns
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function Unsupported(string $sMessage): array
	{
		$this->aUnsupportedRequests[] = $sMessage;
		throw new FakeItopException("(fake iTop) $sMessage", 100);
	}

	/**
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function CreateObject(string $sClass, array $aFields): int
	{
		if (isset($this->aClassModels[$sClass])) {
			foreach ($this->aClassModels[$sClass]['attributes'] as $sAttCode => $aSpec) {
				if ($aSpec['mandatory'] && (($aFields[$sAttCode] ?? '') === '')) {
					throw new FakeItopException("Missing mandatory attribute: '$sAttCode'", 100);
				}
			}
		}

		return $this->AddObject($sClass, $aFields);
	}

	/**
	 * Like iTop: fill the defaults, then create one SynchroAttribute per attribute of the scope class.
	 *
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function CreateSynchroDataSource(array $aFields): int
	{
		foreach (array_keys($aFields) as $sAttCode) {
			if (!array_key_exists($sAttCode, self::SDS_ATTRIBUTES) && $sAttCode !== 'attribute_list') {
				throw new FakeItopException("Unknown attribute $sAttCode from class SynchroDataSource", 100);
			}
		}
		$sScopeClass = $aFields['scope_class'] ?? '';
		if (!isset($this->aClassModels[$sScopeClass])) {
			$this->Unsupported("SynchroDataSource on class '$sScopeClass': declare the class with FakeItop::DefineClass()");
		}
		unset($aFields['attribute_list']);

		$aSdsFields = array_merge(self::SDS_ATTRIBUTES, array_map('strval', $aFields));
		$aSdsFields['friendlyname'] = $aSdsFields['name'];
		$aSdsFields = array_merge($aSdsFields, self::SDS_EXTERNAL_FIELDS);
		$iSourceId = $this->AddObject('SynchroDataSource', $aSdsFields);

		$aModel = $this->aClassModels[$sScopeClass];
		foreach ($aModel['attributes'] as $sAttCode => $aSpec) {
			switch ($aSpec['type']) {
				case 'extkey':
					$sAttClass = 'SynchroAttExtKey';
					break;
				case 'linkset':
					$sAttClass = 'SynchroAttLinkSet';
					break;
				default:
					$sAttClass = 'SynchroAttribute';
			}
			$aAttFields = $this->GetSynchroAttributeDefaults($sAttClass);
			$aAttFields['attcode'] = $sAttCode;
			$aAttFields['reconcile'] = in_array($sAttCode, $aModel['reconc_keys'], true) ? '1' : '0';
			$this->AddObject($sAttClass, array_merge(
				['sync_source_id' => (string)$iSourceId, 'sync_source_name' => $aSdsFields['name']],
				$aAttFields,
				['finalclass' => $sAttClass, 'friendlyname' => $sAttCode, 'sync_source_id_friendlyname' => $aSdsFields['name']]
			));
		}

		return $iSourceId;
	}

	/**
	 * @return array<string, string> The DB-based scalar attributes of a SynchroAttribute class, with their defaults
	 */
	private function GetSynchroAttributeDefaults(string $sAttClass): array
	{
		$aDefaults = self::SYNCHRO_ATTRIBUTE_ATTRIBUTES['SynchroAttribute'];
		if ($sAttClass !== 'SynchroAttribute') {
			$aDefaults = array_merge($aDefaults, self::SYNCHRO_ATTRIBUTE_ATTRIBUTES[$sAttClass]);
		}

		return $aDefaults;
	}

	/**
	 * @throws \UnitTestFiles\Test\Support\FakeItopException
	 */
	private function UpdateObject(int $iId, array $aFields): void
	{
		$sClass = $this->aObjects[$iId]['class'];
		if ($sClass === 'SynchroDataSource' || $this->IsSameOrSubClass($sClass, 'SynchroAttribute')) {
			$aAllowed = ($sClass === 'SynchroDataSource') ? self::SDS_ATTRIBUTES : $this->GetSynchroAttributeDefaults($sClass);
			foreach (array_keys($aFields) as $sAttCode) {
				if (!array_key_exists($sAttCode, $aAllowed)) {
					throw new FakeItopException("Unknown attribute $sAttCode from class $sClass", 100);
				}
			}
			$aFields = array_map('strval', $aFields);
		}
		$this->aObjects[$iId]['fields'] = array_merge($this->aObjects[$iId]['fields'], $aFields);
	}

	private function DeleteObject(int $iId): void
	{
		if ($this->aObjects[$iId]['class'] === 'SynchroDataSource') {
			foreach (array_keys($this->GetObjects('SynchroAttribute', ['sync_source_id' => $iId])) as $iAttId) {
				unset($this->aObjects[$iAttId]);
			}
			unset($this->aReplicas[$iId]);
		}
		unset($this->aObjects[$iId]);
	}

	private function IsSameOrSubClass(string $sClass, string $sParentClass): bool
	{
		while ($sClass !== $sParentClass) {
			if (!isset($this->aParentClasses[$sClass])) {
				return false;
			}
			$sClass = $this->aParentClasses[$sClass];
		}

		return true;
	}

	private function IsAuthenticated(array $aData): bool
	{
		if (($this->sToken !== '') && (($aData['auth_token'] ?? null) === $this->sToken)) {
			return true;
		}

		return (($aData['auth_user'] ?? null) === $this->sLogin) && (($aData['auth_pwd'] ?? null) === $this->sPassword);
	}

	private function GetSynchroDataSourceById(int $iId): ?array
	{
		if (!isset($this->aObjects[$iId]) || $this->aObjects[$iId]['class'] !== 'SynchroDataSource') {
			return null;
		}

		return $this->GetOutputFields($iId);
	}

	/////////////////////////////////////////////////////////////////////////
	// synchro/synchro_import.php
	/////////////////////////////////////////////////////////////////////////

	private function HandleSynchroImport(string $sUrl, array $aData): string
	{
		$this->aRequests[] = ['page' => 'synchro_import', 'url' => $sUrl, 'data' => $aData, 'operation' => null];

		if (!$this->IsAuthenticated($aData)) {
			return $this->P('Invalid login');
		}

		$sOutput = $aData['output'] ?? 'summary';
		$iSourceId = (int)($aData['data_source_id'] ?? 0);
		$aSource = $this->GetSynchroDataSourceById($iSourceId);
		if ($aSource === null) {
			return $this->CliComment("Unknown data source id: '$iSourceId'");
		}
		if (!in_array($sOutput, ['retcode', 'summary', 'details'], true)) {
			return $this->CliComment("Unknown output format: '$sOutput'");
		}

		$sCsvData = (string)($aData['csvdata'] ?? '');
		$aRows = $this->ParseCsv($sCsvData, $aData['separator'] ?? ',', $aData['qualifier'] ?? '"');
		$aColumns = array_shift($aRows) ?? [];

		$aAllowedColumns = [];
		$aModel = $this->aClassModels[$aSource['scope_class']];
		foreach ($aSource['attribute_list'] as $aAttr) {
			foreach ($aModel['attributes'][$aAttr['attcode']]['columns'] ?? [$aAttr['attcode']] as $sColumn) {
				$aAllowedColumns[] = $sColumn;
			}
		}
		foreach ($aColumns as $sColumn) {
			if ($sColumn !== 'primary_key' && !in_array($sColumn, $aAllowedColumns, true)) {
				return $this->CliComment("Unknown column '$sColumn' (class: '{$aSource['scope_class']}')");
			}
		}
		if (!in_array('primary_key', $aColumns, true)) {
			return $this->CliComment("Missing reconciliation column 'primary_key'");
		}

		$iCreated = 0;
		$iUpdated = 0;
		foreach ($aRows as $aRow) {
			$aValues = [];
			foreach ($aColumns as $iCol => $sColumn) {
				$sValue = $aRow[$iCol] ?? '';
				$aValues[$sColumn] = ($sValue === '<NULL>') ? null : $sValue; // like CSVParser: <NULL> means NULL
			}
			$sPrimaryKey = (string)$aValues['primary_key'];
			unset($aValues['primary_key']);
			if (isset($this->aReplicas[$iSourceId][$sPrimaryKey])) {
				$this->aReplicas[$iSourceId][$sPrimaryKey]['values'] = array_merge($this->aReplicas[$iSourceId][$sPrimaryKey]['values'], $aValues);
				$this->aReplicas[$iSourceId][$sPrimaryKey]['seen'] = true;
				$iUpdated++;
			} else {
				$this->aReplicas[$iSourceId][$sPrimaryKey] = ['values' => $aValues, 'seen' => true, 'dest_id' => 0];
				$iCreated++;
			}
		}

		$iErrors = $this->iForcedImportErrors;
		$this->iForcedImportErrors = 0;

		if ($sOutput === 'retcode') {
			return (string)$iErrors;
		}

		$sPage = '';
		foreach ([
			'Data Size: '.strlen($sCsvData),
			'Data Lines: '.count($aRows),
			'Columns: '.implode(', ', $aColumns),
			'Output format: '.$sOutput,
			'Simulate: 0',
			'Change tracking comment: ',
			'Issues (before synchro): '.$iErrors,
			'Created (before synchro): '.$iCreated,
			'Updated (before synchro): '.$iUpdated,
		] as $sComment) {
			$sPage .= $this->CliComment($sComment);
		}

		return $sPage;
	}

	/**
	 * CLILikeWebPage::add_comment()
	 */
	private function CliComment(string $sText): string
	{
		return '#'.$sText."<br/>\n";
	}

	/**
	 * @return array<int, string[]>
	 */
	private function ParseCsv(string $sCsvData, string $sSeparator, string $sQualifier): array
	{
		$hFile = fopen('php://memory', 'r+');
		fwrite($hFile, $sCsvData);
		rewind($hFile);
		$aRows = [];
		while (($aRow = fgetcsv($hFile, 0, $sSeparator, $sQualifier, '\\')) !== false) {
			if ($aRow === [null]) {
				continue; // blank line
			}
			$aRows[] = $aRow;
		}
		fclose($hFile);

		return $aRows;
	}

	/////////////////////////////////////////////////////////////////////////
	// synchro/synchro_exec.php
	/////////////////////////////////////////////////////////////////////////

	private function HandleSynchroExec(string $sUrl, array $aData): string
	{
		$this->aRequests[] = ['page' => 'synchro_exec', 'url' => $sUrl, 'data' => $aData, 'operation' => null];

		if (!$this->IsAuthenticated($aData)) {
			return $this->WebPage($this->P('Invalid login'));
		}
		$sDataSources = trim((string)($aData['data_sources'] ?? ''));
		if ($sDataSources === '') {
			return $this->WebPage($this->P("ERROR: Missing argument 'data_sources'\n"));
		}

		$sBody = '';
		foreach (explode(',', $sDataSources) as $sSourceId) {
			$aSource = $this->GetSynchroDataSourceById((int)$sSourceId);
			if ($aSource === null) {
				$sBody .= $this->P("ERROR: The data source (id=$sSourceId) does not exist. Exiting...");
				break;
			}
			$sBody .= $this->P('Working on '.htmlentities($aSource['name'])." (id=$sSourceId)...");
			$aStats = $this->RunSynchro((int)$sSourceId, $aSource);
			if ($aStats['last_error'] !== '') {
				$sBody .= $this->P('ERROR: '.$aStats['last_error']);
			}
			foreach ([
				'Replicas: '.$aStats['replica_total'],
				'Replicas touched since last synchro: '.$aStats['replica_seen'],
				'Objects deleted: 0',
				'Objects deletion errors: 0',
				'Objects obsoleted: 0',
				'Objects obsolescence errors: 0',
				'Objects created: '.$aStats['created'].' ('.$aStats['created_warnings'].' warnings)',
				'Objects creation errors: '.$aStats['created_errors'],
				'Objects updated: '.$aStats['updated'].' ('.$aStats['updated_warnings'].' warnings)',
				'Objects update errors: '.$aStats['updated_errors'],
				'Objects reconciled (updated): 0 (0 warnings)',
				'Objects reconciled (unchanged): '.$aStats['unchanged'].' (0 warnings)',
				'Objects reconciliation errors: '.$aStats['reconciliation_errors'],
				'Replica disappeared, no action taken: 0',
			] as $sLine) {
				$sBody .= $this->P($sLine);
			}
		}

		return $this->WebPage($sBody);
	}

	/**
	 * WebPage::p(): one paragraph per line
	 */
	private function P(string $sHtml): string
	{
		return "<p>$sHtml</p>\n";
	}

	private function WebPage(string $sBody): string
	{
		return "<!DOCTYPE html>\n<html><head><title>Synchronization</title></head><body>$sBody</body></html>";
	}

	/**
	 * Simplified SynchroExecution::Process(): the replicas seen since the last run are reconciled with the objects of
	 * the scope class, then created or updated according to the action_on_* settings. Deletion is not emulated.
	 *
	 * @return array{replica_total: int, replica_seen: int, created: int, created_warnings: int, created_errors: int, updated: int, updated_warnings: int, updated_errors: int, unchanged: int, reconciliation_errors: int, last_error: string}
	 */
	private function RunSynchro(int $iSourceId, array $aSource): array
	{
		$aStats = [
			'replica_total' => count($this->aReplicas[$iSourceId] ?? []),
			'replica_seen' => 0,
			'created' => 0,
			'created_warnings' => 0,
			'created_errors' => 0,
			'updated' => 0,
			'updated_warnings' => 0,
			'updated_errors' => 0,
			'unchanged' => 0,
			'reconciliation_errors' => 0,
			'last_error' => '',
		];
		$sClass = $aSource['scope_class'];
		$aAttributes = [];
		foreach ($aSource['attribute_list'] as $aAttr) {
			$aAttributes[$aAttr['attcode']] = $aAttr;
		}

		foreach ($this->aReplicas[$iSourceId] ?? [] as $sPrimaryKey => &$aReplica) {
			if (!$aReplica['seen']) {
				continue;
			}
			$aReplica['seen'] = false;
			$aStats['replica_seen']++;

			$iWarnings = 0;
			$aValues = $this->ResolveReplicaValues($sClass, $aAttributes, $aReplica['values'], $iWarnings);

			if ($aSource['reconciliation_policy'] === 'use_primary_key') {
				$aMatches = isset($this->aObjects[(int)$sPrimaryKey]) && $this->IsSameOrSubClass($this->aObjects[(int)$sPrimaryKey]['class'], $sClass) ? [(int)$sPrimaryKey] : [];
			} elseif ($aReplica['dest_id'] > 0 && isset($this->aObjects[$aReplica['dest_id']])) {
				$aMatches = [$aReplica['dest_id']];
			} else {
				$aCriteria = [];
				foreach ($aAttributes as $sAttCode => $aAttr) {
					if ($aAttr['reconcile'] == '1') {
						$aCriteria[$sAttCode] = $aValues[$sAttCode] ?? '';
					}
				}
				$aMatches = empty($aCriteria) ? [] : array_keys($this->GetObjects($sClass, $aCriteria));
			}

			$sAction = 'error';
			if (count($aMatches) === 0) {
				$sAction = $aSource['action_on_zero'];
			} elseif (count($aMatches) === 1) {
				$sAction = $aSource['action_on_one'];
			} elseif ($aSource['action_on_multiple'] !== 'error') {
				$sAction = ($aSource['action_on_multiple'] === 'take_first') ? 'update' : $aSource['action_on_multiple'];
			} else {
				$aStats['reconciliation_errors']++;
				continue;
			}

			if ($sAction === 'create') {
				$aFields = [];
				foreach ($aAttributes as $sAttCode => $aAttr) {
					if (($aAttr['update'] == '1' || $aAttr['reconcile'] == '1') && array_key_exists($sAttCode, $aValues) && $aValues[$sAttCode] !== null) {
						$aFields[$sAttCode] = $aValues[$sAttCode];
					}
				}
				try {
					$aReplica['dest_id'] = $this->CreateObject($sClass, $aFields);
					$aStats['created']++;
					$aStats['created_warnings'] += $iWarnings;
				} catch (FakeItopException $e) {
					$aStats['created_errors']++;
				}
			} elseif ($sAction === 'update') {
				$iId = reset($aMatches);
				$aReplica['dest_id'] = $iId;
				$aChanges = [];
				foreach ($aAttributes as $sAttCode => $aAttr) {
					if ($aAttr['update'] != '1' || !array_key_exists($sAttCode, $aValues) || $aValues[$sAttCode] === null) {
						continue;
					}
					$sCurrent = (string)($this->aObjects[$iId]['fields'][$sAttCode] ?? '');
					if ($aAttr['update_policy'] === 'write_if_empty' && $sCurrent !== '') {
						continue;
					}
					if ($sCurrent !== $aValues[$sAttCode]) {
						$aChanges[$sAttCode] = $aValues[$sAttCode];
					}
				}
				if (empty($aChanges)) {
					$aStats['unchanged']++;
				} else {
					$this->aObjects[$iId]['fields'] = array_merge($this->aObjects[$iId]['fields'], $aChanges);
					$aStats['updated']++;
					$aStats['updated_warnings'] += $iWarnings;
				}
			} else {
				// action 'error'
				if (count($aMatches) === 0) {
					$aStats['created_errors']++;
				} else {
					$aStats['updated_errors']++;
				}
			}
		}
		unset($aReplica);

		return $aStats;
	}

	/**
	 * External keys given with a reconciliation_attcode are turned into the id of the target object, when the target
	 * class is known. A value that matches nothing is ignored, with a warning.
	 *
	 * @return array<string, ?string>
	 */
	private function ResolveReplicaValues(string $sClass, array $aAttributes, array $aValues, int &$iWarnings): array
	{
		$aModel = $this->aClassModels[$sClass]['attributes'];
		foreach ($aValues as $sAttCode => $value) {
			$sTarget = $aModel[$sAttCode]['target'] ?? null;
			$sReconcAttCode = $aAttributes[$sAttCode]['reconciliation_attcode'] ?? '';
			if ($value === null || $value === '' || $sTarget === null || $sReconcAttCode === '' || !isset($this->aClassModels[$sTarget])) {
				continue;
			}
			$aTargets = array_keys($this->GetObjects($sTarget, [$sReconcAttCode => $value]));
			if (count($aTargets) === 1) {
				$aValues[$sAttCode] = (string)$aTargets[0];
			} else {
				unset($aValues[$sAttCode]);
				$iWarnings++;
			}
		}

		return $aValues;
	}
}

/**
 * An error answered by the fake iTop, with the code iTop would use.
 */
class FakeItopException extends Exception
{
}
