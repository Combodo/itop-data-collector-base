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
            "format_json_1" => array("format_json_1"),
            "format_json_2" => array("format_json_2"),
            "format_json_3" => array("format_json_3"),
        );
    }

    /**
     * @param $additional_dir
     * @param $error_msg
     * @param bool $exception_msg
     * @throws \Exception
     * @dataProvider ErrorFileProvider
     */
    public function testJsonErrors($additional_dir, $error_msg, $exception_msg=false)
    {
        $this->copy(APPROOT . "/test/single_json/common/*");
        $this->copy(APPROOT . "/test/single_json/json_error/".$additional_dir."/*");

        require_once TestJsonCollector::$COLLECTOR_PATH . "iTopPersonJsonCollector.class.inc.php";
        $orgCollector = new \iTopPersonJsonCollector();
        \Utils::LoadConfig();

        if ($exception_msg) {
            $this->mocked_logger->expects($this->exactly(2))
                ->method("Log")
                ->withConsecutive(array(LOG_ERR, $error_msg), array(LOG_ERR, $exception_msg));
        }
        elseif ($error_msg) {
            $this->mocked_logger->expects($this->exactly(1))
                ->method("Log")
                ->withConsecutive(array(LOG_ERR, $error_msg));
        }
        else {
            $this->mocked_logger->expects($this->exactly(0))
                ->method("Log");
        }
        try{
            $res = $orgCollector->Collect();

            $this->assertEquals($error_msg ? false : true, $res);
        }
        catch(Exception $e){
             $this->assertEquals($exception_msg, $e->getMessage());
        }
    }

    public function ErrorFileProvider()
    {
        return array(
            "format_json_1" => array("format_json_1","[ITopPersonJsonCollector] The column \"first_name\", used for reconciliation, is missing from the query.","ITopPersonJsonCollector::Collect() got an exception: Missing columns in the Json file."),
            "format_json_2" => array("format_json_2","ITopPersonJsonCollector::Collect() got an exception: Undefined index: blop",""),
            "format_json_3" => array("format_json_3",'[ITopPersonJsonCollector] Failed to translate data from JSON file: \'C:\gitRepo\iTopCollector\itop-data-collector-base\collectors\dataTest.json\'. Reason: Syntax error',"ITopPersonJsonCollector::Prepare() returned false"),
        );
    }
}
