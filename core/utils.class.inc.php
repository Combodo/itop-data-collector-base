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

define('LOG_NONE', -1);

define('CONF_DIR', APPROOT.'conf/');
require_once(APPROOT.'core/ioexception.class.inc.php');

class Utils
{
	static public $iConsoleLogLevel = LOG_INFO;
	static public $iSyslogLogLevel = LOG_NONE;
	static protected $oConfig = null;
	static protected $aConfigFiles = array();

	static protected $oMockedLogger;

	static public function ReadParameter($sParamName, $defaultValue)
	{
		global $argv;
		
		$retValue = $defaultValue;
        if (is_array($argv))
        {
            foreach ($argv as $iArg => $sArg) {
                if (preg_match('/^--' . $sParamName . '=(.*)$/', $sArg, $aMatches)) {
                    $retValue = $aMatches[1];
                }
            }
        }
		return $retValue;
	}
	
	static public function ReadBooleanParameter($sParamName, $defaultValue)
    {
        global $argv;

        $retValue = $defaultValue;
        if (is_array($argv))
        {
            foreach ($argv as $iArg => $sArg) {
                if (preg_match('/^--' . $sParamName . '$/', $sArg, $aMatches)) {
                    $retValue = true;
                } else if (preg_match('/^--' . $sParamName . '=(.*)$/', $sArg, $aMatches)) {
                    $retValue = ($aMatches[1] != 0);
                }
            }
        }
		return $retValue;
	}
	
	static public function CheckParameters($aOptionalParams)
	{
		global $argv;
		
		$aUnknownParams = array();
        if (is_array($argv))
        {
            foreach($argv as $iArg => $sArg)
            {
                if ($iArg == 0) continue; // Skip program name
                if (preg_match('/^--([A-Za-z0-9_]+)$/', $sArg, $aMatches))
                {
                    // Looks like a boolean parameter
                    if (!array_key_exists($aMatches[1], $aOptionalParams) || ($aOptionalParams[$aMatches[1]] != 'boolean'))
                    {
                        $aUnknownParams[] = $sArg;
                    }
                }
                else if(preg_match('/^--([A-Za-z0-9_]+)=(.*)$/', $sArg, $aMatches))
                {
                    // Looks like a regular parameter
                    if (!array_key_exists($aMatches[1], $aOptionalParams) || ($aOptionalParams[$aMatches[1]] == 'boolean'))
                    {
                        $aUnknownParams[] = $sArg;
                    }
                }
                else
                {
                    $aUnknownParams[] = $sArg;
                }
            }
        }
		return $aUnknownParams;
	}

	/**
	 * Logs a message to the centralized log for the application, with the given priority
	 *
	 * @param int $iPriority Use the LOG_* constants for priority e.g. LOG_WARNING, LOG_INFO, LOG_ERR... (see:
	 *     www.php.net/manual/en/function.syslog.php)
	 * @param string $sMessage The message to log
	 *
	 * @return void
	 * @throws \Exception
	 */
	static public function Log($iPriority, $sMessage) {
		//testing only LOG_ERR
		if (self::$oMockedLogger) {
			if ($iPriority <= self::$iConsoleLogLevel && $iPriority <= LOG_ERR) {
				self::$oMockedLogger->Log($iPriority, $sMessage);
			}
		}

		switch ($iPriority) {
			case LOG_EMERG:
				$sPrio = 'Emergency';
				break;

			case LOG_ALERT:
				$sPrio = 'Alert';
				break;
			case LOG_CRIT:
				$sPrio = 'Critical Error';
				break;

			case LOG_ERR:
				$sPrio = 'Error';
				break;

			case LOG_WARNING:
				$sPrio = 'Warning';
				break;

			case LOG_NOTICE:
				$sPrio = 'Notice';
				break;

			case LOG_INFO:
				$sPrio = 'Info';
				break;

			case LOG_DEBUG:
				$sPrio = 'Debug';
				break;
		}

		if ($iPriority <= self::$iConsoleLogLevel) {
			$log_date_format = self::GetConfigurationValue("console_log_dateformat", "[Y-m-d H:i:s]");
			$txt = date($log_date_format)."\t[".$sPrio."]\t".$sMessage."\n";
			echo $txt;
		}

		if ($iPriority <= self::$iSyslogLogLevel) {
			openlog('iTop Data Collector', LOG_PID, LOG_USER);
			syslog($iPriority, $sMessage);
			closelog();
		}
	}

	static public function MockLog($oMockedLogger) {
		self::$oMockedLogger = $oMockedLogger;
	}

    /**
     * Load the configuration from the various XML condifuration files
     * @throws Exception
     * @return Parameters
     */
	static public function LoadConfig()
	{
	    $sCustomConfigFile = Utils::ReadParameter('config_file', null);

		self::$aConfigFiles[] = CONF_DIR.'params.distrib.xml';
		self::$oConfig = new Parameters(CONF_DIR.'params.distrib.xml');
		if (file_exists(APPROOT.'collectors/params.distrib.xml'))
		{
		    self::MergeConfFile(APPROOT.'collectors/params.distrib.xml');
		}
		if ($sCustomConfigFile !== null)
		{
		    // A custom config file was supplied on the command line
		    if (file_exists($sCustomConfigFile))
		    {
                self::MergeConfFile($sCustomConfigFile);
		    }
		    else
		    {
		        throw new Exception("The specified configuration file '$sCustomConfigFile' does not exist.");
		    }
		}
		else if (file_exists(CONF_DIR.'params.local.xml'))
		{
            self::MergeConfFile(CONF_DIR.'params.local.xml');
		}
		return self::$oConfig;
	}

    static private function MergeConfFile($sFilePath)
    {
        self::$aConfigFiles[] = $sFilePath;
        $oLocalConfig = new Parameters($sFilePath);
        self::$oConfig->Merge($oLocalConfig);
    }
	
	/**
	 * Get the value of a configuration parameter
	 * @param string $sCode
	 * @param mixed $defaultValue
	 * @throws Exception
	 * @return mixed
	 */
	static public function GetConfigurationValue($sCode, $defaultValue = '')
	{
		if (self::$oConfig == null)
		{
			self::LoadConfig();
		}
		
		$value = self::$oConfig->Get($sCode, $defaultValue);
		$value = self::Substitute($value);

		return $value;
	}
	
	/**
	 * Dump information about the configuration (value of the parameters)
	 * @throws Exception
	 * @return string
	 */
	static public function DumpConfig()
	{
		if (self::$oConfig == null)
		{
		    self::LoadConfig();
		}
		return self::$oConfig->Dump();	
	}
	
	/**
	 * Get the ordered list of configuration files loaded
	 * @throws Exception
	 * @return string
	 */
	static public function GetConfigFiles()
	{
		if (self::$oConfig == null)
		{
		    self::LoadConfig();
		}
		return self::$aConfigFiles;	
	}
	
	static protected function Substitute($value)
	{
		if (is_array($value))
		{
			// Recursiverly process each entry
			foreach($value as $key => $val)
			{
				$value[$key] = self::Substitute($val);
			}
		}
		else if (is_string($value))
		{
			preg_match_all('/\$([A-Za-z0-9-_]+)\$/', $value, $aMatches);
			$aReplacements = array();
			if(count($aMatches) > 0)
			{
				foreach ($aMatches[1] as $sSubCode)
				{
					$aReplacements['$'.$sSubCode.'$'] = self::GetConfigurationValue($sSubCode, '#ERROR_UNDEFINED_PLACEHOLDER_'.$sSubCode.'#');
				}
				$value = str_replace(array_keys($aReplacements), $aReplacements, $value);
			}
		}
		else
		{
			// Do nothing, return as-is
		}

		return $value;		
	}
	
	/**
	 * Return the (valid) location where to store some temporary data
	 * Throws an exception if the directory specified in the 'data_path' configuration does not exist and cannot be created
	 * @param string $sFileName
	 * @throws Exception
	 * @return string
	 */
	static public  function GetDataFilePath($sFileName)
	{
	    $sPath = static::GetConfigurationValue('data_path', '%APPROOT%/data/');
	    $sPath = str_replace('%APPROOT%', APPROOT, $sPath); // substitute the %APPROOT% placeholder with its actual value
	    $sPath = rtrim($sPath, '/').'/'; // Make that the path ends with exactly one /
	    if (!file_exists($sPath))
	    {
	        if (!mkdir($sPath, 0700, true)) throw new Exception("Failed to create data_path: '$sPath'. Either create the directory yourself or make sure that the script has enough rights to create it.");
	    }
	    return $sPath.basename($sFileName);
	}
	
	/**
	 * Helper to execute an HTTP POST request
	 * Source: http://netevil.org/blog/2006/nov/http-post-from-php-without-curl
	 *         originaly named after do_post_request
	 * Does not require cUrl but requires openssl for performing https POSTs.
	 *
	 * @param string $sUrl The URL to POST the data to
	 * @param hash $aData The data to POST as an array('param_name' => value)
	 * @param string $sOptionnalHeaders Additional HTTP headers as a string with newlines between headers
	 * @param hash	$aResponseHeaders An array to be filled with reponse headers: WARNING: the actual content of the array depends on the library used: cURL or fopen, test with both !! See: http://fr.php.net/manual/en/function.curl-getinfo.php
	 * @param hash $aCurlOptions An (optional) array of options to pass to curl_init. The format is 'option_code' => 'value'. These values have precedence over the default ones
	 * @return string The result of the POST request
	 * @throws Exception
	 */
	static public function DoPostRequest($sUrl, $aData, $sOptionnalHeaders = null, &$aResponseHeaders = null, $aCurlOptions = array())
	{
		// $sOptionnalHeaders is a string containing additional HTTP headers that you would like to send in your request.
	
		if (function_exists('curl_init'))
		{
			// If cURL is available, let's use it, since it provides a greater control over the various HTTP/SSL options
			// For instance fopen does not allow to work around the bug: http://stackoverflow.com/questions/18191672/php-curl-ssl-routinesssl23-get-server-helloreason1112
			// by setting the SSLVERSION to 3 as done below.
			$aHTTPHeaders = array();
			if ($sOptionnalHeaders !== null)
			{
				$aHeaders = explode("\n", $sOptionnalHeaders);
				foreach($aHeaders as $sHeaderString)
				{
					if(preg_match('/^([^:]): (.+)$/', $sHeaderString, $aMatches))
					{
						$aHTTPHeaders[$aMatches[1]] = $aMatches[2];
					}
				}
			}
			// Default options, can be overloaded/extended with the 4th parameter of this method, see above $aCurlOptions
			$aOptions = array(
				CURLOPT_RETURNTRANSFER	=> true,     // return the content of the request
				CURLOPT_HEADER			=> false,    // don't return the headers in the output
				CURLOPT_FOLLOWLOCATION	=> true,     // follow redirects
				CURLOPT_ENCODING		=> "",       // handle all encodings
				CURLOPT_USERAGENT		=> "spider", // who am i
				CURLOPT_AUTOREFERER		=> true,     // set referer on redirect
				CURLOPT_CONNECTTIMEOUT	=> 120,      // timeout on connect
				CURLOPT_TIMEOUT			=> 120,      // timeout on response
				CURLOPT_MAXREDIRS		=> 10,       // stop after 10 redirects
				CURLOPT_SSL_VERIFYHOST	=> 0,   	 // Disabled SSL Cert checks
				CURLOPT_SSL_VERIFYPEER	=> 0,   	 // Disabled SSL Cert checks
				// SSLV3 (CURL_SSLVERSION_SSLv3 = 3) is now considered as obsolete/dangerous: http://disablessl3.com/#why
				// but it used to be a MUST to prevent a strange SSL error: http://stackoverflow.com/questions/18191672/php-curl-ssl-routinesssl23-get-server-helloreason1112
				// CURLOPT_SSLVERSION		=> 3,
				CURLOPT_POST			=> count($aData),
				CURLOPT_POSTFIELDS		=> http_build_query($aData),
				CURLOPT_HTTPHEADER		=> $aHTTPHeaders,
			);
			$aAllOptions = $aCurlOptions + $aOptions;
			$ch = curl_init($sUrl);
			curl_setopt_array($ch, $aAllOptions);
			$response = curl_exec($ch);
			$iErr = curl_errno($ch);
			$sErrMsg = curl_error( $ch );
			$aHeaders = curl_getinfo( $ch );
			if ($iErr !== 0)
			{
				throw new IOException("Problem opening URL: $sUrl"
                    .PHP_EOL."    error msg: $sErrMsg"
                    .PHP_EOL."    curl_init error code: $iErr (cf https://www.php.net/manual/en/function.curl-errno.php)");
			}
			if (is_array($aResponseHeaders))
			{
				$aHeaders = curl_getinfo($ch);
				foreach($aHeaders as $sCode => $sValue)
				{
					$sName = str_replace(' ' , '-', ucwords(str_replace('_', ' ', $sCode))); // Transform "content_type" into "Content-Type"
					$aResponseHeaders[$sName] = $sValue;
				}
			}
			curl_close( $ch );
		}
		else
		{
			// cURL is not available let's try with streams and fopen...
				
			$sData = http_build_query($aData);
			$aParams = array('http' => array(
				'method' => 'POST',
				'content' => $sData,
				'header'=> "Content-type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($sData)."\r\n",
			));
			if ($sOptionnalHeaders !== null)
			{
				$aParams['http']['header'] .= $sOptionnalHeaders;
			}
			$ctx = stream_context_create($aParams);
	
			$fp = @fopen($sUrl, 'rb', false, $ctx);
			if (!$fp)
			{
				$error_arr = error_get_last();
				if (is_array($error_arr))
				{
					throw new IOException("Wrong URL: $sUrl, Error: ". json_encode($error_arr));
				}
				elseif ((strtolower(substr($sUrl, 0, 5)) == 'https') && !extension_loaded('openssl'))
				{
					throw new IOException("Cannot connect to $sUrl: missing module 'openssl'");
				}
				else
				{
					throw new IOException("Wrong URL: $sUrl");
				}
			}
			$response = @stream_get_contents($fp);
			if ($response === false)
			{
				throw new IOException("Problem reading data from $sUrl, $php_errormsg");
			}
			if (is_array($aResponseHeaders))
			{
				$aMeta = stream_get_meta_data($fp);
				$aHeaders = $aMeta['wrapper_data'];
				foreach($aHeaders as $sHeaderString)
				{
					if(preg_match('/^([^:]+): (.+)$/', $sHeaderString, $aMatches))
					{
						$aResponseHeaders[$aMatches[1]] = trim($aMatches[2]);
					}
				}
			}
		}
		return $response;
	}

	/**
	 * Pretty print a JSON formatted string. Copied/pasted from http://stackoverflow.com/questions/6054033/pretty-printing-json-with-php
	 * @param string $json A JSON formatted object definition
	 * @return string The nicely formatted JSON definition
	 */
	public static function JSONPrettyPrint($json)
	{
	    $result = '';
	    $level = 0;
	    $in_quotes = false;
	    $in_escape = false;
	    $ends_line_level = NULL;
	    $json_length = strlen( $json );
	
	    for( $i = 0; $i < $json_length; $i++ ) {
	        $char = $json[$i];
	        $new_line_level = NULL;
	        $post = "";
	        if( $ends_line_level !== NULL ) {
	            $new_line_level = $ends_line_level;
	            $ends_line_level = NULL;
	        }
	        if ( $in_escape ) {
	            $in_escape = false;
	        } else if( $char === '"' ) {
	            $in_quotes = !$in_quotes;
	        } else if( ! $in_quotes ) {
	            switch( $char ) {
	                case '}': case ']':
	                    $level--;
	                    $ends_line_level = NULL;
	                    $new_line_level = $level;
	                    break;
	
	                case '{': case '[':
	                    $level++;
	                case ',':
	                    $ends_line_level = $level;
	                    break;
	
	                case ':':
	                    $post = " ";
	                    break;
	
	                case " ": case "\t": case "\n": case "\r":
	                    $char = "";
	                    $ends_line_level = $new_line_level;
	                    $new_line_level = NULL;
	                    break;
	            }
	        } else if ( $char === '\\' ) {
	            $in_escape = true;
	        }
	        if( $new_line_level !== NULL ) {
	            $result .= "\n".str_repeat( "\t", $new_line_level );
	        }
	        $result .= $char.$post;
	    }
	
	    return $result;
	}

    /**
     * Executes a command and returns an array with exit code, stdout and stderr content
     *
     * @param string $cmd - Command to execute
     *
     * @return false|string
     * @throws \Exception
     */
    function Exec($sCmd) {
        $iBeginTime = time();
        $sWorkDir = APPROOT;
        $aDescriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w"),  // stderr
        );
	    Utils::Log(LOG_INFO, "Command: $sCmd. Workdir: $sWorkDir");
        $rProcess = proc_open($sCmd, $aDescriptorSpec, $aPipes, $sWorkDir, null);

        $sStdOut = stream_get_contents($aPipes[1]);
        fclose($aPipes[1]);

        $sStdErr = stream_get_contents($aPipes[2]);
        fclose($aPipes[2]);

        $iCode = proc_close($rProcess);

        $iElapsed = time() - $iBeginTime;
        if (0 === $iCode) {
            Utils::Log(LOG_INFO, "elapsed:${iElapsed}s output: $sStdOut");
            return $sStdOut;
        } else {
            throw new Exception("Command failed : $sCmd \n\t\t=== with status:$iCode \n\t\t=== stderr:$sStdErr \n\t\t=== stdout: $sStdOut");
        }
    }
}

class UtilsLogger
{
    /**
     * UtilsLogger constructor.
     */
    public function __construct()
    {
    }

    public function Log($iPriority, $sMessage)
    {
    }
}
