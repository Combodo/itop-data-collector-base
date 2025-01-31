<?php

class iTopPersonCollector extends Collector
{
	private $i;
	private $aCurrentData;

	protected function Fetch()
	{
		if (is_null($this->aCurrentData)){
			$this->i = 0;
			$this->aCurrentData = [
				[
					'primary_key' => 1,
					'first_name' => "isaac",
					'name' => "asimov_null",
					'org_id' => "Demo",
					'phone' => null,
					'mobile_phone' => "123456",
					'employee_number' => "9998877665544",
					'email' => "issac.asimov@function.io",
					'function' => "writer",
					'Status' => "Active",
				],
				[
					'primary_key' => 2,
					'first_name' => "isaac",
					'name' => "asimov_empty",
					'org_id' => "Demo",
					'phone' => "",
					'mobile_phone' => "123456",
					'employee_number' => "9998877665544",
					'email' => "issac.asimov@function.io",
					'function' => "writer",
					'Status' => "Active",
				],
				[
					'primary_key' => 3,
					'first_name' => "isaac",
					'name' => "asimov_notempty",
					'org_id' => "Demo",
					'phone' => "not empty",
					'mobile_phone' => "123456",
					'employee_number' => "9998877665544",
					'email' => "issac.asimov@function.io",
					'function' => "writer",
					'Status' => "Active",
				],
			];
		}

		$res = null;
		if ($this->i < count($this->aCurrentData)){
			$res=$this->aCurrentData[$this->i];
			$this->i++;
		}

		return $res;
	}

	/**
	 * {@inheritDoc}
	 * @see Collector::AttributeIsOptional()
	 */
	public function AttributeIsOptional($sAttCode)
	{
	    return ($sAttCode === 'optional');
	}
}
