<?php

class iTopPersonCsvCollector extends CSVCollector
{
	protected function MustProcessBeforeSynchro()
	{
		return true;
	}
}
