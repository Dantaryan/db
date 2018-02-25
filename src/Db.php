<?php

namespace PandyRex;

class Db
{
	/**
	 * @var \mysqli link
	 */
	private static $_lnk;
	/**
	 * @var array cached table structures
	 */
	private static $_tables = [];
	/**
	 * @var array cached table names
	 */
	private static $_tableNames = [];

	/**
	 * fetch one row from table
	 * @param string $qry
	 * @param bool $assoc
	 * @return array
	 * @throws \Exception
	 */
	public static function row($qry, $assoc = true)
	{
		$queryResult = self::result($qry);
		$result = $assoc ? $queryResult->fetch_assoc() : $queryResult->fetch_row();
		$queryResult->close();
		return $result ?: [];
	}

	/**
	 * execute query on current link
	 * @param string $query
	 * @return \mysqli_result
	 * @throws \Exception
	 */
	public static function result($query)
	{
		if (!self::$_lnk) {
			throw new \Exception('no db connection');
		}
		$result = self::$_lnk->query($query);
		if (!$result) {
			throw new \Exception('query error');
		}
		return $result;
	}

	/**
	 * get single value from query
	 * @param string $qry
	 * @return array
	 * @throws \Exception
	 */
	public static function val($qry)
	{
		$queryResult = self::result($qry);
		$result = $queryResult->fetch_row();
		$queryResult->close();
		return $result ? $result[0] : [];
	}

	/**
	 * get array from query where key - first column, value - second
	 * @param string $qry
	 * @return array
	 * @throws \Exception
	 */
	public static function select($qry)
	{
		$queryResult = self::result($qry);
		$result = [];
		while ($row = $queryResult->fetch_row()) {
			$result[$row[0]] = $row[1];
		}
		$queryResult->close();
		return $result;
	}

	/**
	 * get array from query where key - first column, value - array from other columns
	 * @param string $qry
	 * @return array
	 * @throws \Exception
	 */
	public static function iArr($qry)
	{
		$queryResult = self::result($qry);
		$result = [];
		while ($row = $queryResult->fetch_assoc()) {
			$result[array_shift($row)] = $row;
		}
		$queryResult->close();
		return $result;
	}

	/**
	 * get all tables structure from $dbname database
	 * @param string $dbname
	 * @return array
	 * @throws \Exception
	 */
	public static function getTablesStructure($dbname)
	{
		if (self::$_tables && self::$_tableNames) {
			return [
				'tablenams' => self::$_tableNames,
				'tables' => self::$_tables,
			];
		}
		$qry = "SHOW tables FROM $dbname";
		self::$_tableNames = self::col($qry);
		self::$_tables = [];
		for ($i = 0, $length = count(self::$_tableNames); $i < $length; $i++) {
			$tableName = self::$_tableNames[$i];
			$tableParams = self::arr("DESCRIBE $dbname.$tableName");
			$tableFields = [];
			for ($j = 0, $columnsLength = count($tableParams); $j < $columnsLength; $j++) {
				$fieldName = $tableParams[$j]['Field'];
				unset($tableParams[$j]['Field']);
				$tableFields[$fieldName] = $tableParams[$j];
			}
			self::$_tables[$tableName] = $tableFields;
		}
		return [
			'tablenams' => self::$_tableNames,
			'tables' => self::$_tables,
		];
	}

	/**
	 * get unindexed array from query from 1-st column
	 * @param string $qry
	 * @return array
	 * @throws \Exception
	 */
	public static function col($qry)
	{
		$queryResult = self::result($qry);
		$result = [];
		while ($row = $queryResult->fetch_row()) {
			$result[] = $row[0];
		}
		$queryResult->close();
		return $result;
	}

	/**
	 * get array from query
	 * @param string $qry
	 * @param bool $indexed
	 * @return array
	 * @throws \Exception
	 */
	public static function arr($qry, $indexed = false)
	{
		$queryResult = self::result($qry);
		$result = [];
		$method = $indexed ? 'fetch_row' : 'fetch_assoc';
		while ($row = $queryResult->$method()) {
			$result[] = $row;
		}
		$queryResult->close();
		return $result;
	}

	/**
	 * set new db link
	 * @param \mysqli $link
	 */
	public static function setLink(\mysqli $link)
	{
		if ($link) {
			self::$_lnk = $link;
		}
	}

	/**
	 * setup new mysqli connection, returns previous if there was
	 * @param string $host
	 * @param string $user
	 * @param string $pass
	 * @param string $dbname
	 * @param string $port
	 * @param string $socket
	 * @return \mysqli - previous connection
	 * @throws \Exception
	 */
	public static function connect($host, $user, $pass, $dbname, $port = null, $socket = null)
	{
		//$lvl = error_reporting(0);
		if ($socket) {
			$link = new \mysqli($host, $user, $pass, $dbname, $port, $socket);
		} else {
			$link = new \mysqli("$host", $user, $pass, $dbname, $port);
		}

		$previous = self::$_lnk;
		self::$_lnk = $link;
		if ($link->connect_error) {
			throw new \Exception('db connection error');
		}

		self::$_lnk->set_charset('utf8');
		return $previous;
	}

	/**
	 * close current connection
	 */
	public static function disconnect()
	{
		if (self::$_lnk) {
			self::$_lnk->close();
		}
	}

	/**
	 * insert $values to $into (table name [with columns]) adds ON DUPLICATE on 3-d param
	 * @param string $into
	 * @param array $values
	 * @param string $onDuplicateUpdate
	 * @return int
	 * @throws \Exception
	 */
	public static function insert($into, $values, $onDuplicateUpdate = '')
	{
		$names = array_map(function ($val) {
			return '`' . str_replace('`', "\\`", $val) . '`';
		}, array_keys(current($values)));

		foreach ($values as &$set) {
			foreach ($set as &$val) {
				$val = is_null($val) ? 'NULL' : "'" . self::escape($val) . "'";
			}
			$set = '(' . implode(',', $set) . ')';
		}

		$qry = "INSERT IGNORE INTO $into (" . implode(',', $names) . ")
            VALUES " . implode(',', $values);
		if ($onDuplicateUpdate) {
			$qry .= " ON DUPLICATE KEY UPDATE $onDuplicateUpdate";
		}
		return self::query($qry, true);
	}

	/**
	 * @param $str
	 * @alias escape_string
	 * @return string
	 */
	public static function escape($str)
	{
		return self::escapeString($str);
	}

	/**
	 * string escaping for safe use in query string
	 * @param $str
	 * @return string
	 */
	public static function escapeString($str)
	{
		return self::$_lnk->real_escape_string($str);
	}

	/**
	 * execute query and return affected rows (or inserted id on $returnInsertId)
	 * @param string $qry
	 * @param bool $returnInsertId
	 * @return int
	 * @throws \Exception
	 */
	public static function query($qry, $returnInsertId = false)
	{
		self::result($qry);
		return $returnInsertId ? self::insertId() : self::affectedRows();
	}

	/**
	 * returns last inserted id
	 * @return int
	 */
	public static function insertId()
	{
		return (self::$_lnk) ? self::$_lnk->insert_id : 0;
	}

	/**
	 * returns last query affected rows
	 * @return int
	 */
	public static function affectedRows()
	{
		return self::$_lnk ? self::$_lnk->affected_rows : 0;
	}
}
