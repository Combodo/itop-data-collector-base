<?php

class iTopPersonCollector extends Collector
{
	private $bFetched;

	protected function Fetch()
	{
		if (! $this->bFetched) {
			$this->bFetched = true;
			return [
				'primary_key' => 1,
				'first_name' => "isaac",
				'name' => "asimov",
				'org_id' => "Demo",
				'phone' => null,
				'mobile_phone' => "123456",
				'employee_number' => "9998877665544",
				'email' => "issac.asimov@function.io",
				'function' => "writer",
				'Status' => "Active",
			];
		}

		return null;
	}
}
