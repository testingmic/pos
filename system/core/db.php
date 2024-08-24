<?php
/**
 * Common Functions
 *
 * Loads the base classes and executes the request.
 *
 * @package		POS
 * @subpackage	POS Super Class
 * @category	Core Functions
 * @author		VisamiNetSolutions Dev Team
 */

class DB {
	
	private $argon;
	private $conn;
	private $hostname;
	private $password;
	private $username;
	private $database;
	
	public function __construct() {
		
		$this->hostname = DB_HOST;
		$this->username = DB_USER;
		$this->password = DB_PASS;
		$this->database = DB_NAME;
		
		if($this->argon == null) {
			$this->argon = $this->db_connect($this->hostname, $this->username, $this->password, $this->database);
		}
	}
	public function get_database(){
		return $this->argon;
	}

	private function db_connect($hostname, $username, $password, $database) {
		
		try {
			$this->conn = "mysql:host=$hostname; dbname=$database; charset=utf8";
			
			$argon = new PDO($this->conn, $username, $password);
			$argon->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$argon->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH);
			
			return $argon;
			
		} catch(PDOException $e) {
			die("It seems there was an error.  Please refresh your browser and try again. ".$e->getMessage());
		}
		
	}

	public function query($sql) {
		
		try {
					
			$stmt = $this->argon->prepare("$sql");
			$stmt->execute();
			$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
			return $results;
		
		} catch(PDOException $e) {return 0;}
	}

	public function execute($sql) {
		
		try {
			$stmt = $this->argon->prepare("$sql");
			$stmt->execute();
		} catch(PDOException $e) {return 0;}
	}
	
	public function rowCount($results) {

		return count($results);

	}
}