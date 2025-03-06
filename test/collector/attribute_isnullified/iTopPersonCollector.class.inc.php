<?php

class iTopPersonCollector extends Collector
{
	private $bFetched;
	private $aOptionalAttributes;

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

	/**
	 * @return array
	 */
	public function GetOptionalAttributes() {
		if (! isset($this->aOptionalAttributes)){
			return ['optional'];
		}
		return $this->aOptionalAttributes;
	}

	/**
	 * @param array $aOptionalAttributes
	 */
	public function SetOptionalAttributes($aOptionalAttributes) {
		$this->aOptionalAttributes = $aOptionalAttributes;
	}


	/**
	 * {@inheritDoc}
	 * @see Collector::AttributeIsOptional()
	 */
	public function AttributeIsOptional($sAttCode)
	{
		foreach ($this->GetOptionalAttributes() as $sAttOptionalCode){
			if ($sAttCode === $sAttOptionalCode){
				return true;
			}
		}

		return false;
	}
}
