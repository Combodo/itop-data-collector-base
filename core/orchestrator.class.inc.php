<?php
class Orchestrator
{
	static $aCollectors = array();
	
	static function AddCollector($fExecOrder, $sCollectorClass)
	{
		$oReflection = new ReflectionClass($sCollectorClass);
		if (!$oReflection->IsSubclassOf('Collector'))
		{
			throw new Exception('Cannot register a collector class ('.$sCollectorClass.') which is not derived from Collector.');
		}
		if ($oReflection->IsAbstract())
		{
			throw new Exception('Cannot register an abstract class ('.$sCollectorClass.') as a collector.');
		}
		self::$aCollectors[$sCollectorClass] = array('order' => $fExecOrder, 'class' => $sCollectorClass, 'sds_name' => '', 'sds_id' => 0);
	}
	
	public function ListCollectors()
	{
		$aResults = array();
		//Sort the collectors based on their order
		uasort(self::$aCollectors, array("Orchestrator", "CompareCollectors"));
		
		foreach(self::$aCollectors as $aCollectorData)
		{
			$aResults[] = new $aCollectorData['class']();
		}
		return $aResults;
	}
	
	public function InitSynchroDataSources($aCollectors)
	{
		$bResult = true;
		$aPlaceholders = array();
		$sEmailToNotify = Utils::GetConfigurationValue('contact_to_notify', '');
		$aPlaceholders['$contact_to_notify$'] = 0;
		if ($sEmailToNotify != '')
		{
			$oRestClient = new RestClient();
			$aRes = $oRestClient->Get('Person', array('email' => $sEmailToNotify));
			if ($aRes['code'] == 0)
			{
				$aObj = reset($aRes['objects']);
				$aPlaceholders['$contact_to_notify$'] = $aObj['key'];
				Utils::Log(LOG_INFO, "Contact to notify: '{$aObj['fields']['friendlyname']}' <{$aObj['fields']['email']}> ({$aObj['key']}).");
			}
			else
			{
				Utils::Log(LOG_ERR, "Unable to find the contact with email = '$sEmailToNotify'. No contact to notify will be defined.");
			}
		}
		$sSynchroUser = Utils::GetConfigurationValue('synchro_user', '');
		$aPlaceholders['$synchro_user$'] = 0;
		if ($sSynchroUser != '')
		{
			$oRestClient = new RestClient();
			$aRes = $oRestClient->Get('User', array('login' => $sSynchroUser));
			if ($aRes['code'] == 0)
			{
				$aObj = reset($aRes['objects']);
				$aPlaceholders['$synchro_user$'] = $aObj['key'];
				Utils::Log(LOG_INFO, "Synchro User: '{$aObj['fields']['friendlyname']}' ({$aObj['key']}).");
			}
			else
			{
				Utils::Log(LOG_ERR, "Unable to find user with login = '$sSynchroUser'. No user will be defined.");
			}
		}
		$aOtherPlaceholders = Utils::GetConfigurationValue('json_placeholders', array());
		foreach($aOtherPlaceholders as $sKey => $sValue)
		{
			$aPlaceholders['$'.$sKey.'$'] = $sValue;
		}
		
		foreach($aCollectors as $oCollector)
		{
			$bResult = $oCollector->InitSynchroDataSource($aPlaceholders);
			if (!$bResult)
			{
				break;
			}
		}
		return $bResult;
	}
	
	public function Collect($aCollectors, $iMaxChunkSize, $CollectOnly)
	{
		$bResult = true;
		foreach($aCollectors as $oCollector)
		{
			$bResult = $oCollector->Collect($iMaxChunkSize, $CollectOnly);
			if (!$bResult)
			{
				break;
			}
		}
		return $bResult;
	}
	
	public function Synchronize($aCollectors)
	{
		$bResult = true;
		foreach($aCollectors as $oCollector)
		{
			$bResult = $oCollector->Synchronize();
			if (!$bResult)
			{
				break;
			}
		}
		return $bResult;
	}
	
	/////////////////////////////////////////////////////////////////////////
	//
	// Internal methods
	//
	/////////////////////////////////////////////////////////////////////////
	
	static public function CompareCollectors($aCollector1, $aCollector2)
	{
        if ($aCollector1['order'] == $aCollector2['order'])
        {
            return 0;
        }
        return ($aCollector1['order'] > $aCollector2['order']) ? +1 : -1;
	}
	
}