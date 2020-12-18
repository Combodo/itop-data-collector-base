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
	
	
	public function Get($sClass, $keySpec, $sOutputFields = '*')
	{
		$aOperation = array(
			'operation' => 'core/get', // operation code
			'class' => $sClass,
			'key' => $keySpec,
			'output_fields' => $sOutputFields, // list of fields to show in the results (* or a,b,c)
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}
	
	public function CheckCredentials($sUser, $sPassword)
	{
		$aOperation = array(
			'operation' => 'core/check_credentials', // operation code
			'user' => $sUser,
			'password' => $sPassword,
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}
	
	public function ListOperations()
	{
		$aOperation = array(
			'operation' => 'list_operations', // operation code
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}

	public function Create($sClass, $aFields, $sComment)
	{
		$aOperation = array(
			'operation' => 'core/create', // operation code
			'class' => $sClass,
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
			'fields' => $aFields,
			'comment' => $sComment,
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}
	
	public function Update($sClass, $keySpec, $aFields, $sComment)
	{
		$aOperation = array(
			'operation' => 'core/update', // operation code
			'class' => $sClass,
			'key' => $keySpec,
			'fields' => $aFields, // fields to update
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
			'comment' => $sComment,
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}
	
	public function GetRelatedObjects($sClass, $sKey, $sRelation, $bRedundancy = false, $iDepth = 99)
	{
		$aOperation = array(
			'operation' => 'core/get_related', // operation code
			'class' => $sClass,
			'key' => $sKey,
			'relation' => $sRelation,
			'depth' => $iDepth,
			'redundancy' => $bRedundancy,
		);
		return self::ExecOperation($aOperation, $this->sVersion);
	}
	
	protected static function ExecOperation($aOperation, $sVersion = '1.0')
	{
		$aData = array();
		$aData['auth_user'] = Utils::GetConfigurationValue('itop_login', '');
		$aData['auth_pwd'] = Utils::GetConfigurationValue('itop_password', '');
		$aData['json_data'] = json_encode($aOperation);
		$sUrl = Utils::GetConfigurationValue('itop_url', '').'/webservices/rest.php?login_mode=form&version='.$sVersion;
		$aHeaders = array();
		$aRawCurlOptions = Utils::GetConfigurationValue('curl_options', array());
		$aCurlOptions = array();
		foreach ($aRawCurlOptions as $key => $value) {
			// Convert strings like 'CURLOPT_SSLVERSION' to the value of the corresponding define i.e CURLOPT_SSLVERSION = 32 !
			// Make sure that the actual constant exists to prevent warnings/errors in case of typo or when php-curl is not loaded
			$iKey = ((!is_numeric($key)) && defined((string)$key)) ? constant((string)$key) : (int)$key;
			$iValue = ((!is_numeric($value)) && defined((string)$value)) ? constant((string)$value) : (int)$value;
			$aCurlOptions[$iKey] = $iValue;
		}
		$response = Utils::DoPostRequest($sUrl, $aData, null, $aHeaders, $aCurlOptions);
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
		foreach($aKnownVersions as $sVersion)
		{
			$oC->SetVersion($sVersion);
			$aRet = $oC->ListOperations();
			if ($aRet['code'] == 0)
			{
				// Supported version
				$sNewestVersion = $sVersion;
			}
		}
		return $sNewestVersion;		
	}
	/**
	 * Emulates the behavior of Get('*+') to retrieve all the characteristics
	 * of the attribute_list of a given synchro data source
	 * @param hash $aSource The definition of 'fields' the Synchro DataSource, as retrieved by Get
	 * @param integer $iSourceId The identifier (key) of the Synchro Data Source
	 */
	public static function GetFullSynchroDataSource(&$aSource, $iSourceId)
	{
		$bResult = true;
		$aAttributes = array();
		// Optimize the calls to the REST API: one call per finalclass
		foreach($aSource['attribute_list'] as $aAttr)
		{
			if (!array_key_exists($aAttr['finalclass'], $aAttributes))
			{
				$aAttributes[$aAttr['finalclass']] = array();
			}
			$aAttributes[$aAttr['finalclass']][] = $aAttr['attcode'];
		}
		
		$oRestClient = new RestClient();
		foreach($aAttributes as $sFinalClass => $aAttCodes)
		{
			Utils::Log(LOG_DEBUG, "RestClient::Get SELECT $sFinalClass WHERE attcode IN ('".implode("','", $aAttCodes)."') AND sync_source_id = $iSourceId");
			$aResult = $oRestClient->Get($sFinalClass, "SELECT $sFinalClass WHERE attcode IN ('".implode("','", $aAttCodes)."') AND sync_source_id = $iSourceId");
			if($aResult['code'] != 0)
			{
				Utils::Log(LOG_ERR, "{$aResult['message']} ({$aResult['code']})");
				$bResult = false;
			}
			else
			{
				// Update the SDS Attributes
				foreach($aSource['attribute_list'] as $idx => $aAttr)
				{
					foreach($aResult['objects'] as $aAttDef)
					{
						if ($aAttDef['fields']['attcode'] == $aAttr['attcode'])
						{
							$aSource['attribute_list'][$idx] = $aAttDef['fields'];
							
							// fix booleans
							$aSource['attribute_list'][$idx]['reconcile'] = $aAttDef['fields']['reconcile'] ? '1' : '0';
							$aSource['attribute_list'][$idx]['update'] = $aAttDef['fields']['update'] ? '1' : '0';
							
							// read-only (external) fields
							unset($aSource['attribute_list'][$idx]['sync_source_id']);
							unset($aSource['attribute_list'][$idx]['sync_source_name']);
							unset($aSource['attribute_list'][$idx]['sync_source_id_friendlyname']);
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
		}
		return $bResult;	
	}
}
