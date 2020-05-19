<?php

namespace UnitTestFiles\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;

define('APPROOT', dirname(__FILE__).'/../');
require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/csvcollector.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class TestCsvCollector extends TestCase
{
    private static $COLLECTOR_PATH = APPROOT . "/collectors/";
    private $mocked_logger;

    public function setUp()
    {
        parent::setUp();

        $collector_files = glob(TestCsvCollector::$COLLECTOR_PATH . "*");
        foreach ($collector_files as $file)
        {
            unlink($file);
        }

        $this->mocked_logger = $this->createMock("UtilsLogger");
        \Utils::mock_log($this->mocked_logger);

    }

    public function tearDown()
    {
        parent::tearDown();
        $collector_files = glob(TestCsvCollector::$COLLECTOR_PATH . "*");
        foreach ($collector_files as $file)
        {
            unlink($file);
        }
    }

    public function testIOException()
    {
        $this->assertFalse(is_a(new Exception(""), "IOException"));
        $this->assertTrue(is_a(new \IOException(""), "IOException"));
    }

    private function copy($pattern)
    {
        if (! is_dir(TestCsvCollector::$COLLECTOR_PATH))
        {
            mkdir(TestCsvCollector::$COLLECTOR_PATH);
        }

        $files = glob($pattern);
        foreach ($files as $file)
        {
            if (is_file($file))
            {
                $bRes = copy($file, TestCsvCollector::$COLLECTOR_PATH . basename($file));
                if (!$bRes)
                {
                    throw new \Exception("Failed copying $file to " . TestCsvCollector::$COLLECTOR_PATH . basename($file));
                }
            }
        }
    }

    /**
     * @param bool $additional_dir
     * @dataProvider OrgCollectorProvider
     * @throws \Exception
     */
    public function testOrgCollector($additional_dir=false)
    {
        $this->copy(APPROOT . "/test/single_csv/common/*");
        $this->copy(APPROOT . "/test/single_csv/".$additional_dir."/*");

        require_once TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.class.inc.php";

        $this->mocked_logger->expects($this->exactly(0))
            ->method("Log");

        $orgCollector = new \iTopPersonCsvCollector();
        \Utils::LoadConfig();

        $this->assertTrue($orgCollector->Collect());

        $expected_content = file_get_contents(TestCsvCollector::$COLLECTOR_PATH ."expected_generated.csv");

        $this->assertEquals($expected_content, file_get_contents(APPROOT . "/data/iTopPersonCsvCollector-1.csv"));
    }

    public function OrgCollectorProvider()
    {
        return array(
            "nominal" => array("nominal"),
            "charset_ISO" => array("charset_ISO"),
            "separator" => array("separator"),
            "clicommand" => array("clicommand"),
        );
    }

    public function testAbsolutePath()
    {
        $this->copy(APPROOT . "/test/single_csv/common/*");
        $sTargetDir = tempnam(sys_get_temp_dir(), 'build-');
        @unlink($sTargetDir);
        mkdir($sTargetDir);
        $sTargetDir = realpath($sTargetDir);
        $sContent = str_replace("TMPDIR", $sTargetDir, file_get_contents(APPROOT . "/test/single_csv/absolutepath/params.distrib.xml"));
        $oHandle = fopen(APPROOT . "/collectors/params.distrib.xml", "w");
        fwrite($oHandle, $sContent);
        fclose($oHandle);

        $sCsvFile = dirname(__FILE__) . "/single_csv/nominal/iTopPersonCsvCollector.csv";
        if (is_file($sCsvFile))
        {
            copy($sCsvFile, $sTargetDir . "/iTopPersonCsvCollector.csv");
        }
        else
        {
            throw new \Exception("Cannot find $sCsvFile file");
        }

        require_once TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.class.inc.php";

        $this->mocked_logger->expects($this->exactly(0))
            ->method("Log");

        $orgCollector = new \iTopPersonCsvCollector();
        \Utils::LoadConfig();

        $this->assertTrue($orgCollector->Collect());

        $expected_content = file_get_contents(dirname(__FILE__) . "/single_csv/nominal/expected_generated.csv");

        $this->assertEquals($expected_content, file_get_contents(APPROOT . "/data/iTopPersonCsvCollector-1.csv"));
    }

    /**
     * @param $error_file
     * @param $error_msg
     * @param bool $exception_msg
     * @throws \Exception
     * @dataProvider ErrorFileProvider
     */
    public function testCsvErrors($error_file, $error_msg, $exception_msg=false)
    {
        $this->copy(APPROOT . "/test/single_csv/common/*");
        copy(APPROOT . "/test/single_csv/csv_errors/$error_file", TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.csv");

        require_once TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.class.inc.php";
        $orgCollector = new \iTopPersonCsvCollector();
        \Utils::LoadConfig();

        if ($exception_msg) {
            $this->mocked_logger->expects($this->exactly(2))
                ->method("Log")
                ->withConsecutive(array(LOG_ERR, $error_msg), array(LOG_ERR, $exception_msg));
        }
        else{
            $this->mocked_logger->expects($this->exactly(0))
                ->method("Log");
        }
        try{
            $res = $orgCollector->Collect();

            $this->assertEquals($exception_msg ? false : true, $res);
        }
        catch(Exception $e){
            $this->assertEquals($exception_msg, $e->getMessage());
        }
    }

    public function ErrorFileProvider()
    {
        return array(
            "wrong number of line" => array("wrongnumber_columns_inaline.csv", "[iTopPersonCsvCollector] Wrong number of columns (1) on line 2 (expected 18 columns just like in header): aa", 'iTopPersonCsvCollector::Collect() got an exception: Invalid CSV file.'),
            "no primary key" => array("no_primarykey.csv", "[iTopPersonCsvCollector] The mandatory column \"primary_key\" is missing from the csv.", 'iTopPersonCsvCollector::Collect() got an exception: Missing columns in the CSV file.'),
            "no email" => array("no_email.csv", "[iTopPersonCsvCollector] The column \"email\", used for reconciliation, is missing from the csv.", "iTopPersonCsvCollector::Collect() got an exception: Missing columns in the CSV file."),
            "OK" => array("../nominal/iTopPersonCsvCollector.csv", "")
        );
    }
}
