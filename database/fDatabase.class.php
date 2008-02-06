<?php
/**
 * Provides a common API for different databases - will automatically use any installed extension
 * 
 * The following databases are supported:
 *   - {@link http://microsoft.com/sql/ MSSQL}
 *   - {@link http://mysql.com MySQL}
 *   - {@link http://postgresql.org PostgreSQL}
 *   - {@link http://sqlite.org SQLite}
 * 
 * The class will automatically use the first of the following extensions it finds:
 *   - MSSQL
 *     - {@link http://php.net/mssql mssql} (or {@link http://php.net/sybase sybase})
 *   - MySQL
 *     - {@link http://php.net/mysql mysql}
 *     - {@link http://php.net/mysqli mysqli}
 *     - {@link http://php.net/pdo_mysql pdo_mysql}
 *   - PostgreSQL
 *     - {@link http://php.net/pgsql pgsql}
 *     - {@link http://php.net/pdo_pgsql pdo_pgsql}
 *   - SQLite
 *     - {@link http://php.net/pdo_sqlite pdo_sqlite} (for v3.x)
 *     - {@link http://php.net/sqlite sqlite} (for v2.x)
 * 
 * @copyright  Copyright (c) 2007-2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @link  http://flourishlib.com/fDatabase
 * 
 * @uses  fConnectivityException
 * @uses  fCore
 * @uses  fEnvironmentException
 * @uses  fProgrammerException
 * @uses  fResult
 * @uses  fSQLException
 * @uses  fSQLTranslation
 * 
 * @version  1.0.0
 * @changes  1.0.0    The initial implementation [wb, 2007-09-25]
 */
class fDatabase
{
	/**
	 * If debugging is enabled
	 * 
	 * @var boolean 
	 */
	private $debug;
	
	/**
	 * The total number of seconds spent executing queries
	 * 
	 * @var float 
	 */
	private $query_time;
	
	/**
	 * Database connection resource or PDO object 
	 * 
	 * @var mixed 
	 */
	private $connection;
	
	/**
	 * The database type (postgresql, mysql, sqlite)
	 * 
	 * @var string 
	 */
	private $type;

	/**
	 * The extension to use for the database specified
	 * 
	 * Options include:
	 *   - mssql, mysql, mysqli, pgsql, sqlite, pdo
	 * 
	 * @var string 
	 */
	private $extension;
	
	/**
	 * The fSQLTranslation object for this database
	 * 
	 * @var object 
	 */
	private $translation;
	
	/**
	 * The database name
	 * 
	 * @var string 
	 */
	private $database;
	
	/**
	 * The user to connect to the database as
	 * 
	 * @var string 
	 */
	private $username;
	
	/**
	 * The password for the user specified
	 * 
	 * @var string 
	 */
	private $password;
	
	/**
	 * The host the database server is located on
	 * 
	 * @var string 
	 */
	private $host;
	
	/**
	 * The port number for the host
	 * 
	 * @var string 
	 */
	private $port;
	

	/**
	 * Establishes a connection to the database.
	 * 
	 * @param  string  $type      The type of the database: 'mssql', 'mysql', 'postgresql', 'sqlite'
	 * @param  string  $database  Name (or file for sqlite) of database
	 * @param  string  $username  Database username
	 * @param  string  $password  User's password
	 * @param  string  $host      Database server host or ip
	 * @param  integer $port      The port to connect to (if non-standard)
	 * @return fDatabase
	 */
	public function __construct($type, $database, $username=NULL, $password=NULL, $host=NULL, $port=NULL)
	{
		if (!in_array($type, array('mssql', 'mysql', 'postgresql', 'sqlite'))) {
			fCore::toss('fProgrammerException', 'Invalid database type specified');       
		}
		
		$this->type = $type;			

		$this->determineExtension();
		
		$this->database = $database;
		$this->username = $username;
		$this->password = $password;
		$this->host     = $host;
		$this->port     = $port;
		
		$this->connectToDatabase();
	}
	
	
	/**
	 * Enabled debugging
	 * 
	 * @param  boolean $enable  If debugging should be enabled
	 * @return void
	 */
	public function setDebug($enable)
	{
		$this->debug = (boolean) $enable;
		if ($this->translation) {
			$this->translation->setDebug($this->debug);	
		}
	}
	
	
	/**
	 * Gets the database type
	 * 
	 * @return string  The database type (mssql, mysql, pgsql or sqlite)
	 */
	public function getType()
	{
		return $this->type;
	}
	
	
	/**
	 * Gets the php extension being used (mssql, mysql, mysqli, pgsql, sqlite, or pdo)
	 * 
	 * @return string  The php extension used for database interaction
	 */
	public function getExtension()
	{
		return $this->extension;
	}
	
	
	/**
	 * Gets the name of the database currently connected to
	 * 
	 * @return string  The name of the database currently connected to
	 */
	public function getDatabase()
	{
		return $this->database;
	}
	
	
	/**
	 * Translates the SQL statement using fSQLTranslation and executes it
	 * 
	 * @param  string  $sql  An SQL statement
	 * @return string  The translated SQL
	 */
	public function translatedQuery($sql)
	{
		if (!$this->translation) {
			$this->translation = new fSQLTranslation($this->connection, $this->type, $this->extension);
		}	
		return $this->query($this->translation->translate($sql));
	}
	
	
	/**
	 * Executes one or more sql queries
	 * 
	 * @param  string  $sql  One or more SQL statements
	 * @return fResult|array  The fResult object(s) for the query
	 */
	public function query($sql)
	{
		// Ensure an SQL statement was passed
		if (empty($sql)) {
			fCore::toss('fProgrammerException', 'No SQL statement passed');
		}
		
		// Split multiple queries
		if (strpos($sql, ';') !== FALSE) {
			$sql_queries = $this->explodeQueries($sql);
			$sql = array_shift($sql_queries);
		}
		
		$result = new fResult($this->extension);
		$result->setSQL($sql);
		
		$start_time = microtime(TRUE);
		$this->executeQuery($result);
		
		// Write some debugging info
		$query_time = microtime(TRUE) - $start_time;
		$this->query_time += $query_time;
		fCore::debug('Query time was ' . $query_time . " seconds for:\n" . $result->getSql(), $this->debug);          
		
		// If we get a resource or TRUE back then the query was successful
		if ($result->getResult() === FALSE) {
			fCore::toss('fSQLException', 'There was an error in the SQL statement ' . $result->getSql());	
		}
		
		$this->handleAutoIncrementedValue($result);
		
		// Handle multiple SQL queries
		if (!empty($sql_queries)) {
			$result = array($result);
			foreach ($sql_queries as $sql_query) {
				$result[] = $this->query($sql_query);
			}	
		}
		
		return $result;
	}
	
	
	/**
	 * Escapes strings to prevent SQL injection attacks, includes surrounding quotes in return value
	 * 
	 * @param  string $value  The value to escape 
	 * @return string  The escaped string
	 */
	public function escapeString($value)
	{
		if ($this->extension == 'mysql') {
			return "'" . mysql_real_escape_string($value, $this->connection) . "'";
		} elseif ($this->extension == 'mysqli') {
			return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
		} elseif ($this->extension == 'pgsql') {
			return "'" . pg_escape_string($value) . "'";
		} elseif ($this->extension == 'sqlite') {
			return "'" . sqlite_escape_string($value) . "'";	
		} elseif ($this->extension == 'pdo') {
			return $this->connection->quote($value);	
		} elseif ($this->extension == 'mssql') {
			return "'" . str_replace("'", "''", $value) . "'";	
		}
	}
	
	
	/**
	 * Unescapes strings coming out of the database, included for completeness
	 * 
	 * @param  string $value  The value to unescape 
	 * @return string  The unescaped string
	 */
	public function unescapeString($value)
	{
		return $value;
	}
	
	
	/**
	 * Escapes blobs to prevent breaking queries, includes surrounding quotes when appropriate
	 * 
	 * @param  string $value  The value to escape 
	 * @return string  The escaped blob
	 */
	public function escapeBlob($value)
	{
		if ($this->extension == 'mysql') {
			return "'" . mysql_real_escape_string($value, $this->connection) . "'";
		} elseif ($this->extension == 'mysqli') {
			return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
		} elseif ($this->extension == 'pgsql') {
			return "'" . pg_escape_bytea($this->connection, $value) . "'";
		} elseif ($this->extension == 'sqlite') {
			return "X'" . bin2hex($value) . "'";	
		} elseif ($this->extension == 'pdo') {
			return $this->connection->quote($value);	
		} elseif ($this->extension == 'mssql') {
			return '0x' . bin2hex($value);	
		}
	}
	
	
	/**
	 * Unescapes blobs coming out of the database
	 * 
	 * @param  string $value  The value to unescape 
	 * @return binary  The binary data from the blob
	 */
	public function unescapeBlob($value)
	{
		if ($this->extension == 'pgsql') {
			return pg_unescape_bytea($this->connection, $value);
		} else  {
			return $value;	
		}
	}
	

	/**
	 * Translates a boolean to a value the database will understand
	 * 
	 * @param  string $value  The boolean to escape
	 * @return string  The database equivalent of the boolean passed
	 */
	public function escapeBoolean($value)
	{
		if (in_array($this->type, array('postgresql', 'mysql'))) {
			return ($value) ? 'TRUE' : 'FALSE';	
		} elseif (in_array($this->type, array('mssql', 'sqlite'))) {
			return ($value) ? "'1'" : "'0'";	
		}
	}
	
	
	/**
	 * Interprets a boolean coming out of the database
	 * 
	 * @param  string $value  The value to interpret 
	 * @return boolean  The boolean equivalent of the value passed
	 */
	public function unescapeBoolean($value)
	{
		return ($value == 'f' || !$value) ? FALSE : TRUE;
	}
	
	
	/**
	 * Escapes a timestamp for insertion into the database
	 * 
	 * @param  string $value  The value to escape 
	 * @return string  The escaped timestamp
	 */
	public function escapeTimestamp($value)
	{
		return "'" . date('Y-m-d H:i:s', strtotime($value)) . "'";
	}
	
	
	/**
	 * Unescapes timestamps coming out of the database
	 * 
	 * @param  string $value  The value to unescape 
	 * @return string  The timestamp
	 */
	public function unescapeTimestamp($value)
	{
		return date('Y-m-d H:i:s', strtotime($value));
	}
	
	
	/**
	 * Escapes a date for insertion into the database
	 * 
	 * @param  string $value  The value to escape 
	 * @return string  The escaped date
	 */
	public function escapeDate($value)
	{
		return "'" . date('Y-m-d', strtotime($value)) . "'";
	}
	
	
	/**
	 * Unescapes dates coming out of the database
	 * 
	 * @param  string $value  The value to unescape 
	 * @return string  The date
	 */
	public function unescapeDate($value)
	{
		return date('Y-m-d', strtotime($value));
	}
	
	
	/**
	 * Escapes a time for insertion into the database
	 * 
	 * @param  string $value  The value to escape 
	 * @return string  The escaped time
	 */
	public function escapeTime($value)
	{
		return "'" . date('H:i:s', strtotime($value)) . "'";
	}
	
	
	/**
	 * Unescapes times coming out of the database
	 * 
	 * @param  string $value  The value to unescape 
	 * @return string  The time
	 */
	public function unescapeTime($value)
	{
		return date('H:i:s', strtotime($value));
	}
		  
	
	/**
	 * Figures out which extension to use for the database type selected
	 * 
	 * @return void
	 */
	private function determineExtension()
	{
		switch ($this->type) {
			case 'mssql':
				if (extension_loaded('mssql')) {
					$this->extension = 'mssql';	
				} else {
					fCore::toss('fEnvironmentException', 'The server does not have any of the following extensions for MSSQL support: mssql');	
				}
				break;
			
			case 'mysql':
				if (extension_loaded('mysql')) {
					$this->extension = 'mysql';	
				} elseif (extension_loaded('mysqli')) {
					$this->extension = 'mysqli';	
				} elseif (class_exists('PDO', FALSE) && in_array('mysql', PDO::getAvailableDrivers())) {
					$this->extension = 'pdo';	
				} else {
					fCore::toss('fEnvironmentException', 'The server does not have any of the following extensions for MySQL support: mysql, mysqli, pdo_mysql');	
				}
				break;
				
			case 'postgresql':
				if (extension_loaded('pgsql')) {
					$this->extension = 'pgsql';	
				} elseif (class_exists('PDO', FALSE) && in_array('pgsql', PDO::getAvailableDrivers())) {
					$this->extension = 'pdo';	
				} else {
					fCore::toss('fEnvironmentException', 'The server does not have any of the following extensions for PostgreSQL support: pgsql, pdo_pgsql');	
				}  
				break; 
				
			case 'sqlite':
				$sqlite_version = 0;
				if (file_exists($this->database)) {
					$database_handle  = fopen($this->database, 'r');
					$database_version = fread($database_handle, 64);	
					fclose($database_handle);
					if (strpos($database_version, 'SQLite format 3') !== FALSE) {
						$sqlite_version = 3;
					} elseif (strpos($database_version, '** This file contains an SQLite 2.1 database **') !== FALSE) {
						$sqlite_version = 2;
					} else {
						fCore::toss('fConnectivityException', 'The database specified does not appear to be a valid SQLite v2.1 or v3 database');
					}		
				}
				if ((!$sqlite_version || $sqlite_version == 3) && class_exists('PDO', FALSE) && in_array('sqlite', PDO::getAvailableDrivers())) {
					$this->extension = 'pdo';	
				} elseif ($sqlite_version == 3 && (!class_exists('PDO', FALSE) || in_array('sqlite', PDO::getAvailableDrivers()))) {
					fCore::toss('fEnvironmentException', 'The database specified is an SQLite v3 database and the pdo_sqlite extension is not installed');
				} elseif ((!$sqlite_version || $sqlite_version == 2) && extension_loaded('sqlite')) {
					$this->extension = 'sqlite';	
				} elseif ($sqlite_version == 2 && !extension_loaded('sqlite')) {
					fCore::toss('fEnvironmentException', 'The database specified is an SQLite v2.1 database and the sqlite extension is not installed');
				} else {
					fCore::toss('fEnvironmentException', 'The server does not have any of the following extensions for SQLite support: pdo_sqlite, sqlite');	
				}
				break; 
		}	
	}
	
	
	/**
	 * Actually connects to the database specified
	 * 
	 * @return void
	 */
	private function connectToDatabase()
	{
		// Establish a connection to the database
		if ($this->extension == 'pdo') {
			if ($this->type == 'mysql') {
				try {
					$this->connection = new PDO('mysql:host=' . $this->host . ';dbname=' . $this->database . (($this->port) ? ';port=' . $this->port : ''), $this->user, $this->password);
				} catch (PDOException $e) {
					$this->connection = FALSE;	
				}
				
			} elseif ($this->type == 'postgresql') {
				try {
					$this->connection = new PDO('pgsql:host=' . $this->host . ' dbname=' . $this->database, $this->user, $this->password);
				} catch (PDOException $e) {
					$this->connection = FALSE;	
				}
				
			} elseif ($this->type == 'sqlite') {
				try {
					$this->connection = new PDO('sqlite:' . $this->database);
				} catch (PDOException $e) {
					$this->connection = FALSE;	
				}
			}		
		}
		
		if ($this->extension == 'sqlite') {
			$this->connection = @sqlite_open($this->database);
		}
		
		if ($this->extension == 'mssql') {
			$this->connection = @mssql_connect(($this->port) ? $this->host . ':' . $this->port : $this->host, $this->username, $this->password);
			if ($this->connection !== FALSE && @mssql_select_db($this->database, $this->connection) === FALSE) {
				$this->connection = FALSE;
			}
		}
		
		if ($this->extension == 'mysql') {
			$this->connection = @mysql_connect(($this->port) ? $this->host . ':' . $this->port : $this->host, $this->username, $this->password);
			if ($this->connection !== FALSE && @mysql_select_db($this->database, $this->connection) === FALSE) {
				$this->connection = FALSE;
			}
		}
			
		if ($this->extension == 'msyqli') {
			if ($this->port) {
				$this->connection = @mysqli_connect($this->host, $this->username, $this->password, $this->database, $this->port);
			} else {
				$this->connection = @mysqli_connect($this->host, $this->username, $this->password, $this->database);
			}
		}
			
		if ($this->extension == 'pgsql') {
			$this->connection = @pg_connect("host='" . addslashes($this->host) . "'
											dbname='" . addslashes($this->database) . "'
											user='" . addslashes($this->username) . "'
											password='" . addslashes($this->password) . "'" .
											(($this->port) ? " port='" . $this->port . "'" : ''));   
		}
		
		// Ensure the connection was established
		if ($this->connection === FALSE) {
			fCore::toss('fConnectivityException', 'Unable to connect to database');
		}
		
		// Make MySQL act more strict
		if ($this->type == 'mysql') {
			$this->query("set sql_mode = 'ANSI'");
		}	
		
		// Make SQLite behave like other DBs for assoc arrays
		if ($this->type == 'sqlite') {
			$this->query('PRAGMA short_column_names = 1');	
		}	
		
		// Fix some issues with mssql 
		if ($this->extension == 'mssql') {
			$this->query('SET TEXTSIZE 65536');		
		}
	}
	
	
	/**
	 * Takes in a string of SQL that contains multiple queries and returns any array of them
	 * 
	 * @param  string $sql  The string of SQL to parse for queries
	 * @return array  The individual SQL queries
	 */
	private function explodeQueries($sql)
	{
		$sql_queries = array();
		
		// Separate the SQL from quoted values
		preg_match_all("#(?:'(?:''|\\\\'|\\\\[^']|[^'\\\\])*')|(?:[^']+)#", $sql, $matches);
		
		$cur_sql = '';
		foreach ($matches[0] as $match) {
			
			// This is a quoted string value, don't do anything to it
			if ($match[0] == "'") {
				$cur_sql .= $match;	
			
			// Handle the SQL
			} else {
				$sql_strings = explode(';', $match);
				$cur_sql .= $sql_strings[0];
				for ($i=1; $i < sizeof($sql_strings); $i++) {
					// SQLite triggers have a ; before and after the "end"
					if (strtolower(trim($sql_strings[$i])) == 'end') {
						$cur_sql .= "; END";
						$i++;
						if ($i >= sizeof($sql_strings)) {
							break;
						}	
					}
					$sql_queries[] = $cur_sql;
					$cur_sql = $sql_strings[$i];
				}	
			}
		}	
		if ($cur_sql) {
			$sql_queries[] = $cur_sql; 
		}
		
		return $sql_queries;	
	}
	
	
	/**
	 * Executes an sql query
	 * 
	 * @param  fResult $result  The result object for the query
	 * @return void
	 */
	private function executeQuery(fResult $result)
	{
		if ($this->extension == 'mssql') {
			$result->setResult(@mssql_query($result->getSql(), $this->connection)); 
		} elseif ($this->extension == 'mysql') {
			$result->setResult(@mysql_query($result->getSql(), $this->connection)); 
		} elseif ($this->extension == 'mysqli') {
			$result->setResult(@mysqli_query($this->connection, $result->getSql()));   
		} elseif ($this->extension == 'pgsql') {
			$result->setResult(@pg_query($this->connection, $result->getSql()));   
		} elseif ($this->extension == 'sqlite') {
			$result->setResult(@sqlite_query($this->connection, $result->getSql(), SQLITE_ASSOC, $sqlite_error_message));	
		} elseif ($this->extension == 'pdo') {
			$pdo_statement = $this->connection->query($result->getSql());
			$result->setResult((is_object($pdo_statement)) ? $pdo_statement->fetchAll(PDO::FETCH_ASSOC) : $pdo_statement);	
		}
		
		$this->checkForError($result);
		if ($this->extension != 'pdo') {
			$this->setAffectedRows($result);
		} else {
			$this->setAffectedRows($result, $pdo_statement); 
		}
		$this->setReturnedRows($result);
	}
	
	
	/**
	 * Checks to see if an SQL error occured
	 * 
	 * @param  fResult $result  The result object for the query
	 * @return void
	 */
	private function checkForError(fResult $result)
	{
		if ($result->getResult() === FALSE) {	
			if ($this->extension == 'mssql') {
				$message = 'MSSQL error (' . mssql_get_last_message() . ') in ' . $result->getSql();
			} elseif ($this->extension == 'mysql') {
				$message = 'MySQL error (' . mysql_error($this->connection) . ') in ' . $result->getSql();
			} elseif ($this->extension == 'mysqli') {
				$message = 'MySQL error (' . mysqli_error($this->connection) . ') in ' . $result->getSql();   
			} elseif ($this->extension == 'pgsql') {
				$message = 'PostgreSQL error (' . pg_last_error($this->connection) . ') in ' . $result->getSql();   
			} elseif ($this->extension == 'sqlite') {
				$message = 'SQLite error (' . $sqlite_error_message . ') in ' . $result->getSql();   
			} elseif ($this->extension == 'pdo') {
				$error_info = $this->connection->errorInfo();
				switch ($this->type) {
					case 'mysql':
						$message = 'MySQL error (' . $error_info[2] . ') in ' . $result->getSql();   
						break;
					case 'postgresql':
						$message = 'PostgreSQL error (' . $error_info[2] . ') in ' . $result->getSql();   
						break;
					case 'sqlite':
						$message = 'SQLite error (' . $error_info[2] . ') in ' . $result->getSql();   
						break;	
				}
			}
			fCore::toss('fSQLException', $message);
		}	
	}
	
	
	/**
	 * Sets the number of rows returned by the query
	 * 
	 * @param  fResult $result  The result object for the query
	 * @return void
	 */
	private function setReturnedRows(fResult $result)
	{
		if (is_resource($result->getResult())) {
			if ($this->extension == 'mssql') {
				$result->setReturnedRows(@mssql_num_rows($result->getResult())); 
			} elseif ($this->extension == 'mysql') {
				$result->setReturnedRows(@mysql_num_rows($result->getResult())); 
			} elseif ($this->extension == 'mysqli') {
				$result->setReturnedRows(@mysqli_num_rows($result->getResult()));
			} elseif ($this->extension == 'pgsql') {
				$result->setReturnedRows(@pg_num_rows($result->getResult()));
			} elseif ($this->extension == 'sqlite') {
				$result->setReturnedRows(@sqlite_num_rows($result->getResult()));	
			}
		} elseif (is_array($result->getResult())) {
			$result->setReturnedRows(sizeof($result->getResult()));		
		}	
	}
	
	
	/**
	 * Sets the number of rows affected by the query
	 * 
	 * @param  fResult      $result         The result object for the query
	 * @param  PDOStatement $pdo_statement  The PDOStatement object for the PDO extension
	 * @return void
	 */
	private function setAffectedRows(fResult $result, PDOStatement $pdo_statement=NULL)
	{
		if ($this->extension == 'mssql') {
			$affected_rows_result = mssql_query('SELECT @@ROWCOUNT AS rows', $this->connection);
			$result->setAffectedRows((int) @mssql_result($affected_rows_result, 0, 'rows'));   
		} elseif ($this->extension == 'mysql') {
			$result->setAffectedRows(@mysql_affected_rows($this->connection));   
		} elseif ($this->extension == 'mysqli') {
			$result->setAffectedRows(@mysqli_affected_rows($this->connection)); 
		} elseif ($this->extension == 'pgsql') {
			$result->setAffectedRows(@pg_affected_rows($result->getResult())); 
		} elseif ($this->extension == 'sqlite') {
			$result->setAffectedRows(@sqlite_changes($this->connection));
		} elseif ($this->extension == 'pdo') {
			// This fixes the fact that rowCount is not reset for non INSERT/UPDATE/DELETE statements
			try {
				if (!$pdo_statement->fetch()) {
					throw new PDOException();	
				}
				$result->setAffectedRows(0);
			} catch (PDOException $e) {
				$result->setAffectedRows($pdo_statement->rowCount());
			}
		}	
	}
	
	
	/**
	 * Will grab the auto incremented value from the last query (if one exists)
	 * 
	 * @param  fResult $result  The result object for the query
	 * @return void
	 */
	private function handleAutoIncrementedValue(fResult $result)
	{
		$insert_id = NULL;
		
		if ($this->extension == 'mssql') {
			$insert_id_res = mssql_query("SELECT @@IDENTITY AS insert_id", $this->connection);
			$insert_id     = mssql_result($insert_id_res, 0, 'insert_id'); 
		
		} elseif ($this->extension == 'mysql') {
			$insert_id     = @mysql_insert_id($this->connection);   
		
		} elseif ($this->extension == 'mysqli') {
			$insert_id     = @mysqli_insert_id($this->connection);   
		
		} elseif ($this->extension == 'pgsql') {
			@pg_query($this->connection, "BEGIN");
			pg_query($this->connection, "SAVEPOINT get_last_val");
			$insert_id_res = @pg_query($this->connection, "SELECT lastval() AS insert_id");
			if (is_resource($insert_id_res)) {
				$insert_id_row = pg_fetch_assoc($insert_id_res);
				$insert_id = $insert_id_row['insert_id'];
			} else {
				pg_query($this->connection, "ROLLBACK to get_last_val");
			} 
		
		} elseif ($this->extension == 'sqlite') {
			$insert_id = sqlite_last_insert_rowid($this->connection);	
		
		} elseif ($this->extension == 'pdo') {
			switch ($this->type) {
		
				case 'postgresql':
					@$this->connection->query("BEGIN");
					$this->connection->query("SAVEPOINT get_last_val");
					try {
						$insert_id_statement = @$this->connection->query("SELECT lastval() AS insert_id");
						$insert_id_row = $insert_id_statement->fetch(PDO::FETCH_ASSOC);
						$insert_id = $insert_id_row['insert_id'];
					} catch (Exception $e) {
						$this->connection->query("ROLLBACK to get_last_val");	
					}
					break;
		
				case 'mysql':
					$insert_id = $this->connection->lastInsertId();
					break;
		
				case 'sqlite':
					$insert_id = $this->connection->lastInsertId();
					break;
			}	
		}
		
		$result->setAutoIncrementedValue($insert_id);	
	}
	
	
	/**
	 * Closes open db connection
	 * 
	 * @return void
	 */
	public function __destruct()
	{
		fCore::debug('Total query time: ' . $this->query_time . ' seconds', $this->debug);
		if ($this->extension == 'mssql') {
			mssql_close($this->connection);
		} elseif ($this->extension == 'mysql') {
			mysql_close($this->connection);
		} elseif ($this->extension == 'mysqli') {
			mysqli_close($this->connection);
		} elseif ($this->extension == 'pgsql') {
			pg_close($this->connection);    
		} elseif ($this->extension == 'sqlite') {
			sqlite_close($this->connection);	
		} elseif ($this->extension == 'pdo') {
			// PDO objects close their own connections when destroyed	
		}
	}
	
}


// Handle dependency load order for extending exceptions
if (!class_exists('fCore')) { }


/**
 * An exception dealing with sql
 * 
 * @copyright  Copyright (c) 2007-2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @link  http://flourishlib.com/fSQLException
 * 
 * @version  1.0.0 
 * @changes  1.0.0    The initial implementation [wb, 2007-06-14]
 */
class fSQLException extends fUnexpectedException
{
}



/**
 * Copyright (c) 2007-2008 William Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */ 
?>
