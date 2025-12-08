<?php

// Copyright (C) 2018 Combodo SARL
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

class ITopPersonJsonCollector extends JsonCollector
{
	public function AttributeIsOptional($sAttCode)
	{
		if ($sAttCode == 'function') {
			return true;
		}
		return parent::AttributeIsOptional($sAttCode);
	}

	public function testDataSourcesAreEquivalent($aPlaceholders)
	{
		$bResult = true;
		$sJSONSourceDefinition = file_get_contents(APPROOT."/collectors/ITopPersonJsonCollector.json");
		$aExpectedSourceDefinition = json_decode($sJSONSourceDefinition, true);
		$this->CheckDataSourceDefinition($aExpectedSourceDefinition);

		$sJSONExpectedDefinition = file_get_contents(APPROOT."/collectors/ITopPersonJsonSourceCollector.json");
		$aCurrentSourceDefinition = json_decode($sJSONExpectedDefinition, true);

		if ($this->DataSourcesAreEquivalent($aExpectedSourceDefinition, $aCurrentSourceDefinition)) {
			Utils::Log(LOG_INFO, "Ok, the Synchro Data Source exists in iTop and is up to date");
		} else {
			Utils::Log(LOG_INFO, "The Synchro Data Source definition for must be updated in iTop.");
		}

		return $bResult;
	}
}
