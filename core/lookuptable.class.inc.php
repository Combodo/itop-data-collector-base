<?php
// Copyright (C) 2014-2018 Combodo SARL
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
 * Helper class to perform advanced data lookups in iTop by retrieving objects via the REST API
 */
class LookupTable
{
	protected $aData;
	protected $aFieldsPos;
	protected $bCaseSensitive;
	protected $bIgnoreMappingErrors;
	protected $sReturnAttCode;

	/**
	 * Initialization of a LookupTable, based on an OQL query in iTop
	 * @param string $sOQL The OQL query for the objects to integrate in the LookupTable. Format: SELECT <class>[ WHERE ...]
	 * @param array $aKeyFields The fields of the object to use in the lookup key
	 * @param bool $bCaseSensitive Is the mapping case sensitive ?
	 * @param bool $bIgnoreMappingErrors Are mapping errors considered as "normal"? (e.g. when using the lookup table for filtering the data)
	 * @param string $sReturnAttCode The attribute code whose value to return as the result of the mapping (by default 'id' meaning the ID of the matching iTop object)
	 * @throws Exception
	 */
	public function __construct($sOQL, $aKeyFields, $bCaseSensitive = true, $bIgnoreMappingErrors = false, $sReturnAttCode = 'id')
	{
		$this->aData =array();
		$this->aFieldsPos =array();
		$this->bCaseSensitive = $bCaseSensitive;
		$this->bIgnoreMappingErrors = $bIgnoreMappingErrors;
		$this->sReturnAttCode = $sReturnAttCode;
		
		if(!preg_match('/^SELECT ([^ ]+)/', $sOQL, $aMatches))
		{
			throw new Exception("Invalid OQL query: '$sOQL'. Expecting a query starting with 'SELECT xxx'");
		}
		$sClass = $aMatches[1];
		$oRestClient = new RestClient();
		$aRestFields = $aKeyFields;
		if ($this->sReturnAttCode !== 'id')
		{
		    // If the return attcode is not the ID of the object, add it to the list of the required fields
		    $aRestFields[] = $this->sReturnAttCode;
		}
		$aRes = $oRestClient->Get($sClass, $sOQL, implode(',', $aRestFields));
		if ($aRes['code'] == 0)
		{
			foreach($aRes['objects'] as $sObjKey => $aObj)
			{
				$iObjKey = 0;
				$aMappingKeys = array();
				foreach($aKeyFields as $sField)
				{
					if (!array_key_exists($sField, $aObj['fields']))
					{
						Utils::Log(LOG_ERR, "field '$sField' does not exist in '".json_encode($aObj['fields'])."'");
						$aMappingKeys[] = '';
					}
					else
					{
						$aMappingKeys[] = $aObj['fields'][$sField];
					}
				}
				$sMappingKey = implode($aMappingKeys, '_');
				if (!$this->bCaseSensitive)
				{
					if (function_exists('mb_strtolower'))
					{
						$sMappingKey = mb_strtolower($sMappingKey);
					}
					else
					{
						$sMappingKey = strtolower($sMappingKey);
					}
				}
				if ($this->sReturnAttCode !== 'id')
				{
					// If the return attcode is not the ID of the object, check that it exists
					if (!array_key_exists($this->sReturnAttCode, $aObj['fields']))
					{
						Utils::Log(LOG_ERR, "field '{$this->sReturnAttCode}' does not exist in '".json_encode($aObj['fields'])."'");
						$iObjKey = 0;
					}
					else
					{
						$iObjKey = $aObj['fields'][$this->sReturnAttCode];
					}
				}
				else
				{
					// The return value is the ID of the object
					if(!array_key_exists('key', $aObj))
					{
						// Emulate the behavior for older versions of the REST API
						if(preg_match('/::([0-9]+)$/', $sObjKey, $aMatches))
						{
							$iObjKey = (int)$aMatches[1];
						}
					}
					else
					{
						$iObjKey = (int)$aObj['key'];
					}
				}
				$this->aData[$sMappingKey] = $iObjKey; // Store the mapping
			}
		}
		else
		{
			Utils::Log(LOG_ERR, "Unable to retrieve the $sClass objects (query = $sOQL). Message: ".$aRes['message']);
		}
	}
	
	/**
	 * Replaces the given field in the CSV data by the identifier of the object in iTop, based on a list of lookup fields
	 * @param hash $aLineData The data corresponding to the line of the CSV file being processed
	 * @param array $aLookupFields The list of fields used for the mapping key
	 * @param string $sDestField The name of field (i.e. column) to populate with the id of the iTop object
	 * @param int $iLineIndex The index of the line (0 = first line of the CSV file)
	 * @return bool true if the mapping succeeded, false otherwise
	 */
	public function Lookup(&$aLineData, $aLookupFields, $sDestField, $iLineIndex)
	{
		$bRet = true;
		if ($iLineIndex == 0)
		{
			$this->InitLineMappings($aLineData, array_merge($aLookupFields, array($sDestField)));
		}
		else
		{
			$aLookupKey = array();
			foreach($aLookupFields as $sField)
			{
				$iPos = $this->aFieldsPos[$sField];
				if ($iPos !== null)
				{
					$aLookupKey[] = $aLineData[$iPos];
				}
				else
				{
					$aLookupKey[] = ''; // missing column ??
				}
			}
			$sLookupKey = implode('_', $aLookupKey);
			if (!$this->bCaseSensitive)
			{
			    if (function_exists('mb_strtolower'))
			    {
			        $sLookupKey = mb_strtolower($sLookupKey);
			    }
			    else
			    {
			        $sLookupKey = strtolower($sLookupKey);
			    }
			}
			if (!array_key_exists($sLookupKey, $this->aData))
			{
			    if ($this->bIgnoreMappingErrors)
			    {
			        // Mapping *errors* are expected, just report them in debug mode 
			        Utils::Log(LOG_DEBUG, "No mapping found with key: '$sLookupKey', '$sDestField' will be set to zero.");
			    }
			    else
			    {
				    Utils::Log(LOG_WARNING, "No mapping found with key: '$sLookupKey', '$sDestField' will be set to zero.");
			    }
				$bRet = false;
			}
			else
			{
				$iPos = $this->aFieldsPos[$sDestField];
				if ($iPos !== null)
				{
					$aLineData[$iPos] = $this->aData[$sLookupKey];
				}
				else
				{
					Utils::Log(LOG_WARNING, "'$sDestField' is not a valid column name in the CSV file. Mapping will be ignored.");
				}
			}
		}
		return $bRet;
	}
	
	/**
	 * Initializes the mapping between the column names (given by the first line of the CSV) and their index, for the given columns 
	 * @param hash $aLineHeaders An array of strings (the "headers" i.e. first line of the CSV file)
	 * @param array $aFields The fields for which a mapping is requested, as an array of strings
	 */
	protected function InitLineMappings($aLineHeaders, $aFields)
	{
		foreach($aLineHeaders as $idx => $sHeader)
		{
			if (in_array($sHeader, $aFields))
			{
				$this->aFieldsPos[$sHeader] = $idx;
			}
		}
		
		// Check that all requested fields were found in the headers
		foreach($aFields as $sField)
		{
			if(!array_key_exists($sField, $this->aFieldsPos))
			{
				Utils::Log(LOG_ERR, "'$sField' is not a valid column name in the CSV file. Mapping will fail.");
			}
		}
	}
}
