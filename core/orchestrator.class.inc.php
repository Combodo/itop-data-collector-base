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

class Orchestrator
{
	static $aCollectors = array();
	static $aMinVersions = array('PHP' => '5.3.0', 'simplexml' => '0.1', 'dom' => '1.0');
	
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
	
	static public function AddRequirement($sMinRequiredVersion, $sExtension = 'PHP')
	{
		if (!array_key_exists($sExtension, self::$aMinVersions))
		{
			
		}
		else if (version_compare($sMinRequiredVersion, self::$aMinVersions[$sExtension], '>'))
		{
			 self::$aMinVersions[$sExtension] = $sMinRequiredVersion;
		}
	}
	
	static public function CheckRequirements()
	{
		$bResult = true;
		foreach(self::$aMinVersions as $sExtension => $sRequiredVersion)
		{
			if ($sExtension == 'PHP')
			{
				$sCurrentVersion = phpversion();
				if (version_compare($sCurrentVersion, $sRequiredVersion, '<'))
				{
					$bResult = false;
					Utils::Log(LOG_ERR, "The required PHP version to run this application is $sRequiredVersion. The current PHP version is only $sCurrentVersion.");
				}
				else
				{
					Utils::Log(LOG_DEBUG, "OK, the required PHP version to run this application is $sRequiredVersion. The current PHP version is $sCurrentVersion.");
				}
			}
			else if (extension_loaded($sExtension))
			{
				$sCurrentVersion = phpversion($sExtension);
				if (version_compare($sCurrentVersion, $sRequiredVersion, '<'))
				{
					$bResult = false;
					Utils::Log(LOG_ERR, "The extension '$sExtension' (version >= $sRequiredVersion) is required to run this application. The installed version is only $sCurrentVersion.");
				}
				else
				{
					Utils::Log(LOG_DEBUG, "OK, the required extension '$sExtension' is installed (current version: $sCurrentVersion >= $sRequiredVersion).");
				}
				
			}
			else
			{
				$bResult = false;
				Utils::Log(LOG_ERR, "The missing extension '$sExtension' (version >= $sRequiredVersion) is required to run this application.");
			}
		}
		return $bResult;
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
				if (!is_array($aRes['objects']))
				{
					Utils::Log(LOG_WARNING, "Contact to notify ($sEmailToNotify) not found in iTop. Nobody will be notified of the results of the synchronization.");
				}
				else
				{
					foreach($aRes['objects'] as $sKey => $aObj)
					{
						if(!array_key_exists('key', $aObj))
						{
							// Emulate the behavior for older versions of the API
							if(preg_match('/::([0-9]+)$/', $sKey, $aMatches))
							{
								$aPlaceholders['$contact_to_notify$'] = (int)$aMatches[1];
							}
						}
						else
						{
							$aPlaceholders['$contact_to_notify$'] = (int)$aObj['key'];
						}
						Utils::Log(LOG_INFO, "Contact to notify: '{$aObj['fields']['friendlyname']}' <{$aObj['fields']['email']}> ({$aPlaceholders['$contact_to_notify$']}).");
						break;
					}
				}
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
				foreach($aRes['objects'] as $sKey => $aObj)
				{
					if(!array_key_exists('key', $aObj))
					{
						// Emulate the behavior for older versions of the API
						if(preg_match('/::([0-9]+)$/', $sKey, $aMatches))
						{
							$aPlaceholders['$synchro_user$'] = (int)$aMatches[1];
						}
					}
					else
					{
						$aPlaceholders['$synchro_user$'] = (int)$aObj['key'];
					}
					Utils::Log(LOG_INFO, "Synchro User: '{$aObj['fields']['friendlyname']}' <{$aObj['fields']['email']}> ({$aPlaceholders['$synchro_user$']}).");
					break;
				}
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