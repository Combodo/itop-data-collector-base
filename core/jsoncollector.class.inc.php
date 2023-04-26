<?php

// Copyright (C) 2014-2020 Combodo SARL
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

Orchestrator::AddRequirement('5.6.0'); // Minimum PHP version

/**
 * Base class for creating collectors which retrieve their data via a JSON files
 *
 * The minimum implementation for such a collector consists in:
 * - creating a class derived from JSONCollector
 * - configuring parameters in tag <name_of_the_collector_class> with :
 * - <command> to configure a CLI command executed before reading JSON file
 * - <jsonfile> to configure a JSON file pat
 *      or <jsonurl> to give a JSON URL with post params in <jsonpost>
 * - <path> to configuring the path in the json file to take to find the data
 * by example aa/bb for {"aa":{"bb":{mydata},"cc":"xxx"}
 *      "*" will replace any tag aa/ * /bb  for {"aa":{cc":{"bb":{mydata1}},"dd":{"bb":{mydata2}}}
 *
 */
abstract class JsonCollector extends Collector
{
	protected $sFileJson;
	protected $aJson;
	protected $sURL;
	protected $sFilePath;
	protected $aJsonKey;
	protected $aFieldsKey;
	protected $sJsonCliCommand;
	protected $iIdx;
	protected $aSynchroFieldsToDefaultValues = array();

	/**
	 * Initalization
	 */
	public function __construct()
	{
		parent::__construct();
		$this->sFileJson = null;
		$this->sURL = null;
		$this->aJson = null;
		$this->aFieldsKey = null;
		$this->iIdx = 0;
	}

	/**
	 * Runs the configured query to start fetching the data from the database
	 *
	 * @see Collector::Prepare()
	 */
	public function Prepare()
	{
		$bRet = parent::Prepare();
		if (!$bRet) {
			return false;
		}

		//**** step 1 : get all parameters from config file
		$aParamsSourceJson = $this->aCollectorConfig;
		if (isset($aParamsSourceJson["command"])) {
			$this->sJsonCliCommand = $aParamsSourceJson["command"];
		}
		if (isset($aParamsSourceJson["COMMAND"])) {
			$this->sJsonCliCommand = $aParamsSourceJson["COMMAND"];
			Utils::Log(LOG_INFO, "[".get_class($this)."] CLI command used is [".$this->sJsonCliCommand."]");
		}


		// Read the URL or Path from the configuration
		if (isset($aParamsSourceJson["jsonurl"])) {
			$this->sURL = $aParamsSourceJson["jsonurl"];
		}
		if (isset($aParamsSourceJson["JSONURL"])) {
			$this->sURL = $aParamsSourceJson["JSONURL"];
		}

		if ($this->sURL == '') {
			if (isset($aParamsSourceJson["jsonfile"])) {
				$this->sFilePath = $aParamsSourceJson["jsonfile"];
			}
			if (isset($aParamsSourceJson["JSONFILE"])) {     // Try all lowercase
				$this->sFilePath = $aParamsSourceJson["JSONFILE"];
			}
			Utils::Log(LOG_INFO, "Source file path: ".$this->sFilePath);
		} else {
			Utils::Log(LOG_INFO, "Source URL: ".$this->sURL);
		}

		if ($this->sURL == '' && $this->sFilePath == '') {
			// No query at all !!
			Utils::Log(LOG_ERR, "[".get_class($this)."] no json URL or path configured! Cannot collect data. Please configure it as '<jsonurl>' or '<jsonfile>' in the configuration file.");

			return false;
		}
		if (array_key_exists('defaults', $aParamsSourceJson)) {
			if ($aParamsSourceJson['defaults'] !== '') {
				$this->aSynchroFieldsToDefaultValues = $aParamsSourceJson['defaults'];
				if (!is_array($this->aSynchroFieldsToDefaultValues)) {
					Utils::Log(LOG_ERR, "[".get_class($this)."] defaults section configuration is not correct. please see documentation.");

					return false;
				}
			}
		}

		if (isset($aParamsSourceJson["path"])) {
			$aPath = explode('/', $aParamsSourceJson["path"]);
		}
		if (isset($aParamsSourceJson["PATH"])) {
			$aPath = explode('/', $aParamsSourceJson["PATH"]);
		}
		if ($aPath == '') {
			Utils::Log(LOG_ERR, "[".get_class($this)."] no path to find data in JSON file");
		}

		//**** step 2 : get json file
		//execute cmd before get the json
		if (!empty($this->sJsonCliCommand)) {
			utils::Exec($this->sJsonCliCommand);
		}

		//get Json file
		if ($this->sURL != '') {
			Utils::Log(LOG_DEBUG, 'Get params for uploading data file ');
            $aDataGet = [];
			if (isset($aParamsSourceJson["jsonpost"])) {
				$aDataGet = $aParamsSourceJson['jsonpost'];
			} else {
				$aDataGet = [];
			}
			$iSynchroTimeout = (int)Utils::GetConfigurationValue('itop_synchro_timeout', 600); // timeout in seconds, for a synchro to run
			$aCurlOptions = Utils::GetCurlOptions($iSynchroTimeout);

			//logs
			Utils::Log(LOG_DEBUG, 'Source aDataGet: '.json_encode($aDataGet));
			$this->sFileJson = Utils::DoPostRequest($this->sURL, $aDataGet, '', $aResponseHeaders, $aCurlOptions);
			Utils::Log(LOG_DEBUG, 'Source sFileJson: '.$this->sFileJson);
			Utils::Log(LOG_INFO, 'Synchro URL (target): '.Utils::GetConfigurationValue('itop_url', array()));
		} else {
			$this->sFileJson = file_get_contents($this->sFilePath);
			Utils::Log(LOG_DEBUG, 'Source sFileJson: '.$this->sFileJson);
			Utils::Log(LOG_INFO, 'Synchro  URL (target): '.Utils::GetConfigurationValue('itop_url', array()));
		}

		//verify the file
		if ($this->sFileJson === false) {
			Utils::Log(LOG_ERR, '['.get_class($this).'] Failed to get JSON file: '.$this->sURL);

			return false;
		}


		//**** step 3 : read json file
		$this->aJson = json_decode($this->sFileJson, true);
		if ($this->aJson == null) {
			Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to translate data from JSON file: '".$this->sURL.$this->sFilePath."'. Reason: ".json_last_error_msg());

			return false;
		}

		//Get table of Element in JSON file with a specific path
		foreach ($aPath as $sTag) {
			Utils::Log(LOG_DEBUG, "tag: ".$sTag);
			//!array_key_exists(0, $this->aJson) => element $this->aJson is not a classic array It's an array with defined keys
			if (!array_key_exists(0, $this->aJson) && $sTag != '*') {
				$this->aJson = $this->aJson[$sTag];
			} else {
				$aJsonNew = array();
				foreach ($this->aJson as $aElement) {
					if ($sTag == '*') //Any tag
					{
						array_push($aJsonNew, $aElement);
					} else {
						if (isset($aElement[$sTag])) {
							array_push($aJsonNew, $aElement[$sTag]);
						}
					}
				}
				$this->aJson = $aJsonNew;
			}
			if (count($this->aJson) == 0) {
				Utils::Log(LOG_ERR, "[".get_class($this)."] Failed to find path ".implode("/", $aPath)." until data in json file: $this->sURL $this->sFilePath.");

				return false;
			}
		}
		$this->aJsonKey = array_keys($this->aJson);
		if (isset($aParamsSourceJson["fields"])) {
			$this->aFieldsKey = $aParamsSourceJson["fields"];
		}
		if (isset($aParamsSourceJson["FIELDS"])) {
			$this->aFieldsKey = $aParamsSourceJson["FIELDS"];
		}
		Utils::Log(LOG_DEBUG, "aFieldsKey: ".json_encode($this->aFieldsKey));
		Utils::Log(LOG_DEBUG, "aJson: ".json_encode($this->aJson));
		Utils::Log(LOG_DEBUG, "aJsonKey: ".json_encode($this->aJsonKey));
		Utils::Log(LOG_DEBUG, "nb of elements:".count($this->aJson));

		$this->iIdx = 0;

		return true;
	}

	/**
	 * Fetch one element from the JSON file
	 * The first element is used to check if the columns of the result match the expected "fields"
	 *
	 * @see Collector::Fetch()
	 */
	public function Fetch()
	{
		if (empty($this->aJson)) {
			return false;
		}
		if ($this->iIdx < count($this->aJson)) {
			$aData = $this->aJson[$this->aJsonKey[$this->iIdx]];
			Utils::Log(LOG_DEBUG, '$aData: '.json_encode($aData));

			$aDataToSynchronize = $this->SearchFieldValues($aData);

			foreach ($this->aSkippedAttributes as $sCode) {
				unset($aDataToSynchronize[$sCode]);
			}

			if ($this->iIdx == 0) {
				$this->CheckColumns($aDataToSynchronize, [], 'json file');
			}
			//check if all expected fields are in array. If not add it with null value
			foreach ($this->aCSVHeaders as $sHeader) {
				if (!isset($aDataToSynchronize[$sHeader])) {
					$aDataToSynchronize[$sHeader] = null;
				}
			}

			foreach ($this->aNullifiedAttributes as $sHeader) {
				if (!isset($aDataToSynchronize[$sHeader])) {
					$aDataToSynchronize[$sHeader] = null;
				}
			}

			$this->iIdx++;

			return $aDataToSynchronize;
		}

		return false;
	}

	/**
	 * @param array $aData
	 *
	 * @return array
	 * @throws \Exception
	 */
	private function SearchFieldValues($aData, $aTestOnlyFieldsKey=null) {
		$aDataToSynchronize = [];

		$aCurrentFieldKeys = (is_null($aTestOnlyFieldsKey)) ? $this->aFieldsKey : $aTestOnlyFieldsKey;
		foreach ($aCurrentFieldKeys as $key => $sPath) {
			if ($this->iIdx == 0) {
				Utils::Log(LOG_DEBUG, $key.":".array_search($key, $aCurrentFieldKeys));
			}
			//
			$aJsonKeyPath = explode('/', $sPath);
			$aValue = $this->SearchValue($aJsonKeyPath, $aData);

			if (empty($aValue) && array_key_exists($key, $this->aSynchroFieldsToDefaultValues)){
				$sDefaultValue = $this->aSynchroFieldsToDefaultValues[$key];
				Utils::Log(LOG_DEBUG, "aDataToSynchronize[$key]: $sDefaultValue");
				$aDataToSynchronize[$key] = $sDefaultValue;
			} else if (! is_null($aValue)){
				Utils::Log(LOG_DEBUG, "aDataToSynchronize[$key]: $aValue");
				$aDataToSynchronize[$key] = $aValue;
			}
		}

		Utils::Log(LOG_DEBUG, '$aDataToSynchronize: '.json_encode($aDataToSynchronize));
		return $aDataToSynchronize;
	}

	private function SearchValue($aJsonKeyPath, $aData){
		$sTag = array_shift($aJsonKeyPath);

		if($sTag === '*'){
			foreach ($aData as $sKey => $aDataValue){
				$aCurrentValue = $this->SearchValue($aJsonKeyPath, $aDataValue);
				if (null !== $aCurrentValue){
					return $aCurrentValue;
				}
			}
			return null;
		}

		if (is_int($sTag)
			&& array_is_list($aData)
			&&  array_key_exists((int) $sTag, $aData)
		) {
			$aValue = $aData[(int) $sTag];
		} else if(($sTag != '*')
			&& is_array($aData)
			&& isset($aData[$sTag])
		){
			$aValue = $aData[$sTag];
		} else {
			return null;
		}

		if (empty($aJsonKeyPath)){
			return (is_array($aValue)) ? null : $aValue;
		}

		return $this->SearchValue($aJsonKeyPath, $aValue);
	}

	/**
	 * Determine if a given attribute is allowed to be missing in the data datamodel.
	 *
	 * The implementation is based on a predefined configuration parameter named from the
	 * class of the collector (all lowercase) with _ignored_attributes appended.
	 *
	 * Example: here is the configuration to "ignore" the attribute 'location_id' for the class MyJSONCollector:
	 * <myjsoncollector_ignored_attributes type="array">
	 *    <attribute>location_id</attribute>
	 * </myjsoncollector_ignored_attributes>
	 *
	 * @param string $sAttCode
	 *
	 * @return boolean True if the attribute can be skipped, false otherwise
	 */
	public function AttributeIsOptional($sAttCode)
	{
		$aIgnoredAttributes = Utils::GetConfigurationValue(get_class($this)."_ignored_attributes", null);
		if ($aIgnoredAttributes === null) {
			// Try all lowercase
			$aIgnoredAttributes = Utils::GetConfigurationValue(strtolower(get_class($this))."_ignored_attributes", null);
		}
		if (is_array($aIgnoredAttributes)) {
			if (in_array($sAttCode, $aIgnoredAttributes)) {
				return true;
			}
		}

		return parent::AttributeIsOptional($sAttCode);
	}

}
