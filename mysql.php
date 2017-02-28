<?php
class Mysql
{
    private $conn;
    private $host;
    private $user;
    private $password;
    public function __construct($host, $user, $password)
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->connect();
    }

    public function connect(){
        $this->conn = mysql_connect($this->host, $this->user, $this->password);
        mysql_select_db( 'azazie' );
    }

    public function __destruct()
    {
        mysql_close($this->conn);
    }

    public function query($sql)
    {
        $res = mysql_query($sql, $this->conn);
        return $res;
    }

    function getAll($sql, $result_type = MYSQL_ASSOC)
    {
        $res = mysql_query($sql);
        if ($res)
        {
            $r = array();
            while ($fa = $this->fetchArray($res, $result_type))
            {
                $r[] = $fa;
            }
            return $r;
        }
        return false;
    }

    function fetchArray($res, $result_type = MYSQL_BOTH)
    {
        return mysql_fetch_array($res, $result_type);
    }

    public function error(){
        return mysql_error($this->conn);
    }

    public function ping(){
        if(!mysql_ping($this->conn)){
            mysql_close($this->conn); //注意：一定要先执行数据库关闭，这是关键
            $this->connect();
        }
    }
}

?>


