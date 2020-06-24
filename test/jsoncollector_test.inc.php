<?php

namespace UnitTestFiles\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Exception;

define('APPROOT', dirname(__FILE__).'/../');
require_once(APPROOT.'core/parameters.class.inc.php');
require_once(APPROOT.'core/utils.class.inc.php');
require_once(APPROOT.'core/collector.class.inc.php');
require_once(APPROOT.'core/orchestrator.class.inc.php');
require_once(APPROOT.'core/jsoncollector.class.inc.php');
require_once(APPROOT.'core/ioexception.class.inc.php');

class TestJsonCollector extends TestCase
{
    private static $COLLECTOR_PATH = APPROOT . "/collectors/";
    private $mocked_logger;

    public function setUp()
    {
        parent::setUp();

        $collector_files = glob(TestJsonCollector::$COLLECTOR_PATH . "*");
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
        $collector_files = glob(TestJsonCollector::$COLLECTOR_PATH . "*");
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
        if (! is_dir(TestJsonCollector::$COLLECTOR_PATH))
        {
            mkdir(TestJsonCollector::$COLLECTOR_PATH);
        }

        $files = glob($pattern);
        foreach ($files as $file)
        {
            if (is_file($file))
            {
                $bRes = copy($file, TestJsonCollector::$COLLECTOR_PATH . basename($file));
                if (!$bRes)
                {
                    throw new \Exception("Failed copying $file to " . TestJsonCollector::$COLLECTOR_PATH . basename($file));
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
        $this->copy(APPROOT . "/test/single_json/common/*");
        $this->copy(APPROOT . "/test/single_json/".$additional_dir."/*");

        require_once TestJsonCollector::$COLLECTOR_PATH . "iTopPersonJsonCollector.class.inc.php";

        $this->mocked_logger->expects($this->exactly(0))
            ->method("Log");

        $orgCollector = new \ITopPersonJsonCollector();
        \Utils::LoadConfig();

        $this->assertTrue($orgCollector->Collect());

        $expected_content = file_get_contents(TestJsonCollector::$COLLECTOR_PATH ."expected_generated.csv");

        $this->assertEquals($expected_content, file_get_contents(APPROOT . "/data/iTopPersonJsonCollector-1.csv"));
    }

   public function OrgCollectorProvider()
    {
        return array(
            "rest" => array("rest"),
            "url" => array("url"),
        );
    }

    public function testAbsolutePath()
    {
        $this->copy(APPROOT . "/test/single_json/common/*");
        $sTargetDir = tempnam(sys_get_temp_dir(), 'build-');
        @unlink($sTargetDir);
        mkdir($sTargetDir);
        $sTargetDir = realpath($sTargetDir);
        $sContent = str_replace("TMPDIR", $sTargetDir, file_get_contents(APPROOT . "/test/single_json/absolute_path/params.distrib.xml"));
        $oHandle = fopen(APPROOT . "/collectors/params.distrib.xml", "w");
        fwrite($oHandle, $sContent);
        fclose($oHandle);

        $sJsonFile = dirname(__FILE__) . "/single_json/absolute_path/dataTest.json";
        if (is_file($sJsonFile))
        {
            copy($sJsonFile, $sTargetDir . "/iTopPersonJsonCollector.csv");
        }
        else
        {
            throw new \Exception("Cannot find $sJsonFile file");
        }

        require_once TestJsonCollector::$COLLECTOR_PATH . "iTopPersonJsonCollector.class.inc.php";

        $this->mocked_logger->expects($this->exactly(0))
            ->method("Log");

        $orgCollector = new \ITopPersonJsonCollector();
        \Utils::LoadConfig();

        $this->assertTrue($orgCollector->Collect());

        $expected_content = file_get_contents(dirname(__FILE__) . "/single_json/common/expected_generated.csv");

        $this->assertEquals($expected_content, file_get_contents(APPROOT . "/data/iTopPersonJsonCollector-1.csv"));
    }

    /**
     * @param $error_file
     * @param $error_msg
     * @param bool $exception_msg
     * @throws \Exception
     * @dataProvider ErrorFileProvider
     */
    public function testJsonErrors($error_file, $error_msg, $exception_msg=false)
    {
        $this->copy(APPROOT . "/test/single_json/common/*");
        copy(APPROOT . "/test/single_json/json_errors/$error_file", TestJsonCollector::$COLLECTOR_PATH . "iTopPersonJsonCollector.csv");

        require_once TestJsonCollector::$COLLECTOR_PATH . "iTopPersonJsonCollector.class.inc.php";
        $orgCollector = new \iTopPersonJsonoCollector();
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
            "wrong number of line" => array("wrongnumber_columns_inaline.json", "[iTopPersonJsonCollector] Wrong number of columns (1) on line 2 (expected 18 columns just like in header): aa", 'iTopPersonJsonCollector::Collect() got an exception: Invalid JSON file.'),
            "no primary key" => array("no_primarykey.csv", "[iTopPersonJsonCollector] The mandatory column \"primary_key\" is missing from the json.", 'iTopPersonJsonCollector::Collect() got an exception: Missing columns in the JSON file.'),
            "no email" => array("no_email.csv", "[iTopPersonJsonCollector] The column \"email\", used for reconciliation, is missing from the json.", "iTopPersonJsonCollector::Collect() got an exception: Missing columns in the JSON file."),
            "OK" => array("../nominal/iTopPersonJsonCollector.csv", "")
        );
    }
}
