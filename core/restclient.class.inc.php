<?php
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
	
	
	public function Get($sClass, $keySpec)
	{
		$aOperation = array(
			'operation' => 'core/get', // operation code
			'class' => $sClass,
			'key' => $keySpec,
			'output_fields' => '*', // list of fields to show in the results (* or a,b,c)
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
	
	protected static function ExecOperation($aOperation, $sVersion = '1.0')
	{
		/*
		$sChangeTracking = MetaModel::GetModuleSetting('itop-rest-data-push', 'change_tracking', Dict::S('rest-data-push:change_tracking'));
		if ($sChangeTracking == '')
		{
			$aOperation['comment'] = UserRights::GetUserFriendlyName();
		}
		else
		{
			$aOperation['comment'] = sprintf($sChangeTracking, UserRights::GetUserFriendlyName());
		}
		*/
		
		$aData = array();
		$aData['auth_user'] = Utils::GetConfigurationValue('itop_login', '');
		$aData['auth_pwd'] = Utils::GetConfigurationValue('itop_password', '');
		$aData['json_data'] = json_encode($aOperation);
//print_r($aOperation);
		$sUrl = Utils::GetConfigurationValue('itop_url', '').'/webservices/rest.php?version='.$sVersion;
		$response = Utils::DoPostRequest($sUrl, $aData);
		$aResults = json_decode($response, true);
		if (!$aResults)
		{
			throw new Exception("rest.php replied: $response");
		}
//print_r($aResults);
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
}