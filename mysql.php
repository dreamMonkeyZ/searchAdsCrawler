<?php
class Mysql
{
    private $conn;
    public function __construct($host, $user, $password)
    {
        $this->conn = mysql_connect($host, $user, $password);
        mysql_select_db( 'azazie' );
    }

    public function __destruct()
    {
        mysql_close($this->conn);
    }

    public function query($sql)
    {
        mysql_query($sql);
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
}

?>


