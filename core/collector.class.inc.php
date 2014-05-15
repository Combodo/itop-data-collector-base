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

/**
 * Base class for all collectors
 *
 */
abstract class Collector
{

	protected $sSynchroDataSourceDefinitionFile;
	protected $sVersion;
	protected $iSourceId;
	protected $aFields;
	protected $aCSVHeaders;
	protected $aCSVFiles;
	protected $iFileIndex;
	protected $sErrorMessage;
	
	public function __construct()
	{
		$this->sSynchroDataSourceDefinitionFile = APPROOT.'collectors/'.get_class($this).'.json';
		$this->sVersion = null;
		$this->iSourceId = null;
		$this->aFields = array();
		$this->aCSVHeaders = array();
		$this->aCSVFiles = array();
		$this->iFileIndex = null;
		$this->sErrorMessage = '';
		
		$sJSONSourceDefinition = $this->GetSynchroDataSourceDefinition();
		if (empty($sJSONSourceDefinition))
		{
			Utils::Log(LOG_ERR, "Empty Synchro Data Source definition for the collector '".$this->GetName()."'");
			throw new Exception('Cannot create Collector (empty JSON definition)');
		}
		$aSourceDefinition = json_decode($sJSONSourceDefinition, true);

		if ($aSourceDefinition === null)
		{
			Utils::Log(LOG_ERR, "Invalid Synchro Data Source definition for the collector '".$this->GetName()."' (not a JSON string)");
			throw new Exception('Cannot create Collector (invalid JSON definition)');
		}
		$this->sSourceName = $aSourceDefinition['name'];
		foreach($aSourceDefinition['attribute_list'] as $aAttr)
		{
			$this->aFields[$aAttr['attcode']] = $aAttr['finalclass'];
		}
	}
	
	public function GetErrorMessage()
	{
		return $this->sErrorMessage;
	}
	
	protected function Fetch()
	{
		// Implement your own mechanism, unless you completely overload Collect()
	}
	
	protected function Prepare()
	{
		$this->RemoveDataFiles();
		$this->sSeparator = ';';
		
		return true;
	}
	
	protected function Cleanup()
	{
		fclose($this->aCSVFile[$this->iFileIndex]);
	}
	
	public function GetSynchroDataSourceDefinition($aPlaceHolders = array())
	{
		if (file_exists($this->sSynchroDataSourceDefinitionFile))
		{
			$aPlaceHolders['$version$'] = $this->GetVersion();
			$sSynchroDataSourceDefinition = file_get_contents($this->sSynchroDataSourceDefinitionFile);
			$sSynchroDataSourceDefinition = str_replace(array_keys($aPlaceHolders), array_values($aPlaceHolders), $sSynchroDataSourceDefinition);
		}
		else
		{
			$sSynchroDataSourceDefinition = false;
		}
		return $sSynchroDataSourceDefinition;
	}
	
	public function GetName()
	{
		return get_class($this);
	}
	
	public function GetVersion()
	{
		if ($this->sVersion == null)
		{
			$this->GetVersionFromModuleFile();	
		}
		return $this->sVersion;
	}
	
	/////////////////////////////////////////////////////////////////////////
	
	/**
	 * Extracts the version number by evaluating the content of the first module.xxx.php file found
	 * in the "collector" directory.
	 */
	protected function GetVersionFromModuleFile()
	{
		$aFiles = glob(APPROOT.'collectors/module.*.php');
		$sModuleFile = null;
		$sModuleFile = reset($aFiles);
		if ($sModuleFile == null)
		{
			// No module found, use a default value...
			$this->sVersion = '1.0.0';
		}
		
		try
		{
			$sModuleFileContents = file_get_contents($sModuleFile);
			$sModuleFileContents = str_replace(array('<?php', '?>'), '', $sModuleFileContents);
			$sModuleFileContents = str_replace('SetupWebPage::AddModule(', '$this->InitVersionCallback(', $sModuleFileContents);
			$bRet = eval($sModuleFileContents);
			
			if ($bRet === false)
			{
				Utils::Log(LOG_WARNING, "Eval of '$sModuleFileContents' returned false");
			}
		}
		catch(Exception $e)
		{
			// Continue...
			Utils::Log(LOG_WARNING, "Eval of '$sModuleFileContents' caused an exception: ".$e->getMessage());
		}		
	}
	
	/**
	 * Sets the $sVersion property. Called when eval'uating the content of the module file
	 * 
	 * @param string $void1 Unused
	 * @param string $sId The identifier of the module. Format:  'name/version'
	 * @param array $void2 Unused
	 */
	protected function InitVersionCallback($void1, $sId, $void2)
	{
		if (preg_match('!^(.*)/(.*)$!', $sId, $aMatches))
		{
			$this->sVersion = $aMatches[2];
		}
		else
		{
			$this->sVersion = "1.0.0";
		}
	}
	public function InitSynchroDataSource($aPlaceholders)
	{
		$bResult = true;
		$sJSONSourceDefinition = $this->GetSynchroDataSourceDefinition($aPlaceholders);
		$aExpectedSourceDefinition = json_decode($sJSONSourceDefinition, true);
		
		try
		{
			$oRestClient = new RestClient();
			$aResult = $oRestClient->Get('SynchroDataSource', array('name' => $this->sSourceName));
			if ($aResult['code'] != 0)
			{
				Utils::Log(LOG_ERR, "{$aResult['message']} ({$aResult['code']})");
				$bResult = false;
			}
			else 
			{
				switch(count($aResult['objects']))
				{
					case 0:
					// not found, need to create the Source
					Utils::Log(LOG_INFO, "There is no Synchro Data Source named '{$this->sSourceName}' in iTop. Let's create it.");
					$key = $this->CreateSynchroDataSource($aExpectedSourceDefinition, $this->GetName());
					if ($key === false)
					{
						$bResult = false;
					}
					break;
					
					case 1:
					// Ok, found, is it up to date ?
					$aData = reset($aResult['objects']);
					$aCurrentSourceDefinition = $aData['fields'];
					$this->iSourceId = $aData['key'];
					if ($aExpectedSourceDefinition == $aCurrentSourceDefinition)
					{
						Utils::Log(LOG_INFO, "Ok, the Synchro Data Source '{$this->sSourceName}' exists in iTop and is up to date");
					}
					else
					{
						Utils::Log(LOG_INFO, "The Synchro Data Source definition for '{$this->sSourceName}' must be updated in iTop.");
						$bResult = $this->UpdateSynchroDataSource($aExpectedSourceDefinition, $this->GetName());
					}					
					break;
					
					default:
					// Ambiguous !!
					Utils::Log(LOG_ERR, "There are ".count($aResult['objects'])." Synchro Data Sources named '{$this->sSourceName}' in iTop. Cannot continue.");
					$bResult = false;
				}
			}
		}
		catch(Exception $e)
		{
			Utils::Log(LOG_ERR, $e->getMessage());
			$bResult = false;
		}
		return $bResult;
	}
	
	public function Collect($iMaxChunkSize = 0)
	{
		$bResult = true;
		Utils::Log(LOG_INFO, get_class($this)." beginning of data collection...");
		$bResult = $this->Prepare();
		if ($bResult)
		{
			$idx = 0;
			$aColumns = array();
			$aHeaders = null;
			while($aRow = $this->Fetch())
			{
				if ($aHeaders == null)
				{
					// Check that the row names are consistent with the definition of the task
					$aHeaders = array_keys($aRow);
				}
				
				if (($idx == 0) || (($iMaxChunkSize > 0) && (($idx % $iMaxChunkSize) == 0)))
				{
					$this->NextCSVFile();
					$this->AddHeader($aHeaders);
				}
				
				$this->AddRow($aRow);
				
				$idx++;
			}
			$this->Cleanup();
			Utils::Log(LOG_INFO,  get_class($this)." end of data collection.");
		}
		else
		{
			Utils::Log(LOG_ERR, get_class($this)."::Prepare() returned false");
		}
		return $bResult;
	}
	
	protected function AddHeader($aHeaders)
	{
		$this->aCSVHeaders = array();
		foreach($aHeaders as $sHeader)
		{
			if (($sHeader != 'primary_key') && !array_key_exists($sHeader, $this->aFields))
			{
				Utils::Log(LOG_WARNING, "Invalid column '$sHeader', will be ignored.");
			}
			else
			{
				$this->aCSVHeaders[] = $sHeader;
			}
		}
		fwrite($this->aCSVFile[$this->iFileIndex], implode($this->sSeparator, $this->aCSVHeaders)."\n"); //TODO: proper CSV encoding	
	}
	
	protected function AddRow($aRow)
	{
		$aData = array();
		foreach($this->aCSVHeaders as $sHeader)
		{
			$aData[] = $aRow[$sHeader];
		}
		fwrite($this->aCSVFile[$this->iFileIndex], implode($this->sSeparator, $aData)."\n"); //TODO: proper CSV encoding	
	}
	
	protected function OpenCSVFile()
	{
		$bResult = true;
		$sDataFile = Utils::GetDataFilePath(get_class($this).'-'.(1+$this->iFileIndex).'.csv');
		$this->aCSVFile[$this->iFileIndex] = fopen($sDataFile, 'wb');
		
		if ($this->aCSVFile[$this->iFileIndex] === false)
		{
			Utils::Log(LOG_ERR, "Unable to open the file '$sDataFile' for writing.");
			$bResult = false;
		}
		else
		{
			Utils::Log(LOG_INFO, "Writing to file '$sDataFile'.");
		}
		return $bResult;		
	}
	protected function NextCSVFile()
	{
		if ($this->iFileIndex !== null)
		{
			fclose($this->aCSVFile[$this->iFileIndex]);
			$this->aCSVFile[$this->iFileIndex] = false;
			$this->iFileIndex++;
		}
		else
		{
			$this->iFileIndex = 0;
		}
		
		return $this->OpenCSVFile();
	}
	
	protected function RemoveDataFiles()
	{
		$aFiles = glob(Utils::GetDataFilePath(get_class($this).'-*.csv'));
		foreach($aFiles as $sFile)
		{
			$bResult = @unlink($sFile);
			Utils::Log(LOG_DEBUG, "Erasing previous data file. unlink('$sFile') returned ".($bResult ? 'true' : 'false'));
		}		
	}
	
	public function Synchronize($iMaxChunkSize = 0)
	{
		$aFiles = glob(Utils::GetDataFilePath(get_class($this).'-*.csv'));
		foreach($aFiles as $sDataFile)
		{
			Utils::Log(LOG_INFO, "Uploading data file '$sDataFile'"); 
			// Load by chunk
			$aData = array(
				'separator' => ';',
				'auth_user' => Utils::GetConfigurationValue('itop_login', ''),
				'auth_pwd' => Utils::GetConfigurationValue('itop_password', ''),
				'data_source_id' => $this->iSourceId,
				'synchronize' => '0',
				'csvdata' => file_get_contents($sDataFile),
			);
			$sUrl = Utils::GetConfigurationValue('itop_url', '').'/synchro/synchro_import.php';
			$sResult = Utils::DoPostRequest($sUrl, $aData);
		}
		// Synchronize... also by chunks...
		Utils::Log(LOG_INFO, "Starting synchronization of the data source '{$this->sSourceName}'...");
		$aData = array(
			'auth_user' => Utils::GetConfigurationValue('itop_login', ''),
			'auth_pwd' => Utils::GetConfigurationValue('itop_password', ''),
			'data_sources' => $this->iSourceId,
		);
		if ($iMaxChunkSize > 0)
		{
			$aData['max_chunk_size'] = $iMaxChunkSize;
		}
		$sUrl = Utils::GetConfigurationValue('itop_url', '').'/synchro/synchro_exec.php';
		$sResult = Utils::DoPostRequest($sUrl, $aData);
		
		$iErrorsCount = 0;
		if (preg_match_all('/Objects (.*) errors: ([0-9]+)/', $sResult, $aMatches))
		{
			foreach($aMatches[2] as $idx => $sErrCount)
			{
				$iErrorsCount += (int)$sErrCount;
				if ((int)$sErrCount > 0)
				{
					Utils::Log(LOG_ERR, "Synchronization of data source '{$this->sSourceName}' answered: {$aMatches[0][idx]}");
					$this->sErrorMessage .= $aMatches[0][idx]."\n";
				}
			}
		}
		if ($iErrorsCount == 0)
		{
			Utils::Log(LOG_INFO, "Synchronization of data source '{$this->sSourceName}' succeeded.");
		}
		return ($iErrorsCount == 0);
	}
	
	/////////////////////////////////////////////////////////////////////////
	//
	// Protected methods
	//
	/////////////////////////////////////////////////////////////////////////	
	protected function CreateSynchroDataSource($aSourceDefinition, $sComment)
	{
		$oClient = new RestClient();
		$ret = false;

		// Ignore read-only fields
		unset($aSourceDefinition['friendlyname']);
		unset($aSourceDefinition['user_id_friendlyname']);
		unset($aSourceDefinition['user_id_finalclass_recall']);
		unset($aSourceDefinition['notify_contact_id_friendlyname']);
		unset($aSourceDefinition['notify_contact_id_finalclass_recall']);
		// SynchroAttributes will be processed one by one, below
		$aSynchroAttr = $aSourceDefinition['attribute_list'];
		unset($aSourceDefinition['attribute_list']);
				
		$aResult = $oClient->Create('SynchroDataSource', $aSourceDefinition, $sComment);
		if ($aResult['code'] == 0)
		{
			$aCreatedObj = reset($aResult['objects']);
			$aExpectedAttrDef = $aCreatedObj['fields']['attribute_list'];
			$iKey = (int)$aCreatedObj['key'];
			$this->iSourceId = $iKey;
			
			if ($this->UpdateSDSAttributes($aExpectedAttrDef, $aSynchroAttr, $sComment))
			{
				$ret = $this->iSourceId;
			}
		}
		else
		{
			Utils::Log(LOG_ERR, "Failed to create the SynchroDataSource '{$aSourceDefinition['name']}'. Reason: {$aResult['message']} ({$aResult['code']})");
		}
		
		return $ret;
	}

	protected function UpdateSynchroDataSource($aSourceDefinition, $sComment)
	{
		$bRet = true;
		$oClient = new RestClient();

		// Ignore read-only fields
		unset($aSourceDefinition['friendlyname']);
		unset($aSourceDefinition['user_id_friendlyname']);
		unset($aSourceDefinition['user_id_finalclass_recall']);
		unset($aSourceDefinition['notify_contact_id_friendlyname']);
		unset($aSourceDefinition['notify_contact_id_finalclass_recall']);
		// SynchroAttributes will be processed one by one, below
		$aSynchroAttr = $aSourceDefinition['attribute_list'];
		unset($aSourceDefinition['attribute_list']);
		
		$aResult = $oClient->Update('SynchroDataSource', $this->iSourceId, $aSourceDefinition, $sComment);
		$bRet = ($aResult['code'] == 0);
		if ($bRet)
		{
			$aUpdatedObj = reset($aResult['objects']);
			$aExpectedAttrDef = $aUpdatedObj['fields']['attribute_list'];
			$bRet = $this->UpdateSDSAttributes($aExpectedAttrDef, $aSynchroAttr, $sComment);
		}
		else
		{
			Utils::Log(LOG_ERR, "Failed to update the SynchroDataSource '{$aSourceDefinition['name']}' ({$this->iSourceId}). Reason: {$aResult['message']} ({$aResult['code']})");
		}
		
		return $bRet;
	}
	
	protected function UpdateSDSAttributes($aExpectedAttrDef, $aSynchroAttrDef, $sComment)
	{
		$bRet = true;
		$oClient = new RestClient();
		
		foreach($aSynchroAttrDef as $aAttr)
		{
			$aExpectedAttr = $this->FindAttr($aAttr['attcode'], $aExpectedAttrDef);
			
			if ($aAttr != $aExpectedAttr)
			{
				// Update only the SynchroAttributes which are really different			
				// Ignore read-only fields
				unset($aAttr['friendlyname']);
				$sTargetClass = $aAttr['finalclass'];
				unset($aAttr['finalclass']);
				// Fix booleans
				$aAttr['update'] = ($aAttr['update'] == 1) ? "1" : "0";
				$aAttr['reconcile'] = ($aAttr['reconcile'] == 1) ? "1" : "0";
				
				$aResult = $oClient->Update($sTargetClass, array( 'sync_source_id' => $this->iSourceId, 'attcode' => $aAttr['attcode']), $aAttr, $sComment);
				$bRet = ($aResult['code'] == 0);
				if (!$bRet)
				{
					Utils::Log(LOG_ERR, "Failed to update the SynchroAttribute '{$aAttr['attcode']}'. Reason: {$aResult['message']} ({$aResult['code']})");
					break;
				}
			}
		}
		return $bRet;		
	}
	
	protected function FindAttr($sAttCode, $aExpectedAttrDef)
	{
		foreach($aExpectedAttrDef as $aAttr)
		{
			if ($aAttr['attcode'] == $sAttCode)
			{
				return $aAttr;
			}
		}
		return false;
	}
}