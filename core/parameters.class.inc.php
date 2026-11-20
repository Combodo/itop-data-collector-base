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
	protected $sParametersFile;

	public function __construct($sInputFile = null)
	{
		$this->aData = [];
		if ($sInputFile != null) {
			$this->LoadFromFile($sInputFile);
		}
	}

	public function Get($sCode, $default = '')
	{
		if (array_key_exists($sCode, $this->aData)) {
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
		if ($data === null) {
			$data = $this->aData;
		}

		if ($oRoot instanceof DOMDocument) {
			$oNode = $oRoot->createElement($sNodeName);
		} else {
			$oNode = $oRoot->ownerDocument->createElement($sNodeName);
		}
		$oRoot->appendChild($oNode);

		if (is_array($data)) {

			$aKeys = array_keys($data);
			$bNumericKeys = true;
			foreach ($aKeys as $idx => $subkey) {
				if (((int)$subkey) !== $subkey) {
					$bNumericKeys = false;
					break;
				}
			}
			if ($bNumericKeys) {
				$oNode->setAttribute("type", "array");
				foreach ($data as $key => $value) {
					$this->ToXML($oNode, $value, 'item');
				}
			} else {
				foreach ($data as $key => $value) {
					$this->ToXML($oNode, $value, $key);
				}
			}
		} else {
			$oTextNode = $oRoot->ownerDocument->createTextNode($data);
			$oNode->appendChild($oTextNode);
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
		if ($this->aData == null) {
			libxml_use_internal_errors(true);
			$oXML = @simplexml_load_file($this->sParametersFile);
			if (!$oXML) {
				$aMessage = [];
				foreach (libxml_get_errors() as $oError) {
					$aMessage[] = "(line: {$oError->line}) ".$oError->message; // Beware: $oError->columns sometimes returns wrong (misleading) value
				}
				libxml_clear_errors();
				throw new InvalidParameterException("Invalid Parameters file '{$this->sParametersFile}': ".implode(' ', $aMessage));
			}

			$this->aData = [];
			foreach ($oXML as $key => $oElement) {
				$this->aData[(string)$key] = $this->ReadElement($oElement);
			}
		}
	}

	protected function ReadElement(SimpleXMLElement $oElement)
	{
		$sDefaultNodeType = (count($oElement->children()) > 0) ? 'hash' : 'string';
		$sNodeType = $this->GetAttribute('type', $oElement, $sDefaultNodeType);
		switch ($sNodeType) {
			case 'array':
				$value = [];
				// Treat the current element as zero based array, child tag names are NOT meaningful
				$sFirstTagName = null;
				foreach ($oElement->children() as $oChildElement) {
					if ($sFirstTagName == null) {
						$sFirstTagName = $oChildElement->getName();
					} elseif ($sFirstTagName != $oChildElement->getName()) {
						throw new InvalidParameterException("Invalid Parameters file '{$this->sParametersFile}': mixed tags ('$sFirstTagName' and '".$oChildElement->getName()."') inside array '".$oElement->getName()."'");
					}
					$val = $this->ReadElement($oChildElement);
					$value[] = $val;
				}
				break;

			case 'hash':
				$value = [];
				// Treat the current element as a hash, child tag names are keys
				foreach ($oElement->children() as $oChildElement) {
					if (array_key_exists($oChildElement->getName(), $value)) {
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

		foreach ($oElement->attributes() as $sKey => $oChildElement) {
			if ((string)$sKey == $sAttName) {
				$sRet = (string)$oChildElement;
				break;
			}
		}

		return $sRet;
	}

	/**
	 * Merge the parameters of another Parameters object into this one.
	 *
	 * @param Parameters $oTask Parameters to merge in, e.g. loaded from params.local.xml
	 * @uses self::ReplaceRecursive()
	 */
	public function Merge(Parameters $oTask): void
	{
		$this->aData = static::ReplaceRecursive($this->aData, $oTask->aData);
	}

	/**
	 * Like array_replace_recursive(), but an "array" in the override replaces the base array
	 * as a whole instead of being merged by index. Returns the merged values.
	 *
	 * Both inputs are PHP arrays, but parameters distinguish two kinds of containers:
	 * "array" (type="array" in XML: zero-based list, item tag names are not meaningful)
	 * and "hash" (child tag names are the keys).
	 *
	 * Rules, applied per key of $aOverride:
	 * - key missing in $aBase: the value is added.
	 * - both values are arrays: the array from $aOverride replaces the one from $aBase as a whole.
	 *   This allows an override to remove items from a preconfigured array, or to empty it
	 *   with `<param type="array"/>`.
	 * - both values are containers and at least one is a hash: they are merged recursively,
	 *   so an override only has to contain the keys it changes. An empty array overriding
	 *   a hash therefore leaves the hash untouched.
	 * - any other combination (a scalar is involved, or a container against a scalar): the value
	 *   from $aOverride replaces the one from $aBase, whatever its type.
	 *
	 * Keys that exist only in $aBase are always kept; a merge can never delete a key.
	 *
	 * Unlike {@see array_merge_recursive()}, scalar values are never turned into arrays.
	 * Unlike {@see array_replace_recursive()}, arrays are replaced instead of being merged by index.
	 *
	 * @param array<string, mixed> $aBase Base values (e.g. from params.distrib.xml)
	 * @param array<string, mixed> $aOverride Overriding values (e.g. from params.local.xml)
	 *
	 * @return array<string, mixed> The merged values
	 */
	protected static function ReplaceRecursive(array $aBase, array $aOverride): array
	{
		foreach ($aOverride as $sKey => $value) {
			$bBothContainers = is_array($value) && isset($aBase[$sKey]) && is_array($aBase[$sKey]);
			$bBothArrays = $bBothContainers && array_is_list($value) && array_is_list($aBase[$sKey]);
			$aBase[$sKey] = ($bBothContainers && !$bBothArrays)
				? static::ReplaceRecursive($aBase[$sKey], $value)
				: $value;
		}

		return $aBase;
	}
}
