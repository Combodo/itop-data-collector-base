<?php
// Copyright (C) 2014 Combodo SARL
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

class InvalidParameterException extends Exception
{
}

/**
 * Helper class to store the parameters
 * into an XML file and manipulate them as a hash array
 */
class Parameters
{
	protected $aData = null;
	
	public function __construct($sInputFile = null)
	{
		$this->aData = array();
		if ($sInputFile != null)
		{
			$this->LoadFromFile($sInputFile);
		}
	}

	public function Get($sCode, $default = '')
	{
		if (array_key_exists($sCode, $this->aData))
		{
			return $this->aData[$sCode];
		}
		return $default;
	}

	public function Set($sCode, $value)
	{
		$this->aData[$sCode] = $value;
	}

	protected function ToXML(DOMNode $oRoot, $data = null, $sNodeName = null)
	{
		if ($data === null)
		{
			$data = $this->aData;
		}
				
		if (is_array($data))
		{
			if ($oRoot instanceof DOMDocument)
			{
				$oNode = $oRoot->createElement($sNodeName);
			}
			else
			{
				$oNode = $oRoot->ownerDocument->createElement($sNodeName);
			}
			$oRoot->appendChild($oNode);

			$aKeys = array_keys($data);
			$bNumericKeys = true;
			foreach($aKeys as $idx => $subkey)
			{
				if(((int)$subkey) !== $subkey)
				{
					$bNumericKeys = false;
					break;
				}
			}
			if ($bNumericKeys)
			{
				$oNode->setAttribute("type", "array");
				foreach($data as $key => $value)
				{
					$this->ToXML($oNode, $value , 'item');
				}
			}
			else
			{
				foreach($data as $key => $value)
				{
					$this->ToXML($oNode, $value , $key);
				}
			}
		}
		else
		{
			$oNode = $oRoot->ownerDocument->createElement($sNodeName, $data);
			$oRoot->appendChild($oNode);
		}
		return $oNode;
	}
	
	public function SaveToFile($sFileName)
	{
		$oDoc = new DOMDocument('1.0', 'UTF-8');
		$oDoc->preserveWhiteSpace = false;
		$oDoc->formatOutput = true;
		$this->ToXML($oDoc, null, 'parameters');
		$oDoc->save($sFileName);
	}

	public function Dump()
	{
		$oDoc = new DOMDocument('1.0', 'UTF-8');
		$oDoc->preserveWhiteSpace = false;
		$oDoc->formatOutput = true;
		$this->ToXML($oDoc, null, 'parameters');
		return $oDoc->saveXML();
	}

	public function LoadFromFile($sParametersFile)
	{
		$this->sParametersFile = $sParametersFile;
		if ($this->aData == null)
		{
			libxml_use_internal_errors(true);
			$oXML = @simplexml_load_file($this->sParametersFile);
			if (!$oXML)
			{
				$aMessage = array();
				foreach(libxml_get_errors() as $oError)
				{
					$aMessage[] = "(line: {$oError->line}) ".$oError->message; // Beware: $oError->columns sometimes returns wrong (misleading) value
				}
				libxml_clear_errors();
				throw new InvalidParameterException("Invalid Parameters file '{$this->sParametersFile}': ".implode(' ', $aMessage));
			}
			
			$this->aData = array();
			foreach($oXML as $key => $oElement)
			{
				$this->aData[(string)$key] = $this->ReadElement($oElement);
			}
		}
	}
	
	protected function ReadElement(SimpleXMLElement $oElement)
	{
		$sDefaultNodeType = (count($oElement->children()) > 0) ? 'hash' : 'string';
		$sNodeType = $this->GetAttribute('type', $oElement, $sDefaultNodeType);
		switch($sNodeType)
		{
			case 'array':
			$value = array();
			// Treat the current element as zero based array, child tag names are NOT meaningful
			$sFirstTagName = null;
			foreach($oElement->children() as $oChildElement)
			{
				if ($sFirstTagName == null)
				{
					$sFirstTagName = $oChildElement->getName();
				}
				else if ($sFirstTagName != $oChildElement->getName())
				{
					throw new InvalidParameterException("Invalid Parameters file '{$this->sParametersFile}': mixed tags ('$sFirstTagName' and '".$oChildElement->getName()."') inside array '".$oElement->getName()."'");
				}
				$val = $this->ReadElement($oChildElement);
				$value[] = $val;
			}
			break;
			
			case 'hash':
			$value = array();
			// Treat the current element as a hash, child tag names are keys
			foreach($oElement->children() as $oChildElement)
			{
				if (array_key_exists($oChildElement->getName(), $value))
				{
					throw new InvalidParameterException("Invalid Parameters file '{$this->sParametersFile}': duplicate tags '".$oChildElement->getName()."' inside hash '".$oElement->getName()."'");
				}
				$val = $this->ReadElement($oChildElement);
				$value[$oChildElement->getName()] = $val;
			}
			break;
			
			case 'int':
			case 'integer':
			$value = (int)$oElement;
			break;
			
			case 'string':
			default:
			$value = (string)$oElement;
		}
		return $value;
	}
	
	protected function GetAttribute($sAttName, $oElement, $sDefaultValue)
	{
		$sRet = $sDefaultValue;

		foreach($oElement->attributes() as $sKey => $oChildElement)
		{
			if ((string)$sKey == $sAttName)
			{
				$sRet = (string)$oChildElement;
				break;
			}
		}
		return $sRet;
	}
	
	function Merge(Parameters $oTask)
	{
		$this->aData = $this->array_merge_recursive_distinct($this->aData, $oTask->aData);
	}
	
/**
 * array_merge_recursive does indeed merge arrays, but it converts values with duplicate
 * keys to arrays rather than overwriting the value in the first array with the duplicate
 * value in the second array, as array_merge does. I.e., with array_merge_recursive,
 * this happens (documented behavior):
 *
 * array_merge_recursive(array('key' => 'org value'), array('key' => 'new value'));
 *     => array('key' => array('org value', 'new value'));
 *
 * array_merge_recursive_distinct does not change the datatypes of the values in the arrays.
 * Matching keys' values in the second array overwrite those in the first array, as is the
 * case with array_merge, i.e.:
 *
 * array_merge_recursive_distinct(array('key' => 'org value'), array('key' => 'new value'));
 *     => array('key' => array('new value'));
 *
 * Parameters are passed by reference, though only for performance reasons. They're not
 * altered by this function.
 *
 * @param array $array1
 * @param array $array2
 * @return array
 * @author Daniel <daniel (at) danielsmedegaardbuus (dot) dk>
 * @author Gabriel Sobrinho <gabriel (dot) sobrinho (at) gmail (dot) com>
 */
protected function array_merge_recursive_distinct ( array &$array1, array &$array2 )
{
  $merged = $array1;

  foreach ( $array2 as $key => &$value )
  {
    if ( is_array ( $value ) && isset ( $merged [$key] ) && is_array ( $merged [$key] ) )
    {
      $merged [$key] = $this->array_merge_recursive_distinct ( $merged [$key], $value );
    }
    else
    {
      $merged [$key] = $value;
    }
  }

  return $merged;
}	
}