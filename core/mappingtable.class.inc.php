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
 * Helper class to perform a simple data transformation (cleaning) based on a set of patterns
 */
class MappingTable
{
	/**
	 * @var string The name of the configuration entry from which the configuratin was loaded
	 */
	protected $sConfigEntryName;
	
	/**
	 * @var string[][]
	 */
	protected $aMappingTable;

	/**
	 * Creates a new MappingTable
	 * @param string $sConfigEntryName Name of the XML tag (in the params file) under which the configuration of the mapping table is stored
	 */
	public function __construct($sConfigEntryName)
	{
		// Read the "extended mapping" from the configuration
		// The mapping is expressed as an array of strings in the following format: <delimiter><regexpr_body><delimiter><replacement>
		$this->sConfigEntryName = $sConfigEntryName;
		$aRawMapping = Utils::GetConfigurationValue($sConfigEntryName, array());
		foreach($aRawMapping as $sExtendedPattern)
		{
			$sDelimiter = $sExtendedPattern[0];
			$iEndingDelimiterPos = strrpos($sExtendedPattern, $sDelimiter);
			$sPattern = substr($sExtendedPattern, 0, $iEndingDelimiterPos + 1);
			$sReplacement = substr($sExtendedPattern, $iEndingDelimiterPos + 1);
			$this->aMappingTable[] = array(
				'pattern' => $sPattern,
				'replacement' => $sReplacement,
			);
		}
	}
	/**
	 * Normalizes a value through the mapping table
	 * @param string $sRawValue The value to normalize
	 * @param string $defaultValue Default value if no match is found in the mapping table
	 * @return string The normalized value. Can be null if no match is found and no default value was supplied.
	 */
	public function MapValue($sRawValue, $defaultValue = null)
	{
		$value = null;
		foreach($this->aMappingTable as $aMapping)
		{
			if (preg_match($aMapping['pattern'].'iu', $sRawValue, $aMatches)) // 'i' for case insensitive matching, 'u' for utf-8 characters
			{
				$value = vsprintf($aMapping['replacement'], $aMatches); // found a suitable match
				Utils::Log(LOG_DEBUG, "MappingTable[{$this->sConfigEntryName}]: input value '$sRawValue' matches '{$aMapping['pattern']}'. Output value is '$value'");
				break;
			}
		}
		if ($value === null)
		{
			$value = $defaultValue;
		}
		return $value;
	}
}