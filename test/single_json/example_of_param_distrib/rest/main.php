<?php
// Copyright (C) 2020 Combodo SARL
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

require_once(APPROOT.'collectors\ITopPersonJsonCollector.class.inc.php');


// Register the collectors (one collector class per data synchro task to run)
// and tell the orchestrator in which order to run them
$iRank = 1;
Orchestrator::AddCollector($iRank++, 'ITopPersonJsonCollector');

