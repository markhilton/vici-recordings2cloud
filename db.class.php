<?php

/*
 *	Abstraction Layer database driver: PDO extension
 *
 *  (C) Copyright 2001-2012
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 * 09/26/2013 - changed Update method to allow updates without WHERE clouse
 * 07/16/2013 - added cli mode auto recognision
 * 11/14/2012 - added optional empty first array key & value on GetAssoc
 * 11/05/2012 - added optional empty first array key & value on GetEnum
 * 09/30/2012 - added query parser for aliases for input arguments as well
 * 09/15/2012 - removed query log to file and added array log instead
 * 09/08/2012 - added aliases array to convert field and table names, removed table and col prefix
 * 10/17/2011 - changed SQL_DEBUG_IP handling to public $debug_ips
 * 08/20/2012 - added Insert & Update methods
 * 06/19/2011 - added debug to file
 * 01/23/2011 - debug output is determined auto for CLI or WEB request
 * 01/19/2011 - debug output added timestamps to track SQL execution time
 * 05/26/2010 - changed SQL_DEBUG_IP handling
 * 07/02/2006 - changed ?NOW? command from UNIX_TIMESTAMP to NOW() / MK
 * 13/03/2006 - __ added as prefix to column name / MK
 *
 */

class db extends db_common
{
	public $debug    	   = false;	// output queries
	public $debug_ips  	   = array();	// IP addresses allowed to see debug messages
	public $climode 	   = false;	// force console output
	public $_init          = null;
	public $_error   	   = false;
    public $_error_message = '';
	public $_loaded  	   = true;
	public $_timestamp 	   = false;



	function __construct($dbhost, $dbuser, $dbpasswd, $dbname, $dbport=null, $dbsocket=null)
	{
		if(php_sapi_name() === 'cli') $this->climode = true;

		if($dbhost !== false):
		    $this->Connect($dbhost, $dbuser, $dbpasswd, $dbname, $dbport, $dbsocket);
        else:
			$this->_error 	= true;
			$this->_init    = false;
			$this->errors[] = array( 'query'=>'', 'error'=>'Empty host' );
        endif;

	}

	function _driver_connect($dbhost, $dbuser, $dbpasswd, $dbname, $dbport, $dbsocket)
	{
        try {
            $host   = strlen( (string) $dbhost  ) > 1 ? sprintf('host=%s;'       , $dbhost  ) : '';
            $port   = strlen( (string) $dbport  ) > 1 ? sprintf('port=%s;'       , $dbport  ) : '';
            $socket = strlen( (string) $dbsocket) > 1 ? sprintf('unix_socket=%s;', $dbsocket) : '';
            $dsn    = sprintf('mysql:%s%s%sdbname=%s;charset=utf8', $host, $port, $socket, $dbname);

#            syslog(LOG_DEBUG, 'PDO: ' . $dsn);

            $this->_dblink = @new PDO($dsn, $dbuser, $dbpasswd);

            $this->_dblink->setAttribute( PDO::ATTR_TIMEOUT, 1 );
            $this->_dblink->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

        } catch (PDOException $e) {
			$this->_init          = false;
			$this->_error 	      = true;
			$this->_error_message = $e->getMessage();
			$this->errors[]       = array( 'query'=>$dsn, 'error'=>$e->getMessage() );
        }


        if($this->_error === false) $this->_init = true;


		$this->_dbhost 	 = $dbhost;
		$this->_dbuser 	 = $dbuser;
		$this->_dbname 	 = $dbname;
		$this->_dbport 	 = $dbport;
		$this->_dbsocket = $dbsocket;

		return $this->_dblink;
	}

	function _driver_geterror()
	{
		return $this->_dblink->errorInfo();
	}

	function _driver_disconnect()
	{
		unset($this->_dblink);
	}

	function _driver_execute($query, $data = array(), $debug = false)
	{
		$this->_timestamp   = microtime(true);

        if (isset($this->_dblink)):
            $this->_query   = $this->_dblink->prepare($query);

            try {
                $this->_query->execute( (array) $data);
                $this->_error         = false;
            } catch (PDOException $e) {
    			$this->_error         = true;
    			$this->_error_message = $e->getMessage();
    			$this->errors[]       = array( 'query'=>$this->_query->queryString, 'error'=>$e->getMessage() );
            }

        else:
			$this->_error         = true;
			$this->_error_message = 'Cannot connect to the database server';
			$this->errors[]       = array( 'query'=>'', 'error'=>'Cannot connect to the database server' );
        endif;

		// debug output
		if($this->debug || $debug)
		{
			// output on the screen
			$output = sprintf('%s: <b style="color: %s</b> [%s sec] %s', $query, $this->_error ? '#CC0000;">ERROR!' : '#009900;">OK!', number_format(microtime(true)-$this->_timestamp, 2), "\n");
			$output = $this->climode === false ? str_replace("\n", "<br />", $output) : strip_tags($output);

			echo $output;
		}

		return $this->_error;
	}

	function _driver_fetchrow_num()
	{
        $this->_query->setFetchMode(PDO::FETCH_NUM);
	}

	function _driver_fetchrow_assoc()
	{
        $this->_query->setFetchMode(PDO::FETCH_ASSOC);
	}

	function _driver_affected_rows()
	{
		return $this->_query->rowCount();
	}

	function _get_last_insert_id()
	{
		return $this->_dblink->lastInsertId();
	}
}





# --------------------------------------------
# Database common driver class
# build 01/29/2011
# --------------------------------------------

class db_common
{
	public $_error  	= false;
	public $_loaded 	= false;
	public $_dblink 	= NULL;
	public $_dbhost 	= NULL;
	public $_dbuser 	= NULL;
	public $_dbname 	= NULL;
	public $_dbport 	= NULL;
	public $_dbsocket	= NULL;
	public $_query  	= NULL;
	public $_result 	= NULL;
	public $log         = array(); // query log
	public $errors  	= array(); // error log
	public $aliases  	= array(); // table and field aliases



	function __destruct()
	{
		return $this->_driver_disconnect();
	}

	function Connect($dbhost, $dbuser, $dbpasswd, $dbname, $dbport = null, $dbsocket = null)
	{
        if($this->_init === false) return false;

		return $this->_driver_connect($dbhost, $dbuser, $dbpasswd, $dbname, $dbport, $dbsocket);
	}

	function Close()
	{
		return $this->_driver_disconnect();
	}

	function Execute($query, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		$result = $this->_driver_execute($this->_query_parser($query), $data, $debug);

		$this->log[] = array(
			'query'    		=> $this->_query->queryString,
			'data'    		=> print_r($data, true),
			'result'   		=> $this->_error ? 'ERROR' : 'OK',
			'time'     		=> number_format(microtime(true) - $this->_timestamp, 2),
			'error'    		=> $this->_error_message,
			'affected_rows' => (int) $this->_driver_affected_rows()
		);

		if($debug && !$result)
		{
			$output = sprintf("<pre>QUERY: %s\nERROR: %s\n</pre>", $this->_query->queryString, $this->_error_message);
			$output = $this->climode === true ? strip_tags($output) : $output;

			echo $output;
		}

		return $result;
	}

	function Insert($table, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		return $this->InsertType('INSERT', $table, $data, $debug);
	}

	function Replace($table, $data = NULL, $debug = false)
	{
		return $this->InsertType('REPLACE', $table, $data, $debug);
	}

	function InsertType($function, $table, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		if($data !== NULL && !is_array($data)) return false;

        foreach ($data as $key => $val)
        {
            $keys[ preg_replace('/[^A-Za-z0-9-]/', '', $key) ] = $val;
        }

		$query = sprintf("%s INTO `%s` (`%s`) \nVALUES (%s)", $function, $table,
		         join("`, `", array_keys($data)), ':' . join(", :", array_keys($keys)));

		return $this->Execute($query, $keys, $debug);
	}

	function Update($table, $data = NULL, $wherearray = NULL, $debug = false, $limit = "LIMIT 1")
	{
        if($this->_init === false) return false;

		if($data !== NULL && !is_array($data)) return false;
		if($wherearray !== NULL && !is_array($wherearray)) return false;

		if(is_array($wherearray)):
			foreach($wherearray as $key=>$val)
				if(in_array($key, array_keys($data))) unset($data[$key]);

            $where = '';
            foreach($wherearray as $k=>$v)
    		    $where .= sprintf('%s=:%s, ', $k, $k);

		endif;

        $set = '';
        foreach($data as $k=>$v)
		    $set .= sprintf('%s=:%s, ', $k, $k);


		$query      = sprintf("UPDATE %s SET %s %s", $table, substr($set, 0, -2), $where ? "WHERE " . substr($where, 0, -2) : "");

		if($wherearray)
		$data = array_merge($data, $wherearray);

		return $this->Execute($query . " " . $limit, $data, $debug);
	}

	function GetAll($query = NULL, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		$result = array();
	    $this->Execute($query, $data, $debug);
        $this->_driver_fetchrow_assoc();

		while($row = $this->_query->fetch())
			$result[] = $row;

		return $result;
	}

	function GetAllByKey($query = NULL, $key = NULL, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		$result = array();
	    $this->Execute($query, $data, $debug);
        $this->_driver_fetchrow_assoc();

		while($row = $this->_query->fetch())
			$result[ $row[$key] ] = $row;

		return $result;
	}

	function GetRow($query = NULL, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

	    $this->Execute($query, $data, $debug);
        $this->_driver_fetchrow_assoc();

		return $this->_query->fetch();
	}

	function GetCol($query = NULL, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		$result = array();

	    $this->Execute($query, $data, $debug);
        $this->_driver_fetchrow_num();

		while($row = $this->_query->fetch())
			$result[] = $row[0];

		return $result;
	}

	function GetAssoc($query = NULL, $data = NULL, $empty = false, $debug = false)
	{
        if($this->_init === false) return false;

		$result = array();
	    $this->Execute($query, $data, $debug);
        $this->_driver_fetchrow_assoc();

        $row = $this->_query->fetch();

		if(is_array($row))
		{
			list($key, $col)      = array_keys($row);
			$result[ $row[$key] ] = $row[$col];

			while($row = $this->_query->fetch())
				$result[ $row[$key] ] = $row[$col];

			if($empty !== false)
				$result = array('' => $empty) + $result;
		}

		return $result;
	}

	function GetColByKey($query = NULL, $key = NULL, $col = NULL, $data = NULL) // Depreciated - use GetAssoc instead
	{
        if($this->_init === false) return false;

		$this->Execute($query, $data);
        $this->_driver_fetchrow_assoc();

		$result = array();

        while($row = $this->_query->fetch())
			$result[ $row[$key] ] = $row[$col];

		return $result;
	}

	function GetOne($query = NULL, $data = NULL, $debug = false)
	{
        if($this->_init === false) return false;

		$this->Execute($query, $data, $debug);

		$result = array();

		list($result) = $this->_query->fetch();

		return $result;
	}

	function GetEnum($table = NULL, $col = NULL, $empty = false, $debug = false)
	{
        if($this->_init === false) return false;

		if(!$table || !$col) return false;

		$result = $this->GetRow("SHOW COLUMNS FROM $table LIKE ?", array($col), $debug);
		$result = explode("','", preg_replace("/(enum|set)\('(.+?)'\)/","\\2", $result['Type']));

		if($empty !== false)
			$result = array('' => $empty) + $result;

		return $result;
	}

	function GetInsertID()
	{
        if($this->_init === false) return false;

		return $this->_get_last_insert_id();
	}

	function GetAffectedRows()
	{
        if($this->_init === false) return false;

		return $this->_driver_affected_rows();
	}

	function GetQuery($query, $data = NULL)
	{
        if($this->_init === false) return false;

		return $this->_query_parser($query, $data);
	}

	function _query_parser($query, $data = NULL)
	{
        if($this->_init === false) return false;

		if(is_array($this->aliases) && is_array(array_keys($this->aliases)) && is_array(array_values($this->aliases)))
			$query = str_replace(array_keys($this->aliases), array_values($this->aliases), $query);

		return $query;
	}

}
