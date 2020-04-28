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

class TestCsvCollector extends TestCase
{
    private static $COLLECTOR_PATH = "../collectors/";
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

    private function copy($pattern)
    {
        $files = glob($pattern);
        foreach ($files as $file)
        {
            if (is_file($file))
            {
                copy($file, TestCsvCollector::$COLLECTOR_PATH . basename($file));
            }
        }
    }

    public function testOrgCollector()
    {
        $this->copy("./single_csv/*");

        require_once TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.class.inc.php";

        $this->mocked_logger->expects($this->exactly(0))
            ->method("Log");

        $orgCollector = new \iTopPersonCsvCollector();
        $orgCollector->Collect();
        $this->assertTrue(true);
    }

    /**
     * @param $error_file
     * @dataProvider ErrorFileProvider
     */
    public function testCsvErrors($error_file, $error_msg, $exception_msg=false)
    {
        $this->copy("./single_csv/*");
        copy("./single_csv/csv_errors/$error_file", TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.csv");

        require_once TestCsvCollector::$COLLECTOR_PATH . "iTopPersonCsvCollector.class.inc.php";
        $orgCollector = new \iTopPersonCsvCollector();

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
            "OK" => array("../iTopPersonCsvCollector.csv", "")
        );
    }

}
