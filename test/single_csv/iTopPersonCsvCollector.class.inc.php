<?php

class iTopPersonLDAPCollector extends LDAPCollector
{

    protected $idx;

    protected $sLDAPDN;
    protected $sLDAPFilter;
    protected $sSynchronizeOrganizations;
    protected $aPersonFields;
    protected $aPersonDefaults;

    protected $aPersons;

    public function __construct()
    {
        parent::__construct();
        // let's read the configuration parameters
        $this->sLDAPDN = Utils::GetConfigurationValue('ldapdn', 'DC=company,DC=com');
        $this->sLDAPFilter = Utils::GetConfigurationValue('ldappersonfilter', '(&(objectClass=user)(objectCategory=person))');
        $this->sSynchronizeOrganizations = Utils::GetConfigurationValue('synchronize_organization', 'no');
        $this->aPersonDefaults = Utils::GetConfigurationValue('person_defaults', array());
        $this->aPersonFields = Utils::GetConfigurationValue('person_fields', array('primary_key' => 'samaccountname'));
        $this->aPersons = array();
        $this->idx = 0;
        
        // Safety check
        if (!array_key_exists('primary_key', $this->aPersonFields))
        {
            Utils::Log(LOG_ERR, "Persons: You MUST specify a mapping for the field:'primary_key'");
        }
        if (!array_key_exists('name', $this->aPersonFields))
        {
            Utils::Log(LOG_ERR, "Persons: You MUST specify a mapping for the field:'name'");
        }
        
        // For debugging dump the mapping and default values
        $sMapping = '';
        foreach($this->aPersonFields as $sAttCode => $sField)
        {
            if (array_key_exists($sAttCode, $this->aPersonDefaults))
            {
                $sDefaultValue = ", default value: '{$this->aPersonDefaults[$sAttCode]}'";
            }
            else
            {
                $sDefaultValue = '';
            }
            $sMapping .= "   iTop '$sAttCode' is filled from LDAP '$sField' $sDefaultValue\n";
        }
        foreach($this->aPersonDefaults as $sAttCode => $sDefaultValue)
        {
            if (!array_key_exists($sAttCode, $this->aPersonFields))
            {
                $sMapping .= "   iTop '$sAttCode' is filled with the constant value '$sDefaultValue'\n";
            }
        }
        Utils::Log(LOG_DEBUG, "Persons: Mapping of the fields:\n$sMapping");
    }
    
    public function AttributeIsOptional($sAttCode)
    {
        if (in_array($sAttCode, array('anonymized', 'picture', 'status'))) return true;
        
        return parent::AttributeIsOptional($sAttCode);
    }
    
    protected function GetData()
    {
        $aList = $this->Search($this->sLDAPDN, $this->sLDAPFilter);
        
        if ($aList !== false)
        {
            $iNumberOfPersons = count($aList) - 1;
            Utils::Log(LOG_INFO,"(Persons) Number of entries found on LDAP: ".$iNumberOfPersons);
        }
        return $aList;
    }

    public function Prepare()
    {
        if (! $aData = $this->GetData()) return false;
        
        foreach ($aData as $aPerson)
        {
            if (isset($aPerson[$this->aPersonFields['primary_key']][0]) && $aPerson[$this->aPersonFields['primary_key']][0] != "")
            {
                $aValues = array();
                
                // Primary key must be the first column
                $aValues['primary_key'] = $aPerson[$this->aPersonFields['primary_key']][0];
                
                // First set the default values (as well as the constant values for fields which are not collected)
                foreach($this->aPersonDefaults as $sFieldCode => $sDefaultValue)
                {
                    $aValues[$sFieldCode] = $sDefaultValue;
                }
                
                // Then read the actual values (if any) 
                foreach($this->aPersonFields as $sFieldCode => $sLDAPAttribute)
                {
                    if ($sFieldCode == 'primary_key') continue; // Already processed, must be the first column
                    
                    $sDefaultValue = isset($this->aPersonDefaults[$sFieldCode]) ? $this->aPersonDefaults[$sFieldCode] : '';
                    $sFieldValue = isset($aPerson[$sLDAPAttribute][0]) ? $aPerson[$sLDAPAttribute][0] : $sDefaultValue;

                    $aValues[$sFieldCode] = $sFieldValue;
                }
                $this->aPersons[] = $aValues;
            }
        }
        return true;
    }

    public function Fetch()
    {
        if ($this->idx < count($this->aPersons))
        {
            $aData = $this->aPersons[$this->idx];
            $this->idx++;
            
            return $aData;
        }
        return false;
    }
}
