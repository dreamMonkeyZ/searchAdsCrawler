<?php
/*
 * type=crawler&hour=1&crawlerType=bing
 * 命令行: sudo php adsCrawler.php crawler 1 bing
 * 命令行: sudo php adsCrawler.php crawler 1 google
 *
 * 命令行: sudo php adsCrawler.php summary
 */
require_once '/var/www/searchAdsCrawler/simple_html_dom.php';
require_once '/var/www/searchAdsCrawler/random_userAgent.php';
require_once '/var/www/searchAdsCrawler/mysql.php';

class Cron_AdsCrawler_Controller
{
    //crawler相关
    private $hour;

    private $googleCount = 0;
    private $crawlerType; //数据抓去类型 1:google  2:bing

    private $searchAds_PC_Data = array();
    private $searchAds_MB_Data = array();
    private $pla_PC_Data = array();
    private $pla_MB_Data = array();
    private $bing_PC_Data = array();
    private $bing_MB_Data = array();

    private $urlTemplate = array();

    private $keywords = array();

    private $optArrayPC = array();

    private $optArrayMB = array();

    //summary相关
    private $detailData = array();
    private $dailyData = array();
    private $scoreData = array();
    private $hourField = array();
    private $dailyDayField = array();
    private $scoreDayField = array();
    private $email_reciever = array(
        "sdai@i9i8.com",
        "ycwu@i9i8.com",
        "jchu@i9i8.com",
    );
    private $tplAdminDir;

    //common数据
    private $dbhw;

    //参数
    private $param;


    private $currentDate;	


    function __construct($param)
    {
        $this->dbhw = new Mysql("localhost:3306", "dbuser0114", "dbpswd0114");
	
	date_default_timezone_set("America/Los_Angeles");

	$this->currentDate  = date('Y-m-d');

        $this->param = $param;
        $this->crawlerType = $param[3];

        //从数据库中获取需要搜索和分析的关键字
        $this->initKeyword();
        if (empty($this->keywords)) {
            exit('关键词列表为空!');
        }

        if($param[1] == 'crawler'){
            // 爬取数据之前，进行基础数据结构的初始化
            $this->initCrawler();
            // 汇总数据之前, 进行基础数据结构的初始化
        }
	$this->initSummary();
    }

    function indexAction()
    {
        //crawler负责数据的爬取和存储， summary负责数据的汇总以及邮件的发送
        if($this->param[1] == 'crawler'){
//            $this->testSpecialKeyword(); //针对特殊的关键字进行测试
            $this->crawlerAction();
        }elseif($this->param[1] == 'summary'){
            $this->summaryAction();
        }

    }

    function initCrawler(){
        $hour = $this->param[2];
        if (empty($hour)) {
            exit('小时属于必须参数！');
        }

        $this->hour = $hour;
        $this->urlTemplate = array(
            "searchAds" => array(
                "firstPage" => array(
                    "preUrl" => "https://www.google.com/search?q=",
                    "suffixUrl" => ""
                ),
                "secondPage" => array(
                    "preUrl" => "https://www.google.com/search?q=",
                    "suffixUrl" => "&start=10"
                ),
                "thirdPage" => array(
                    "preUrl" => "https://www.google.com/search?q=",
                    "suffixUrl" => "&start=20"
                ),
            ),
            "bingAds" => array(
                "firstPage" => array(
                    "preUrl" => "http://www.bing.com/search?q=",
                    "suffixUrl" => ""
                ),
                "sencondPage" => array(
                    "preUrl" => "http://www.bing.com/search?q=",
                    "suffixUrl" => "&first=11"
                ),
                "thirdPage" => array(
                    "preUrl" => "http://www.bing.com/search?q=",
                    "suffixUrl" => "&first=21"
                ),
            )
        );

        //pc和mb的useragent不同，curl需要不同的设置
        $this->optArrayPC = array(
            CURLOPT_HTTPHEADER => array('User-Agent:' . random_user_agent('pc')),
        );
        $this->optArrayMB = array(
            CURLOPT_HTTPHEADER => array('User-Agent:' . random_user_agent('mb')),
        );

        foreach ($this->keywords as $index => $keyword) {
            $this->searchAds_PC_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "google_Ads_PC",
                "weight" => 17,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
            $this->searchAds_MB_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "google_Ads_MB",
                "weight" => 17,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
            $this->pla_PC_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "google_Pla_PC",
                "weight" => 0,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
            $this->pla_MB_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "google_Pla_MB",
                "weight" => 0,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
            $this->bing_PC_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "bing_Ads_PC",
                "weight" => 17,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
            $this->bing_MB_Data[$index] = array(
                "hour" => $this->hour,
                "type" => "bing_Ads_MB",
                "weight" => 17,
                "keyword_id" => $keyword['id'],
                "create_time" => date('Y-m-d H:i:s'),
                "html_content" => '',
            );
        }
    }

    function initSummary(){

        $scoreDayFiled = array();
        $dailyDayField = array();
        $hourField = array();

        //detail 12个小时数据
        for($hour = 1; $hour <= 12; $hour++){
            $adsHourData[$hour] = '';
            $plaHourData[$hour] = '';
            $hourField[] = $hour . ":00";
        }
        //测试5个小时
        ###############################################################################测试代码
//        $hourField = array("3:00", "4:00", "10:00", "16:00", "17:00", "18:00", "19:00", "20:00", "21:00", "22:00");
//        $adsHourData = array(
//            3 => 17,
//            4 => 17,
//            10 => 17,
//            16 => 17,
//            17 => 17,
//            18 => 17,
//            19 => 17,
//            20 => 17,
//            21 => 17,
//            22 => 17,
//        );
//
//        $plaHourData = array(
//            3 => 0,
//            4 => 0,
//            10 => 0,
//            16 => 0,
//            17 => 0,
//            18 => 0,
//            19 => 0,
//            20 => 0,
//            21 => 0,
//            22 => 0
//        );
        ###############################################################################

        // daily 30天数据
        for($interval = 0; $interval <= 29; $interval++){
            $day = date("Y-m-d",strtotime("-{$interval} day",strtotime($this->currentDate)));
            $dailyDayField[] = $day;
            $adsDayData[$day] = '';
            $plaDayData[$day] = '';
        }

        //score 7天数据
        for($interval = 0; $interval <= 6; $interval++){
            $day = date("Y-m-d",strtotime("-{$interval} day",strtotime($this->currentDate)));
            $scoreDayFiled[] = $day;
            $scoreDayData[$day] = '';
        }

        $this->hourField = $hourField;
        $this->dailyDayField = $dailyDayField;
        $this->scoreDayField = $scoreDayFiled;

        foreach ($this->keywords as $index => $keyword){
            // detailData的初始化
            $this->detailData['google_Ads_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $adsHourData,
            );
            $this->detailData['google_Ads_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $adsHourData,
            );
            $this->detailData['google_Pla_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $plaHourData,
            );
            $this->detailData['google_Pla_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $plaHourData,
            );
            $this->detailData['bing_Ads_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $adsHourData,
            );
            $this->detailData['bing_Ads_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "hour" => $adsHourData
            );

            //dailyData的初始化
            $this->dailyData['google_Ads_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $adsDayData,
            );
            $this->dailyData['google_Ads_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $adsDayData,
            );
            $this->dailyData['google_Pla_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $plaDayData,
            );
            $this->dailyData['google_Pla_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $plaDayData,
            );
            $this->dailyData['bing_Ads_PC'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $adsDayData,
            );
            $this->dailyData['bing_Ads_MB'][$keyword['id']] = array(
                "keyword" => $keyword['keyword'],
                "day" => $adsDayData,
            );
        }

        //scoreData初始化
        $this->scoreData['google_Ads_PC'] = array(
            "day" => $scoreDayData,
        );
        $this->scoreData['google_Ads_MB'] = array(
            "day" => $scoreDayData,
        );
        $this->scoreData['google_Pla_PC'] = array(
            "day" => $scoreDayData,
        );
        $this->scoreData['google_Pla_MB'] = array(
            "day" => $scoreDayData,
        );
        $this->scoreData['bing_Ads_PC'] = array(
            "day" => $scoreDayData,
        );
        $this->scoreData['bing_Ads_MB'] = array(
            "day" => $scoreDayData,
        );
    }

    /*
     * 数据爬取入口函数
     */
    function crawlerAction()
    {
        echo "脚本start，时间：" . date('Y-m-d H:i:s') . ", 小时 ：{$this->hour}" . PHP_EOL;
        if($this->crawlerType == 'google'){
            $this->crawlerGoogleData();
        }elseif($this->crawlerType == 'bing'){
            $this->crawlerBingData();
        }
        echo "----------开始插入记录------------" . PHP_EOL;
        $this->recordHourData();
	if($this->crawlerType == 'google'){
	    $this->summaryAction();
	}
        echo "脚本end，时间：" . date('Y-m-d H:i:s') . ", 小时 ：{$this->hour}" . PHP_EOL;
    }

    /*
     * 数据汇总并发送邮件
     * # 后续如有人接触到这个需求 ，请@ycwu, 需求号2018
     * 7天score数据（正文-daily_avg）：单元格数据含义 - ads：所有的关键字在某天的daily_avg <= 3的出现次数 pla：所有的关键字在某天的daily_avg有值的出现次数
     * 30天avg数据（正文-daily_avg） ：单元格数据含义 - 某个关键字在某天有效检索rank总和／有效检索次数 ， 如果hour detail的na数量 >= 7， 则daily记录为NA
     * 每天的detail（附件-daily_avg）：单元格数据含义 -  如下：
         * google ads／bing ads ／google pla 三种渠道，pc和mb两个平台，共6种广告数据
         * 有效检索是指weight不为na的记录
         * ads ：默认weight为17   pla ：默认weight为0     无效检索 = 'NA'
         *
         * detail 单元格数据含义 ：某个关键字在具体hour检索得出的weight结果
     */
    function summaryAction()
    {
        $this->setDetailData();
        $this->setDailyAndScoreData();
        $html = $this->makeupHtml();
        $fileDate =  $this->currentDate . ".xls";
        $fileDir = "/var/log/cronjob/crawlerSummary/";
        if(!is_dir($fileDir)){
            mkdir($fileDir, 0777, true);
            chmod($fileDir, 0777);
        }
        $filePath = $fileDir . $fileDate;
        file_put_contents("{$fileDir}" . $fileDate, $html);
        $cmd = "sudo /usr/local/bin/sendEmail -f 'noreply@azazie.com' -u 'Search Ads Report' -t 'zsdai@i9i8.com,ycwu@i9i8.com,jchu@i9i8.com' -a '{$filePath}' -m '数据如下'";
        $r = `$cmd`;print_r($r);
//        send_mail($this->email_reciever, $html, '', 'noreply@azazie.com');
    }

    function setDetailData(){
        $startDate = $this->currentDate;
        $endDate = date("Y-m-d", strtotime("+1 day",strtotime($this->currentDate)));
        $detailSql = "
            select weight, hour, keyword, type
            from azazie.ads_report_record
            where
               create_time between '$startDate' and '$endDate';
        ";
        $res = $this->dbhw->getAll($detailSql);
        $res || $res = array();
        foreach ($res as $index => $item){
            $curHour = $item['hour'];
            $curKeyword = $item['keyword'];
            $curType = $item['type'];
            $this->detailData[$curType][$curKeyword]['hour'][$curHour] = $item['weight'];
        }
    }

    function setDailyAndScoreData(){
        $endDate = date("Y-m-d", strtotime("+1 day",strtotime($this->currentDate)));
        $startDate = date("Y-m-d", strtotime("-29 day",strtotime($this->currentDate)));
        $dailySql = "
            select 
                keyword, type, date_format(create_time,'%Y-%m-%d') as day, 
                if(sum(if(weight = 'NA', 1, 0)) > 6, 'NA', round(sum(if(weight in ('NA','N'),0,weight)) / sum(if(weight in ('NA','N'),0,1)),2)) as dayAvg
            from azazie.ads_report_record
            where 
                create_time BETWEEN '$startDate' and '$endDate'
            group by keyword, date_format(create_time,'%Y-%m-%d'), type
        ";
        $res = $this->dbhw->getAll($dailySql);
        $res || $res = array();
        foreach ($res as $index => $item){
            $curDay = $item['day'];
            $curKeyword = $item['keyword'];
            $curType = $item['type'];
            $this->dailyData[$curType][$curKeyword]['day'][$curDay] = $item['dayAvg'];

            //pla和ads的score统计不一样
            if(in_array($curType, array("google_Pla_MB","google_Pla_PC"))){
                if($item['dayAvg'] != 'NA' && $item['dayAvg'] >0){
                    $this->scoreData[$curType]['day'][$curDay] ++;
                }
            }else{
                if($item['dayAvg'] != 'NA' && $item['dayAvg'] <= 3 && $item['dayAvg'] >0){
                    $this->scoreData[$curType]['day'][$curDay] ++;
                }
            }
        }
    }

    function makeupHtml(){

        $html = "<!doctype html>
        <html>
        <head>
            <meta charset=\"utf-8\">
            <title>Search Ads Report</title>
        </head>
        <body>
        <table border='1' style='border-collapse: collapse; border: 1px solid #000000;'>
            <tr align='center'>
                <th><strong>Search Score</strong></th> ";
                foreach ($this->scoreDayField as $index => $day){
                    $html .= "<th>{$day}</th>";
                }
                $html .= "
            </tr>";
                foreach ($this->scoreData as $type => $data){
                    $html .= "<tr align='center'>
                                  <td>{$type}</td>";
                    foreach ($data['day'] as $day => $value){
                        $html .= "<td>{$value}</td>";
                    }
                    $html .= "</tr>";
                }
            $html .= "
        </table>
        <br><br><br>
        
        <table border='1' style='border-collapse: collapse; border: 1px solid #000000;'>
                <tr align='center'>
                    <th><strong>No.</strong></th>
                    <th><strong>Adwords_PC</strong>
            ";
            foreach($this->dailyDayField as $index => $day){
                $html .= "<th>{$day}</th>";
            }

            $html .= "</tr>" ;
            foreach ($this->dailyData['google_Ads_PC'] as $key_id => $item){
                $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
                foreach($item['day'] as $day => $value){
                    $html .= "<td>{$value}</td>";
                }
                $html .= "</tr>";
            }
    $html .= "
    <tr>
        <td></td>
        <td></td> ";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<td></td>";
        }

    $html .= "</tr> ";
    $html .= "
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Adwords_MB</strong>";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<th>{$day}</th>";
        };

    $html .= "</tr>";
        foreach ($this->dailyData['google_Ads_MB'] as $key_id => $item){
            $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
            foreach($item['day'] as $day => $value){
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }
        $html .= "
    <tr>
        <td></td>
        <td></td> ";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<td></td>";
        }

        $html .= "</tr> ";


        $html .= "
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Bing-PC</strong>";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<th>{$day}</th>";
        };

        $html .= "</tr>";
        foreach ($this->dailyData['bing_Ads_PC'] as $key_id => $item){
            $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
            foreach($item['day'] as $day => $value){
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

        $html .= "
    <tr>
        <td></td>
        <td></td> ";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<td></td>";
        }

        $html .= "</tr> ";



        $html .= "
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Bing-MB</strong>";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<th>{$day}</th>";
        };

        $html .= "</tr>";
        foreach ($this->dailyData['bing_Ads_MB'] as $key_id => $item){
            $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
            foreach($item['day'] as $day => $value){
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

        $html .= "
    <tr>
        <td></td>
        <td></td> ";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<td></td>";
        }

        $html .= "</tr> ";



        $html .= "
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>PLA-PC</strong>";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<th>{$day}</th>";
        };

        $html .= "</tr>";
        foreach ($this->dailyData['google_Pla_PC'] as $key_id => $item){
            $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
            foreach($item['day'] as $day => $value){
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

        $html .= "
    <tr>
        <td></td>
        <td></td> ";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<td></td>";
        }

        $html .= "</tr> ";





        $html .= "
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>PLA-MB</strong>";
        foreach($this->dailyDayField as $index => $day){
            $html .= "<th>{$day}</th>";
        };

        $html .= "</tr>";
        foreach ($this->dailyData['google_Pla_MB'] as $key_id => $item){
            $html .= "<tr align='center'>
                            <td>{$key_id}</td>
                            <td>{$item['keyword']}</td>";
            foreach($item['day'] as $day => $value){
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

        $html .= "
</table>
<br><br><br>
        
<table border='1' style='border-collapse: collapse; border: 1px solid #000000;'>
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Adwords_PC</strong></th> ";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }
    $html .= "</tr>";

    foreach ($this->detailData['google_Ads_PC'] as $key_id => $data) {
        $html .= "<tr align='center'>
        <td>{$key_id}</td>
        <td>{$data['keyword']}</td>";
        foreach ($data['hour'] as $index => $value) {
            $html .= "<td>{$value}</td>";
        }
        $html .= "</tr>";
    }

    $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>";




    $html .="
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Adwords_MB</strong></th>";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }

    $html .="</tr>";
        foreach ($this->detailData['google_Ads_MB'] as $key_id => $data){
            $html .= "<tr align='center'>
                <td>{$key_id}</td>
                <td>{$data['keyword']}</td>";
            foreach ($data['hour'] as $index => $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

     $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>";


        $html .="
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Bing-PC</strong></th>";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }

    $html .="</tr>";
        foreach ($this->detailData['bing_Ads_PC'] as $key_id => $data){
            $html .= "<tr align='center'>
                <td>{$key_id}</td>
                <td>{$data['keyword']}</td>";
            foreach ($data['hour'] as $index => $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

     $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>";






        $html .="
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>Bing-MB</strong></th>";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }

    $html .="</tr>";
        foreach ($this->detailData['bing_Ads_MB'] as $key_id => $data){
            $html .= "<tr align='center'>
                <td>{$key_id}</td>
                <td>{$data['keyword']}</td>";
            foreach ($data['hour'] as $index => $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

     $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>";






         $html .="
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>PLA_PC</strong></th>";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }

    $html .="</tr>";
        foreach ($this->detailData['google_Pla_PC'] as $key_id => $data){
            $html .= "<tr align='center'>
                <td>{$key_id}</td>
                <td>{$data['keyword']}</td>";
            foreach ($data['hour'] as $index => $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

     $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>";




        $html .="
    <tr align='center'>
        <th><strong>No.</strong></th>
        <th><strong>PLA_MB</strong></th>";

        foreach ($this->hourField as $index => $hour){
            $html .= "<th>{$hour}</th>";
        }

    $html .="</tr>";
        foreach ($this->detailData['google_Pla_MB'] as $key_id => $data){
            $html .= "<tr align='center'>
                <td>{$key_id}</td>
                <td>{$data['keyword']}</td>";
            foreach ($data['hour'] as $index => $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

     $html .= "<tr>
                <td></td>
                <td></td>";
    foreach ($this->hourField as $index => $hour) {
        $html .= "<td></td>";
    }
    $html .= "</tr>
</table>

</body>
</html>";
        return $html;
    }

    /*
     * 爬取google search数据（searchAds + pla）
     */
    function crawlerGoogleData()
    {
        foreach ($this->keywords as $index => $keyInfo) {
            echo "google关键字$index~~~~~~~~~~~~~~~~~~~~~~~~~" . PHP_EOL;

            //记录每个关键字在pc和mobile上搜出来的广告总数，如果广告总数为0，则为此关键字的排行设置为一个特殊的值none，表示此次搜索没有任何广告消息
        
	$adsPcTotal = 0;
            $adsMbTotal = 0;
            $plaPcTotal = 0;
            $plaMbTotal = 0;
            //google一个关键字的前三页都没有广告，则再重复一次，最多重复三次， times记录重复的次数, 且：重复爬取的时候不需要在分析pla（人工search结果中也存在很多查不到的情况，所以暂时不考虑）
            $times = 0;
            for($times; $times < 3; $times ++){
                if($adsPcTotal == 0 || $plaPcTotal == 0){
                    $this->crawlerGooglePcData($keyInfo, $index, $adsPcTotal,$plaPcTotal, $times);
                }
                if($adsMbTotal == 0 || $plaMbTotal == 0){
                    $this->crawlerGoogleMbData($keyInfo, $index, $adsMbTotal,$plaMbTotal, $times);
                }

                if($adsPcTotal > 0 && $adsMbTotal > 0 && $plaPcTotal > 0 && $plaMbTotal > 0){
                    break;
                }
            }

            if($adsPcTotal == 0){
                $this->searchAds_PC_Data[$index]['weight'] = 'N';
            }

            if($adsMbTotal == 0){
                $this->searchAds_MB_Data[$index]['weight'] = 'N';
            }

            if($plaPcTotal == 0){
                $this->pla_PC_Data[$index]['weight'] = 'N';
            }

            if($plaMbTotal == 0){
                $this->plaMbTotal[$index]['weight'] = 'N';
            }
	
	}
    }

    /*
     * 爬取bing search数据（bingAds）
     */
    function crawlerBingData()
    {
        foreach ($this->keywords as $index => $keyInfo) {
            echo "bing关键字$index/{$keyInfo['keyword']}~~~~~~~~~~~~~~~~~~~~~~~~~" . PHP_EOL;

            //记录每个关键字在pc和mobile上搜出来的广告总数，如果广告总数为0，则为此关键字的排行设置为一个特殊的值none，表示此次搜索没有任何广告消息
            $adsPcTotal = 0;
            $adsMbTotal = 0;
            //google一个关键字的前三页都没有广告，则再重复一次，最多重复三次， times记录重复的次数, 且：重复爬取的时候不需要在分析pla（人工search结果中也存在很多查不到的情况，所以暂时不考虑）
            $times = 0;
            for($times; $times < 3; $times ++){
                if($adsPcTotal == 0){
                    $this->crawlerBingPcData($keyInfo, $index, $adsPcTotal, $times);
                }
                if($adsMbTotal == 0){
                    $this->crawlerBingMbData($keyInfo, $index, $adsMbTotal, $times);
                }

                if($adsPcTotal > 0 && $adsMbTotal > 0){
                    break;
                }
            }

            if($adsPcTotal == 0){
                $this->bing_PC_Data[$index]['weight'] = 'N';
            }

            if($adsMbTotal == 0){
                $this->bing_MB_Data[$index]['weight'] = 'N';
            }
        }
    }

    /*
     * 将每小时爬取的数据结果记入数据库，以便一天报表数据汇总以及历史数据的保存
     */
    function recordHourData()
    {
        $insert_sql = "insert into azazie.ads_report_record(hour,weight,type,keyword,create_time) values ";
        $value_sql = "";
        if($this->crawlerType == "google"){
            foreach ($this->searchAds_PC_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
            foreach ($this->searchAds_MB_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
            foreach ($this->pla_PC_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
            foreach ($this->pla_MB_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
        }elseif($this->crawlerType == 'bing'){
            foreach ($this->bing_PC_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
            foreach ($this->bing_MB_Data as $index => $data) {
                $value_sql .= "({$data['hour']}, '{$data['weight']}', '{$data['type']}', {$data['keyword_id']}, '{$data['create_time']}'),";
            }
        }

        if (empty($value_sql)) {
            echo "没有查询结果！" . PHP_EOL;
            exit;
        }

        $value_sql = substr($value_sql, 0, -1);
        $insert_sql .= $value_sql;
        $this->dbhw->ping();
        $res = $this->dbhw->query($insert_sql);
        if(!$res){
            print_r($this->dbhw->error());
        }else{
            print_r($insert_sql);
            echo "数据插入成功~~~";
        }
    }

    /*
     * curl通用函数
     */
    function getCurlData($searchUrl, $optArray)
    {
        echo "{$searchUrl}" . PHP_EOL;
        if($this->googleCount % 51 == 50){
            $this->sleepCrawler(2700);
        }
        $session = curl_init($searchUrl);

        //curl基础配置
        curl_setopt($session, CURLOPT_URL, $searchUrl);
        curl_setopt($session, CURLOPT_HEADER, 0); //是否将头文件的信息作为数据流输出
        curl_setopt($session, CURLOPT_RETURNTRANSFER, 1); // 网页内容不直接输出
        curl_setopt($session, CURLOPT_MAXREDIRS, 20); //最大重定向次数
        curl_setopt($session, CURLOPT_FOLLOWLOCATION, 1); // 解决首页重定向问题
        curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 15); //连接超时
        curl_setopt($session, CURLOPT_TIMEOUT, 180); // 设置超时限制防止死循环

        //可变的设置通过参数来控制如是否使用代理／userAgent的设置
        curl_setopt_array($session, $optArray);

        //https的处理
        $url_info = parse_url($searchUrl);
        if ($url_info['scheme'] == "https") {
            curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false); //这个是重点,规避ssl的证书检查。
            curl_setopt($session, CURLOPT_SSL_VERIFYHOST, false); // 跳过host验证
        }


        $pageContent = curl_exec($session);
        $response = curl_getinfo($session);
        return array($response, $pageContent);
    }

    /*
     * 初始化关键词
     */
    function initKeyword()
    {
        $sql = "select id, keyword
                from azazie.keyword_set
                where is_delete = 0";
        $res = $this->dbhw->getAll($sql);
        $this->keywords = $res;
        $this->keywords || $this->keywords = array();
    }

    function testSpecialKeyword(){
        $this->keywords = array();
        $this->keywords[] = array("id" => 96, "keyword" => "bridesmaid dress");
        $this->keywords[] = array("id" => 97, "keyword" => "david's bridal bridesmaid dresses");
        $this->keywords[] = array("id" => 98, "keyword" => "purple bridesmaid dresses");
        $this->keywords[] = array("id" => 99, "keyword" => "red bridesmaid dresses");
        $this->keywords[] = array("id" => 100, "keyword" => "bridesmaid dresses online");
        $this->keywords[] = array("id" => 101, "keyword" => "tiffany blue bridesmaid dresses");
    }


    function crawlerGooglePcData($keyInfo, $index, &$adsPcTotal,&$plaPcTotal, $times){
        //searchAds只找到第一次出现azazie广告的排名位置，一旦找到一次，就不再爬取其他页，但是pla需要爬满3页以算出现的总次数
        $pcSearchAdsFound = false;
        $adsFound = $adsPcTotal > 0 ? true : false;
        $plaFound = $plaPcTotal > 0 ? true : false;
        $keyword = urlencode($keyInfo['keyword']);
        foreach ($this->urlTemplate['searchAds'] as $urlIndex => $urlMap) {
            $searchUrl = $urlMap['preUrl'] . $keyword . $urlMap['suffixUrl'];
            //pc和mb各爬取一次

            $pcRes = $this->getCurlData($searchUrl, $this->optArrayPC);$this->googleCount ++;

            list($pcResponse, $pcContent) = $pcRes;

            $fileDate = date('Y-m-d');
            $fileDir = "/var/log/cronjob/crawler/{$fileDate}/{$this->hour}/";
            if(!is_dir($fileDir)){
                mkdir($fileDir, 0777, true);
                chmod($fileDir, 0777);
            }
            file_put_contents("{$fileDir}key_{$index}_google_pc_times_{$times}_{$urlIndex}.log", $pcContent);


            //以下是通过分析获取当前hour对应的searchAds和pla的pc权重
            if ($pcResponse['http_code'] == 200) {
                $psHtml = str_get_html($pcContent);
                if(!empty($psHtml)){
                    //searchAds如果已经azazie广告，则不再对content进行分析
                    if (!$pcSearchAdsFound && !$adsFound) {
                        foreach ($psHtml->find('.ads-visurl cite') as $candidateIndex => $candidateElement) {
                            $text = $candidateElement->innertext;
                            $adsPcTotal++;
                            echo $text . PHP_EOL;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->searchAds_PC_Data[$index]['weight'] = $candidateIndex + 1;
                                $pcSearchAdsFound = true;
                                break;
                            }
                        }
                    }

                    //pla数据
                    if(!$plaFound){
                        foreach ($psHtml->find('._Aad') as $candidateElement) {
                            $text = $candidateElement->innertext;
                            $plaPcTotal++;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->pla_PC_Data[$index]['weight']++;
                            }
                        }

                        foreach ($psHtml->find('.pla-unit .pla-unit-container ._Z5 ._mC .rhsl4') as $candidateElement) {
                            $text = $candidateElement->innertext;
                            $plaPcTotal++;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->pla_PC_Data[$index]['weight']++;
                            }
                        }

                        foreach ($psHtml->find('._jym') as $candidateElement) {
                            $text = $candidateElement->innertext;
                            $plaPcTotal++;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->pla_PC_Data[$index]['weight']++;
                            }
                        }
                    }
                }
            } elseif ($pcResponse['http_code'] == 503) {
                echo "错误google : ip被禁!!!!在pc端爬取{$keyInfo['keyword']}第{$times}次，第{$urlIndex}页！！！" . PHP_EOL;
                $this->searchAds_PC_Data[$index]['weight'] = 'NA';
                $this->pla_PC_Data[$index]['weight'] = 'NA';
            } else {
                echo "错误google : 在pc端爬取{$keyInfo['keyword']}第{$times}次，第{$urlIndex}页失败，http_code为{$pcResponse['http_code']}！！！" . PHP_EOL;
            }
        }
    }

    function crawlerGoogleMbData($keyInfo, $index, &$adsMbTotal, &$plaMbTotal, $times){
        //searchAds只找到第一次出现azazie广告的排名位置，一旦找到一次，就不再爬取其他页，但是pla需要爬满3页以算出现的总次数
        $mbSearchAdsFound = false;
        $adsFound = $adsMbTotal > 0 ? true : false;
        $plaFound = $plaMbTotal > 0 ? true : false;
        $keyword = urlencode($keyInfo['keyword']);
        foreach ($this->urlTemplate['searchAds'] as $urlIndex => $urlMap) {
            $searchUrl = $urlMap['preUrl'] . $keyword . $urlMap['suffixUrl'];
            //pc和mb各爬取一次
            $mbRes = $this->getCurlData($searchUrl, $this->optArrayMB);$this->googleCount ++;

            list($mbResponse, $mbContent) = $mbRes;

            $fileDate = date('Y-m-d');
            $fileDir = "/var/log/cronjob/crawler/{$fileDate}/{$this->hour}/";
            if(!is_dir($fileDir)){
                mkdir($fileDir, 0777, true);
                chmod($fileDir, 0777);
            }
            file_put_contents("{$fileDir}key_{$index}_google_mb_times_{$times}_{$urlIndex}.log", $mbContent);

            //以下是通过分析获取当前hour对应的searchAds和pla的mb权重
            if ($mbResponse['http_code'] == 200) {
                $mbHtml = str_get_html($mbContent);

                if(!empty($mbHtml)){
                    //searchAds如果已经azazie广告，则不再对content进行分析
                    if (!$mbSearchAdsFound && !$adsFound) {
                        foreach ($mbHtml->find('.ads-visurl cite') as $candidateIndex => $candidateElement) {
                            $text = $candidateElement->innertext;
                            $adsMbTotal++;
                            echo $text . PHP_EOL;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->searchAds_MB_Data[$index]['weight'] = $candidateIndex + 1;
                                $mbSearchAdsFound = true;
                                break;
                            }
                        }
                    }

                    //pla数据
                    if(!$plaFound){
                        foreach ($mbHtml->find('._FLg') as $candidateElement) {
                            $text = $candidateElement->innertext;
                            $plaMbTotal++;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->pla_MB_Data[$index]['weight']++;
                            }
                        }

                        foreach ($mbHtml->find('._YDe ._KBh .Jyk') as $candidateElement) {
                            $text = $candidateElement->innertext;
                            $plaMbTotal++;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->pla_MB_Data[$index]['weight']++;
                            }
                        }
                    }
                }
            } elseif ($mbResponse['http_code'] == 503) {
                echo "错误google : ip被禁!!!!在mb端爬取{$keyInfo['keyword']}第{$times}次，第{$urlIndex}页！！！" . PHP_EOL;
                $this->searchAds_MB_Data[$index]['weight'] = 'NA';
                $this->pla_MB_Data[$index]['weight'] = 'NA';
            } else {
                echo "错误google : 在mb端爬取{$keyInfo['keyword']}第{$times}次，第{$urlIndex}页失败，http_code为{$mbResponse['http_code']}！！！" . PHP_EOL;
            }
        }
    }


    function crawlerBingPcData($keyInfo, $index, &$adsPcTotal, $times){
        $pcBingAdsFound = false;
        $keyword = urlencode($keyInfo['keyword']);
        foreach ($this->urlTemplate['bingAds'] as $urlIndex => $urlMap) {
            $searchUrl = $urlMap['preUrl'] . $keyword . $urlMap['suffixUrl'];
            //pc和mb各爬取一次
            $pcRes = $this->getCurlData($searchUrl, $this->optArrayPC);

            $fileDate = date('Y-m-d');
            $fileDir = "/var/log/cronjob/crawler/{$fileDate}/{$this->hour}/";
            if(!is_dir($fileDir)){
                mkdir($fileDir, 0777, true);
                chmod($fileDir, 0777);
            }

            list($pcResponse, $pcContent) = $pcRes;
            file_put_contents("{$fileDir}key_{$index}_bing_pc_times_{$times}_{$urlIndex}.log", $pcContent);

            //以下是通过分析获取当前hour对应的bingAds和pla的pc权重
            if ($pcResponse['http_code'] == 200) {
                $psHtml = str_get_html($pcContent);
                if(!empty($psHtml)){
                    //searchAds如果已经azazie广告，则不再对content进行分析
                    if (!$pcBingAdsFound) {
                        foreach ($psHtml->find('#b_results .b_ad:not(".b_adBottom") .sb_adTA .b_caption .b_attribution cite') as $candidateIndex => $candidateElement) {
                            $text = $candidateElement->innertext;
                            $adsPcTotal++;
                            echo $text . PHP_EOL;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->bing_PC_Data[$index]['weight'] = $candidateIndex + 1;
                                $pcBingAdsFound = true;
                                break 2;
                            }
                        }

                        if(!$pcBingAdsFound){
                            foreach ($psHtml->find('#b_context .b_ad .sb_adTA .b_caption .b_attribution cite') as $candidateIndex => $candidateElement) {
                                $text = $candidateElement->innertext;
                                $adsPcTotal++;
                                echo $text . PHP_EOL;
                                if (!empty($text) && stripos($text, 'azazie') !== false) {
                                    //这里candidateIndex是否能代表出现的位置
                                    $this->bing_PC_Data[$index]['weight'] = $candidateIndex + 1;
                                    $pcBingAdsFound = true;
                                    break 2;
                                }
                            }
                        }

                        if(!$pcBingAdsFound){
                            foreach ($psHtml->find('#b_results .b_adBottom .sb_adTA .b_caption .b_attribution cite') as $candidateIndex => $candidateElement) {
                                $text = $candidateElement->innertext;
                                $adsPcTotal++;
                                echo $text . PHP_EOL;
                                if (!empty($text) && stripos($text, 'azazie') !== false) {
                                    //这里candidateIndex是否能代表出现的位置
                                    $this->bing_PC_Data[$index]['weight'] = $candidateIndex + 1;
                                    $pcBingAdsFound = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            } elseif ($pcResponse['http_code'] == 503) {
                $this->bing_PC_Data[$index]['weight'] = 'NA';
            } else {
                echo "错误bing : 在pc端爬取{$keyInfo['keyword']}第{$urlIndex}页失败，http_code为{$pcResponse['http_code']}！！！" . PHP_EOL;
            }
        }
    }

    function crawlerBingMbData($keyInfo, $index, &$adsMbTotal, $times){
        $mbBingAdsFound = false;
        $keyword = urlencode($keyInfo['keyword']);
        foreach ($this->urlTemplate['bingAds'] as $urlIndex => $urlMap) {
            $searchUrl = $urlMap['preUrl'] . $keyword . $urlMap['suffixUrl'];
            //pc和mb各爬取一次
            $mbRes = $this->getCurlData($searchUrl, $this->optArrayMB);

            $fileDate = date('Y-m-d');
            $fileDir = "/var/log/cronjob/crawler/{$fileDate}/{$this->hour}/";
            if(!is_dir($fileDir)){
                mkdir($fileDir, 0777, true);
                chmod($fileDir, 0777);
            }

            list($mbResponse, $mbContent) = $mbRes;
            file_put_contents("{$fileDir}key_{$index}_bing_mb_times_{$times}_{$urlIndex}.log", $mbContent);


            //以下是通过分析获取当前hour对应的bingAds和pla的mb权重
            if ($mbResponse['http_code'] == 200) {
                $mbHtml = str_get_html($mbContent);
                if(!empty($mbHtml)){
                    //searchAds如果已经azazie广告，则不再对content进行分析
                    if (!$mbBingAdsFound) {
                        foreach ($mbHtml->find('.ad_sc .b_attribution cite') as $candidateIndex => $candidateElement) {
                            $text = $candidateElement->innertext;
                            $adsMbTotal++;
                            echo $text . PHP_EOL;
                            if (!empty($text) && stripos($text, 'azazie') !== false) {
                                //这里candidateIndex是否能代表出现的位置
                                $this->bing_MB_Data[$index]['weight'] = $candidateIndex + 1;
                                $mbBingAdsFound = true;
                                break 2;
                            }
                        }
                    }
                }
            } elseif ($mbResponse['http_code'] == 503) {
                $this->bing_MB_Data[$index]['weight'] = 'NA';
            } else {
                echo "错误bing : 在mb端爬取{$keyInfo['keyword']}第{$urlIndex}页失败，http_code为{$mbResponse['http_code']}！！！" . PHP_EOL;
            }


        }
    }

    /*
     * 防止ip被禁，使用sleep休眠程序
     */
    function sleepCrawler($sec){
        if(empty($sec)){
            $sec = mt_rand(50,60);
        }
        sleep($sec);
    }
}

$crawler = new Cron_AdsCrawler_Controller($argv);
$crawler->indexAction();
?>
