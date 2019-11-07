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
	
	/**
	 * Add a collector class to be run in the specified order
	 * @param float $fExecOrder The execution order (smaller numbers run first)
	 * @param string $sCollectorClass The class name of the collector. Must be a subclass of {@link Collector}
	 * @throws Exception
	 * @return void
	 */
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

	/**
	 * Specify a requirement for a minimum version: either for PHP or for a specific extension
	 * @param string $sMinRequiredVersion The minimum version number required
	 * @param string $sExtension The name of the extension, if not specified, then the requirement is for the PHP version itself
	 * @return void
	 */
	static public function AddRequirement($sMinRequiredVersion, $sExtension = 'PHP')
	{
		if (!array_key_exists($sExtension, self::$aMinVersions))
		{
			// This is the first call to add some requirements for this extension, record it as-is
		}
		else if (version_compare($sMinRequiredVersion, self::$aMinVersions[$sExtension], '>'))
		{
			// This requirement is stricter than the previously requested one
			self::$aMinVersions[$sExtension] = $sMinRequiredVersion;
		}
	}
	
	/**
	 * Check that all specified requirements are met, and log (LOG_ERR if not met, LOG_DEBUG if Ok)
	 * @return boolean True if it's Ok, false otherwise
	 */
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

	/**
	 * Returns the list of registered collectors, sorted in their execution order
	 * @return array An array of Collector instances
	 */
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

	/**
	 * Initializes the synchronization data sources in iTop, according to the collectors' JSON specifications
	 *
	 * @param string[] $aCollectors list of classes implementing {@link Collector}
	 *
	 * @return boolean True if Ok, false otherwise
	 * @throws \InvalidConfigException
	 */
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

		/** @var \Collector $oCollector */
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
	
	/**
	 * Run the first pass of data collection: fetching the raw data from inventory scripts
	 * @param string[] $aCollectors list of classes implementing {@link Collector}
	 * @param int $iMaxChunkSize
	 * @param boolean $bCollectOnly
	 * @return boolean True if Ok, false otherwise
	 */
	public function Collect($aCollectors, $iMaxChunkSize, $bCollectOnly)
	{
		$bResult = true;
		/** @var \Collector $oCollector */
		foreach($aCollectors as $oCollector)
		{
			$bResult = $oCollector->Collect($iMaxChunkSize, $bCollectOnly);
			if (!$bResult)
			{
				break;
			}
		}
		return $bResult;
	}
	
	/**
	 * Run the final pass of the collection: synchronizing the data into iTop
	 * @param string[] $aCollectors list of classes implementing {@link Collector}
	 * @return boolean
	 */
	public function Synchronize($aCollectors)
	{
		$bResult = true;
		$sStopOnError = Utils::GetConfigurationValue('stop_on_synchro_error', 'no');
		if (($sStopOnError != 'yes') && ($sStopOnError != 'no'))
		{
			Utils::Log(LOG_WARNING, "Unexpected value '$sStopOnError' for the parameter 'stop_on_synchro_error'. Will NOT stop on error. The expected values for this parameter are 'yes' or 'no'.");
		}
		$bStopOnError = ($sStopOnError == 'yes');
		/** @var \Collector $oCollector */
		foreach($aCollectors as $oCollector)
		{
			$bResult = $oCollector->Synchronize();
			if (!$bResult)
			{
				if ($bStopOnError)
				{
					break;
				}
				else
				{
					// Do not report the error (it impacts the return code of the process)
					$bResult = true;
				}
			}
		}
		return $bResult;
	}
	
	/////////////////////////////////////////////////////////////////////////
	//
	// Internal methods
	//
	/////////////////////////////////////////////////////////////////////////
	
	/**
	 * Helper callback for sorting the collectors using the built-in uasort function
	 * @param array $aCollector1
	 * @param array $aCollector2
	 * @return number
	 */
	static public function CompareCollectors($aCollector1, $aCollector2)
	{
        if ($aCollector1['order'] == $aCollector2['order'])
        {
            return 0;
        }
        return ($aCollector1['order'] > $aCollector2['order']) ? +1 : -1;
	}
	
}