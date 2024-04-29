<?php
// Copyright (C) 2014 Combodo SARL
//
//   This application is free software; you can redistribute it and/or modify
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with this application. If not, see <http://www.gnu.org/licenses/>

class RestClient
{
	protected $sVersion;
	/**
	 * @var string Keeps track of the latest date the datamodel has been installed/updated
	 * (in order to check which modules were installed with it)
	 */
	protected $sLastInstallDate;

	public function __construct()
	{
		$this->sVersion = '1.0';
	}

	public function GetVersion()
	{
		return $this->sVersion;
	}

	public function SetVersion($sVersion)
	{
		$this->sVersion = $sVersion;
	}


	public function Get($sClass, $keySpec, $sOutputFields = '*', $iLimit = 0)
	{
		$aOperation = array(
			'operation'     => 'core/get', // operation code
			'class'         => $sClass,
			'key'           => $keySpec,
			'output_fields' => $sOutputFields, // list of fields to show in the results (* or a,b,c)
			'limit'         => $iLimit,
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function CheckCredentials($sUser, $sPassword)
	{
		$aOperation = array(
			'operation' => 'core/check_credentials', // operation code
			'user'      => $sUser,
			'password'  => $sPassword,
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function ListOperations()
	{
		$aOperation = array(
			'operation'     => 'list_operations', // operation code
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function Create($sClass, $aFields, $sComment)
	{
		$aOperation = array(
			'operation'     => 'core/create', // operation code
			'class'         => $sClass,
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
			'fields'        => $aFields,
			'comment'       => $sComment,
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function Update($sClass, $keySpec, $aFields, $sComment)
	{
		$aOperation = array(
			'operation'     => 'core/update', // operation code
			'class'         => $sClass,
			'key'           => $keySpec,
			'fields'        => $aFields, // fields to update
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
			'comment'       => $sComment,
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function GetRelatedObjects($sClass, $sKey, $sRelation, $bRedundancy = false, $iDepth = 99)
	{
		$aOperation = array(
			'operation'  => 'core/get_related', // operation code
			'class'      => $sClass,
			'key'        => $sKey,
			'relation'   => $sRelation,
			'depth'      => $iDepth,
			'redundancy' => $bRedundancy,
		);

		return self::ExecOperation($aOperation, $this->sVersion);
	}

	protected static function ExecOperation($aOperation, $sVersion = '1.0')
	{
		$aData = Utils::GetCredentials();
		$aData['json_data'] = json_encode($aOperation);
		$sLoginform = Utils::GetLoginMode();
		$sUrl = sprintf('%s/webservices/rest.php?login_mode=%s&version=%s',
			Utils::GetConfigurationValue('itop_url', ''),
			$sLoginform,
			$sVersion
		);
		$aHeaders = array();
		$aCurlOptions = Utils::GetCurlOptions();
		$response = Utils::DoPostRequest($sUrl, $aData, '', $aHeaders, $aCurlOptions);
		$aResults = json_decode($response, true);
		if (!$aResults) {
			throw new Exception("rest.php replied: $response");
		}

		return $aResults;
	}

	public static function GetNewestKnownVersion()
	{
		$sNewestVersion = '1.0';
		$oC = new RestClient();
		$aKnownVersions = array('1.0', '1.1', '1.2', '2.0');
		foreach ($aKnownVersions as $sVersion) {
			$oC->SetVersion($sVersion);
			$aRet = $oC->ListOperations();
			if ($aRet['code'] == 0) {
				// Supported version
				$sNewestVersion = $sVersion;
			}
		}

		return $sNewestVersion;
	}

	/**
	 * Emulates the behavior of Get('*+') to retrieve all the characteristics
	 * of the attribute_list of a given synchro data source
	 *
	 * @param hash $aSource The definition of 'fields' the Synchro DataSource, as retrieved by Get
	 * @param integer $iSourceId The identifier (key) of the Synchro Data Source
	 */
	public static function GetFullSynchroDataSource(&$aSource, $iSourceId)
	{
		$bResult = true;
		$aAttributes = array();
		// Optimize the calls to the REST API: one call per finalclass
		foreach ($aSource['attribute_list'] as $aAttr) {
			if (!array_key_exists($aAttr['finalclass'], $aAttributes)) {
				$aAttributes[$aAttr['finalclass']] = array();
			}
			$aAttributes[$aAttr['finalclass']][] = $aAttr['attcode'];
		}

		$oRestClient = new RestClient();
		foreach ($aAttributes as $sFinalClass => $aAttCodes) {
			Utils::Log(LOG_DEBUG, "RestClient::Get SELECT $sFinalClass WHERE attcode IN ('".implode("','", $aAttCodes)."') AND sync_source_id = $iSourceId");
			$aResult = $oRestClient->Get($sFinalClass, "SELECT $sFinalClass WHERE attcode IN ('".implode("','", $aAttCodes)."') AND sync_source_id = $iSourceId");
			if ($aResult['code'] != 0) {
				Utils::Log(LOG_ERR, "{$aResult['message']} ({$aResult['code']})");
				$bResult = false;
			} else {
				// Update the SDS Attributes
				foreach ($aSource['attribute_list'] as $idx => $aAttr) {
					foreach ($aResult['objects'] as $aAttDef) {
						if ($aAttDef['fields']['attcode'] == $aAttr['attcode']) {
							$aSource['attribute_list'][$idx] = $aAttDef['fields'];

							// fix booleans
							$aSource['attribute_list'][$idx]['reconcile'] = $aAttDef['fields']['reconcile'] ? '1' : '0';
							$aSource['attribute_list'][$idx]['update'] = $aAttDef['fields']['update'] ? '1' : '0';

							// read-only (external) fields
							unset($aSource['attribute_list'][$idx]['friendlyname']);
							unset($aSource['attribute_list'][$idx]['sync_source_id']);
							unset($aSource['attribute_list'][$idx]['sync_source_name']);
							unset($aSource['attribute_list'][$idx]['sync_source_id_friendlyname']);
						}
					}
				}
			}
		}

		// Don't care about these read-only fields
		unset($aSource['friendlyname']);
		unset($aSource['user_id_friendlyname']);
		unset($aSource['user_id_finalclass_recall']);
		unset($aSource['notify_contact_id_friendlyname']);
		unset($aSource['notify_contact_id_finalclass_recall']);
		unset($aSource['notify_contact_id_obsolescence_flag']);

		return $bResult;
	}
	
	/**
	 * Check if the given module is installed in iTop.
	 * Mind that this assumes the `ModuleInstallation` class is ordered by descending installation date
	 *
	 * @param string $sName Name of the module to be found
	 * @param bool $bRequired Whether to throw exceptions when module not found
	 * @return bool True when the given module is installed, false otherwise
	 * @throws Exception When the module is required but could not be found
	 */
	public function CheckModuleInstallation(string $sName, bool $bRequired = false): bool
	{
		try {
			if (!isset($this->sLastInstallDate)) {
				$aDatamodelResults = static::Get('ModuleInstallation', ['name' => 'datamodel'], 'installed', 1);
				if ($aDatamodelResults['code'] != 0 || count($aDatamodelResults['objects']) === 0){
					throw new Exception($aDatamodelResults['message'], $aDatamodelResults['code']);
				}
				$aDatamodel = current($aDatamodelResults['objects']);
				$this->sLastInstallDate = $aDatamodel['fields']['installed'];
			}
			
			$aResults = static::Get('ModuleInstallation', ['name' => $sName, 'installed' => $this->sLastInstallDate], 'name,version', 1);
			if ($aResults['code'] != 0 || count($aResults['objects']) === 0) {
				throw new Exception($aResults['message'], $aResults['code']);
			}
			$aObject = current($aResults['objects']);
			Utils::Log(LOG_DEBUG, sprintf('iTop module %s version %s is installed.', $aObject['fields']['name'], $aObject['fields']['version']));
		} catch (Exception $e) {
			$sMessage = sprintf('%s iTop module %s is considered as not installed due to: %s', $bRequired ? 'Required' : 'Optional', $sName, $e->getMessage());
			if ($bRequired) {
				throw new Exception($sMessage, 0, $e);
			} else {
				Utils::Log(LOG_INFO, $sMessage);
				return false;
			}
		}
		return true;
	}
}
