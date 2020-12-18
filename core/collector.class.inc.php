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
 * Special kind of exception to tell the collector to ignore the row of data being processed
 */
class IgnoredRowException extends Exception
{
}
class InvalidConfigException extends Exception
{
}



/**
 * Base class for all collectors
 *
 */
abstract class Collector
{
	/**
	 * @var string TABLENAME_PATTERN used to validate data synchro table name
	 * @see N°2417
	 */
	const TABLENAME_PATTERN = '/^[A-Za-z0-9_]*$/';

	protected $sSynchroDataSourceDefinitionFile;
	protected $sVersion;
	protected $iSourceId;
	protected $aFields;
	protected $aCSVHeaders;
	protected $aCSVFile;
	protected $iFileIndex;
	protected $sErrorMessage;
	protected $sSeparator;
	protected $aSkippedAttributes;
	
	public function __construct()
	{
		$this->sSynchroDataSourceDefinitionFile = APPROOT.'collectors/'.get_class($this).'.json';
		$this->sVersion = null;
		$this->iSourceId = null;
		$this->aFields = array();
		$this->aCSVHeaders = array();
		$this->aCSVFile = array();
		$this->iFileIndex = null;
		$this->sErrorMessage = '';
		$this->sSeparator = ';';
		$this->aSkippedAttributes = array();
		
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
		foreach($aSourceDefinition['attribute_list'] as $aAttr)
		{
			$this->aFields[$aAttr['attcode']] = array( 'class' => $aAttr['finalclass'], 'update' => ($aAttr['update'] != 0), 'reconcile' => ($aAttr['reconcile'] != 0) );
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
		if ($this->iFileIndex !== null)
		{
			fclose($this->aCSVFile[$this->iFileIndex]);
		}
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
	
	/**
	 * Determine if a given attribute can be missing in the data datamodel.
	 * 
	 * Overload this method to let your collector adapt to various datamodels. If an attribute is skipped,
	 * its name is recorded in the member variable $this->aSkippedAttributes for later reference.
	 * @param string $sAttCode
	 * @return boolean True if the attribute can be skipped, false otherwise
	 */
	public function AttributeIsOptional($sAttCode)
	{
		return false; // By default no attribute is optional
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
	
	/**
	 * Overload this method (to return true) if the collector has
	 * to reprocess the CSV file (with an access to iTop)
	 * before executing the synchro with iTop
	 * @return bool
	 */
	protected function MustProcessBeforeSynchro()
	{
		// Overload this method (to return true) if the collector has
		// to reprocess the CSV file (with an access to iTop)
		// before executing the synchro with iTop
		return false;
	}
	
	/**
	 * Overload this method to perform any one-time initialization which
	 * may be required before processing the CSV file line by line
	 * @return void
	*/
	protected function InitProcessBeforeSynchro()
	{
	}
	
	/**
	 * Overload this method to process each line of the CSV file
	 * Should you need to "reject" the line from the ouput, throw an exception of class IgnoredRowException
	 * Example:
	 * throw new IgnoredRowException('Explain why the line is rejected - visible in the debug output');
	 * 
	 * @param array $aLineData
	 * @param int $iLineIndex
	 * @throws IgnoredRowException
	 * @return void
	 */
	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex)
	{
	}
	
	protected function DoProcessBeforeSynchro()
	{
		$this->InitProcessBeforeSynchro();
		
		$aFiles = glob(Utils::GetDataFilePath(get_class($this).'.raw-*.csv'));
		foreach($aFiles as $sDataFile)
		{
			Utils::Log(LOG_INFO, "Processing '$sDataFile'...");
			// Warning backslashes inside the file path (like C:\ on Windows) must be escaped (i.e. \ => \\), but inside a PHP string \ is written '\\' so \\ becomes '\\\\' !!
			$sPattern = '|'.str_replace('\\', '\\\\', Utils::GetDataFilePath(get_class($this))).'\\.raw-([0-9]+)\\.csv$|';
			if(preg_match($sPattern, $sDataFile, $aMatches))
			{
				$idx = $aMatches[1];
				$sOutputFile = Utils::GetDataFilePath(get_class($this).'-'.$idx.'.csv');
				Utils::Log(LOG_DEBUG, "Converting '$sDataFile' to '$sOutputFile'...");
				ini_set('auto_detect_line_endings', true);
				
				$hCSV = fopen($sDataFile,'r');
				if ($hCSV === false)
				{
					Utils::Log(LOG_ERR, "Failed to open '$sDataFile' for reading... file will be skipped.");
				}
				
				$hOutputCSV = fopen($sOutputFile, 'w');
				if ($hOutputCSV === false)
				{
					Utils::Log(LOG_ERR, "Failed to open '$sOutputFile' for writing... file will be skipped.");
				}
				
				if (($hCSV !== false) && ($hOutputCSV !== false))
				{
					$iLineIndex = 0;
					while (($aData = fgetcsv($hCSV, 10000, $this->sSeparator)) !== false)
					{
						//process
						try
						{
							$this->ProcessLineBeforeSynchro($aData, $iLineIndex);
							// Write the CSV data
							fputcsv($hOutputCSV, $aData, $this->sSeparator);
						}
						catch(IgnoredRowException $e)
						{
							// Skip this line
							Utils::Log(LOG_DEBUG, "Ignoring the line $iLineIndex. Reason: ".$e->getMessage());
						}
						$iLineIndex++;
					}
					fclose($hCSV);
					fclose($hOutputCSV);
					Utils::Log(LOG_INFO, "End of processing of '$sDataFile'...");
				}
			}
			else
			{
				Utils::Log(LOG_DEBUG, "'$sDataFile' does not match '$sPattern'... file will be skipped.");
			}
		}
	}
	
	/**
	 * Overload this method if the data collected is in a different character set
	 * @return string The name of the character set in which the collected data are encoded
	 */
	protected function GetCharset()
	{
		return 'UTF-8';
	}
	
	/////////////////////////////////////////////////////////////////////////
	
	/**
	 * Extracts the version number by evaluating the content of the first module.xxx.php file found
	 * in the "collector" directory.
	 */
	protected function GetVersionFromModuleFile()
	{
		$aFiles = glob(APPROOT.'collectors/module.*.php');
		if (!$aFiles)
		{
			// No module found, use a default value...
			$this->sVersion = '1.0.0';
            Utils::Log(LOG_INFO, "Please create a 'module.*.php' file in 'collectors' folder in order to define the version of collectors");
			return;
		}
		
		try
		{
			$sModuleFile = reset($aFiles);
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
	
	/**
	 * Inspects the definition of the Synchro Data Source to find inconsistencies
	 * @param mixed[] $aExpectedSourceDefinition
	 * @throws Exception
	 * @return void
	 */
	protected function CheckDataSourceDefinition($aExpectedSourceDefinition)
	{
	    Utils::Log(LOG_DEBUG, "Checking the configuration of the data source '{$aExpectedSourceDefinition['name']}'...");
	    
	    // Check that there is at least 1 reconciliation key, if the reconciliation_policy is "use_attributes"
	    if ($aExpectedSourceDefinition['reconciliation_policy'] == 'use_attributes')
	    {
		    $bReconciliationKeyFound = false;
		    foreach($aExpectedSourceDefinition['attribute_list'] as $aAttributeDef)
		    {
		        if ($aAttributeDef['reconcile'] == '1')
		        {
		            $bReconciliationKeyFound = true;
		            break;
		        }
		    }
		    if (!$bReconciliationKeyFound)
		    {
		        throw new InvalidConfigException("Collector::CheckDataSourceDefinition: Missing reconciliation key for data source '{$aExpectedSourceDefinition['name']}'. " .
		           "At least one attribute in 'attribute_list' must have the flag 'reconcile' set to '1'.");
		    }
	    }
	    
	    // Check the database table name for invalid characters
	    $sDatabaseTableName = $aExpectedSourceDefinition['database_table_name'];
	    if (!preg_match(self::TABLENAME_PATTERN, $sDatabaseTableName))
	    {
	        throw new InvalidConfigException("Collector::CheckDataSourceDefinition: '{$aExpectedSourceDefinition['name']}' invalid characters in database_table_name, ".
	            "current value is '$sDatabaseTableName'");
	    }
	    
	    Utils::Log(LOG_DEBUG, "The configuration of the data source '{$aExpectedSourceDefinition['name']}' looks correct.");
	}

	public function InitSynchroDataSource($aPlaceholders)
	{
		$bResult = true;
		$sJSONSourceDefinition = $this->GetSynchroDataSourceDefinition($aPlaceholders);
		$aExpectedSourceDefinition = json_decode($sJSONSourceDefinition, true);
		$this->CheckDataSourceDefinition($aExpectedSourceDefinition);

		$this->sSourceName = $aExpectedSourceDefinition['name'];
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
			    $iCount = ($aResult['objects'] !== null ) ? count($aResult['objects']) : 0;
			    switch($iCount)
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
					foreach($aResult['objects'] as $sKey => $aData)
					{
						// Ok, found, is it up to date ?
						$aData = reset($aResult['objects']);
						$aCurrentSourceDefinition = $aData['fields'];
						if(!array_key_exists('key', $aData))
						{
							// Emulate the behavior for older versions of the API
							if(preg_match('/::([0-9]+)$/', $sKey, $aMatches))
							{
								$iKey = (int)$aMatches[1];
							}
						}
						else
						{
							$iKey = (int)$aData['key'];
						}
						$this->iSourceId = $iKey;
						RestClient::GetFullSynchroDataSource($aCurrentSourceDefinition, $this->iSourceId);
						if ($this->DataSourcesAreEquivalent($aExpectedSourceDefinition, $aCurrentSourceDefinition))
						{
							Utils::Log(LOG_INFO, "Ok, the Synchro Data Source '{$this->sSourceName}' exists in iTop and is up to date");
						}
						else
						{
							Utils::Log(LOG_INFO, "The Synchro Data Source definition for '{$this->sSourceName}' must be updated in iTop.");
							// For debugging...
							file_put_contents(APPROOT.'data/tmp-'.get_class($this).'-orig.txt', print_r($aExpectedSourceDefinition, true));
							file_put_contents(APPROOT.'data/tmp-'.get_class($this).'-itop.txt', print_r($aCurrentSourceDefinition, true));
							$bResult = $this->UpdateSynchroDataSource($aExpectedSourceDefinition, $this->GetName());
						}
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
		try
		{
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
		}
		catch(Exception $e)
		{
			$bResult = false;
			Utils::Log(LOG_ERR, get_class($this)."::Collect() got an exception: ".$e->getMessage());
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
				if (!in_array($sHeader, $this->aSkippedAttributes))
				{
					Utils::Log(LOG_WARNING, "Invalid column '$sHeader', will be ignored.");
				}
			}
			else
			{
				$this->aCSVHeaders[] = $sHeader;
			}
		}
		//fwrite($this->aCSVFile[$this->iFileIndex], implode($this->sSeparator, $this->aCSVHeaders)."\n");
		fputcsv($this->aCSVFile[$this->iFileIndex], $this->aCSVHeaders, $this->sSeparator);
	}
	
	protected function AddRow($aRow)
	{
		$aData = array();
		foreach($this->aCSVHeaders as $sHeader)
		{
			$aData[] = $aRow[$sHeader];
		}
		//fwrite($this->aCSVFile[$this->iFileIndex], implode($this->sSeparator, $aData)."\n");	
		fputcsv($this->aCSVFile[$this->iFileIndex], $aData, $this->sSeparator);
	}
	
	protected function OpenCSVFile()
	{
		$bResult = true;
		if ($this->MustProcessBeforeSynchro())
		{
			$sDataFile = Utils::GetDataFilePath(get_class($this).'.raw-'.(1+$this->iFileIndex).'.csv');
		}
		else
		{
			$sDataFile = Utils::GetDataFilePath(get_class($this).'-'.(1+$this->iFileIndex).'.csv');
		}
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
		$aFiles = glob(Utils::GetDataFilePath(get_class($this).'.raw-*.csv'));
		foreach($aFiles as $sFile)
		{
			$bResult = @unlink($sFile);
			Utils::Log(LOG_DEBUG, "Erasing previous data file. unlink('$sFile') returned ".($bResult ? 'true' : 'false'));
		}		
	}
	
	public function Synchronize($iMaxChunkSize = 0)
	{
		// Let a chance to the collector to alter/reprocess the CSV file
		// before running the synchro. This is useful for performing advanced lookups
		// in iTop, for example for some Typology with several cascading levels that
		// the data synchronization can not handle directly
		if ($this->MustProcessBeforeSynchro())
		{
			$this->DoProcessBeforeSynchro();
		}
		
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
				'no_stop_on_import_error' => 1,
				'output' => 'retcode',
				'csvdata' => file_get_contents($sDataFile),
				'charset' => $this->GetCharset(),
			);
			$sUrl = Utils::GetConfigurationValue('itop_url', '').'/synchro/synchro_import.php?login_mode=form';
			$iSynchroTimeout = (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600); // timeout in seconds, for a synchro to run

			$aResponseHeaders = null;

			$aRawCurlOptions = Utils::GetConfigurationValue('curl_options', array(CURLOPT_SSLVERSION => CURL_SSLVERSION_SSLv3));
			$aCurlOptions = array();
			foreach ($aRawCurlOptions as $key => $value) {
				// Convert strings like 'CURLOPT_SSLVERSION' to the value of the corresponding define i.e CURLOPT_SSLVERSION = 32 !
				$iKey = (!is_numeric($key)) ? constant((string)$key) : (int)$key;
				$iValue = (!is_numeric($value)) ? constant((string)$value) : (int)$value;
				$aCurlOptions[$iKey] = $iValue;
			}
			$aCurlOptions[CURLOPT_CONNECTTIMEOUT] = $iSynchroTimeout;
			$aCurlOptions[CURLOPT_TIMEOUT] = $iSynchroTimeout;

			$sResult = Utils::DoPostRequest($sUrl, $aData, null, $aResponseHeaders, $aCurlOptions);

			// Read the status code from the last line
			$aLines = explode("\n", trim(strip_tags($sResult)));
			$sLastLine = array_pop($aLines);
			if ($sLastLine != '0')
			{
				// hmm something went wrong
				Utils::Log(LOG_ERR, "Failed to import the data from '$sDataFile' into iTop. $sLastLine line(s) had errors.");
				Utils::Log(LOG_ERR, trim(strip_tags($sResult)));
				return false;
			}
			
		}
		// Synchronize... also by chunks...
		Utils::Log(LOG_INFO, "Starting synchronization of the data source '{$this->sSourceName}'...");
		$aData = array(
			'auth_user' => Utils::GetConfigurationValue('itop_login', ''),
			'auth_pwd' => Utils::GetConfigurationValue('itop_password', ''),
			'data_sources' => $this->iSourceId,
		);
		if ($iMaxChunkSize > 0) {
			$aData['max_chunk_size'] = $iMaxChunkSize;
		}
		$sUrl = Utils::GetConfigurationValue('itop_url', '').'/synchro/synchro_exec.php?login_mode=form';
		$iSynchroTimeout = (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600); // timeout in seconds, for a synchro to run

		$aResponseHeaders = null;

		$aRawCurlOptions = Utils::GetConfigurationValue('curl_options', array(CURLOPT_SSLVERSION => CURL_SSLVERSION_SSLv3));
		$aCurlOptions = array();
		foreach ($aRawCurlOptions as $key => $value) {
			// Convert strings like 'CURLOPT_SSLVERSION' to the value of the corresponding define i.e CURLOPT_SSLVERSION = 32 !
			$iKey = (!is_numeric($key)) ? constant((string)$key) : (int)$key;
			$iValue = (!is_numeric($value)) ? constant((string)$value) : (int) $value;
			$aCurlOptions[$iKey] = $iValue;
		}
		$aCurlOptions[CURLOPT_CONNECTTIMEOUT] = $iSynchroTimeout;
        $aCurlOptions[CURLOPT_TIMEOUT] = $iSynchroTimeout;

		$sResult = Utils::DoPostRequest($sUrl, $aData, null, $aResponseHeaders, $aCurlOptions);
		
		$iErrorsCount = 0;
		if (preg_match_all('|<input type="hidden" name="loginop" value="login"|', $sResult, $aMatches))
		{
			// Hmm, it seems that the HTML output contains the login form !!
			Utils::Log(LOG_ERR, "Failed to login to iTop. Invalid (or insufficent) credentials.");
			$this->sErrorMessage .= "Failed to login to iTop. Invalid (or insufficent) credentials.\n";
			$iErrorsCount = 1;
		}
		else if (preg_match_all('/Objects (.*) errors: ([0-9]+)/', $sResult, $aMatches))
		{
			foreach($aMatches[2] as $idx => $sErrCount)
			{
				$iErrorsCount += (int)$sErrCount;
				if ((int)$sErrCount > 0)
				{
					Utils::Log(LOG_ERR, "Synchronization of data source '{$this->sSourceName}' answered: {$aMatches[0][$idx]}");
					$this->sErrorMessage .= $aMatches[0][$idx]."\n";
				}
			}
		}
		else
		{
			Utils::Log(LOG_ERR, "Synchronization of data source '{$this->sSourceName}' failed.");
			$this->sErrorMessage .= $sResult;
			$iErrorsCount = 1;
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
		unset($aSourceDefinition['notify_contact_id_obsolescence_flag']);
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
		unset($aSourceDefinition['notify_contact_id_obsolescence_flag']);
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
				if ($this->AttributeIsOptional($aAttr['attcode']))
				{
					Utils::Log(LOG_INFO, "Skipping optional attribute {$aAttr['attcode']}.");
					$this->aSkippedAttributes[] = $aAttr['attcode']; // record that this attribute was skipped
				}
				else
				{
					// Update only the SynchroAttributes which are really different			
					// Ignore read-only fields
					unset($aAttr['friendlyname']);
					$sTargetClass = $aAttr['finalclass'];
					unset($aAttr['finalclass']);
					// Fix booleans
					$aAttr['update'] = ($aAttr['update'] == 1) ? "1" : "0";
					$aAttr['reconcile'] = ($aAttr['reconcile'] == 1) ? "1" : "0";
					
					Utils::Log(LOG_DEBUG, "Updating attribute {$aAttr['attcode']}.");
					$aResult = $oClient->Update($sTargetClass, array( 'sync_source_id' => $this->iSourceId, 'attcode' => $aAttr['attcode']), $aAttr, $sComment);
					$bRet = ($aResult['code'] == 0);
					if (!$bRet)
					{
						if (preg_match('/Error: No item found with criteria: sync_source_id/', $aResult['message']))
						{
							Utils::Log(LOG_ERR, "Failed to update the Synchro Data Source. Inconsistent data model, the attribute '{$aAttr['attcode']}' does not exist in iTop.");
						}
						else
						{
							Utils::Log(LOG_ERR, "Failed to update the SynchroAttribute '{$aAttr['attcode']}'. Reason: {$aResult['message']} ({$aResult['code']})");
						}
						break;
					}
				}
			}
		}
		return $bRet;		
	}
	
	/**
	 * Find the definition of the specified attribute in 'attribute_list'
	 * @param string $sAttCode
	 * @param array $aExpectedAttrDef
	 * @return array|boolean
	 */
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
	
	/**
	 * Smart comparison of two data sources definitions, ignoring optional attributes
	 * @param array $aDS1
	 * @param array $aDS2
	 */
	protected function DataSourcesAreEquivalent($aDS1, $aDS2)
	{
		foreach($aDS1 as $sKey => $value)
		{
			switch($sKey)
			{
				case 'friendlyname':
				case 'user_id_friendlyname':
				case 'user_id_finalclass_recall':
				case 'notify_contact_id_friendlyname':
				case 'notify_contact_id_finalclass_recall':
				case 'notify_contact_id_obsolescence_flag':    
				// Ignore all read-only attributes
				break;
		
				case 'attribute_list':
				foreach($value as $sKey => $aDef)
				{
					$sAttCode = $aDef['attcode'];
					$aDef2 = $this->FindAttr($sAttCode, $aDS2['attribute_list']);
					if ($aDef2 === false)
					{
						if ($this->AttributeIsOptional($sAttCode))
						{
							// Ignore missing optional attributes
							Utils::Log(LOG_DEBUG, "Comparison: ignoring the missing, but optional, attribute: '$sAttCode'.");
							continue;
						}
						else
						{
							// Missing non-optional attribute
							Utils::Log(LOG_DEBUG, "Comparison: The definition of the non-optional attribute '$sAttCode' is missing. Data sources differ.");
							return false;
						}
						
					}
					else if (($aDef != $aDef2) && (!$this->AttributeIsOptional($sAttCode)))
					{
						// Definitions are different
						Utils::Log(LOG_DEBUG, "Comparison: The definitions of the attribute '$sAttCode' are different. Data sources differ:\nExpected values:".print_r($aDef, true)."------------\nCurrent values in iTop:".print_r($aDef2, true)."\n");
						return false;
					}
				}
				
				// Now check the other way around: are there too many attributes defined?
				foreach($aDS2['attribute_list'] as $sKey => $aDef)
				{
					$sAttCode = $aDef['attcode'];
					if(!$this->FindAttr($sAttCode, $aDS1['attribute_list']) && !$this->AttributeIsOptional($sAttCode))
					{
						Utils::Log(LOG_DEBUG, "Comparison: Found the extra definition of the non-optional attribute '$sAttCode' in iTop. Data sources differ.");
						return false;
					}
				}
				break;
				
				default:
				if (!array_key_exists($sKey, $aDS2) || $aDS2[$sKey] != $value)
				{
					if ($sKey != 'database_table_name')
					{
						// one meaningful difference is enough
						Utils::Log(LOG_DEBUG, "Comparison: The property '$sKey' is missing or has a different value. Data sources differ.");
						return false;
					}
				}
			}
		}
		//Check the other way around
		foreach($aDS2 as $sKey => $value)
		{
			switch($sKey)
			{
				case 'friendlyname':
				case 'user_id_friendlyname':
				case 'user_id_finalclass_recall':
				case 'notify_contact_id_friendlyname':
				case 'notify_contact_id_finalclass_recall':
				case 'notify_contact_id_obsolescence_flag':
				// Ignore all read-only attributes
				break;
				
				default:
				if (!array_key_exists($sKey, $aDS1))
				{
					Utils::Log(LOG_DEBUG, "Comparison: Found an extra property '$sKey' in iTop. Data sources differ.");
					return false;
				}
			}
		}
		return true;
	}
}
