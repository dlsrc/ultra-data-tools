<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Chars\Key;
use Ultra\Data\Browser;

class Temp {
	private $key;
	private $table;
	private $id;
	private $data;
	private $ts;
	private $field;

	private function __construct($table, $id, $data, $ts, array $field=[], $key='') {
		$this->key   = $key;
		$this->table = $table;
		$this->id    = $id;
		$this->data  = $data;
		$this->ts    = $ts;
		$this->field = $field;
	}

	private function prepare(array $fields=[]) {
		$field = [];

		if (empty($fields)) $fields = array_keys($this->field);

		foreach ($this->field as $key => $val) {
			if (!in_array($key, $fields)) {
				continue;
			}

			switch ($val) {
			case 'CURRENT_DATE': case 'CURRENT_TIME': case 'CURRENT_TIMESTAMP':
			case 'CURDATE()':    case 'CURTIME()':    case 'NOW()':

				$field[] = $val;
				break;

			default:

				$field[] = '"{'.$key.'}"';
			}	
		}

		return implode(', ', $field);
	}

	public static function __set_state(array $state) {
		return new Temp($state['table'], $state['id'], $state['data'], $state['ts'], $state['field'], $state['key']);
	}

	public static function open($table='temp', $id='temp_id', $data='temp_data', $ts='temp_ts') {
		return new Temp($table, $id, $data, $ts);
	}

	/*
	* Вернуть объект с ключем $key из таблицы table
	*/
	public static function read(Browser $b, $key, $table='temp', $id='temp_id', $data='temp_data') {
		$data = $b->result(
			'SELECT '.$data.' FROM ~'.$table.' WHERE BINARY '.$id.' = "{0}"',
			[$key]
		);

		if (!$data) {
			return false;
		}

		return @eval('return '.$data.';');
	}

	/*
	* Вернуть объект с параметром $name и значением $value из таблицы table
	*/
	public static function seek(Browser $b, $name, $value, $table='temp', $data='temp_data') {
		if (is_int($value) || ctype_digit($value)) {
			$like = [
				'\''.$name.'\' => \''.$value.'\'',
				'\''.$name.'\' => '.$value.',',
			];

			$data = $b->result(
				'SELECT '.$data.' FROM ~'.$table.' WHERE '.$data.' LIKE "%{0}%" OR '.$data.' LIKE "%{1}%" LIMIT 1',
				$like
			);
		}
		else {
			$data = $b->result(
				'SELECT '.$data.' FROM ~'.$table.' WHERE '.$data.' LIKE "%{0}%" LIMIT 1',
				['\''.$name.'\' => \''.$value.'\'']
			);
		}

		if (!$data) {
			return false;
		}

		return @eval('return '.$data.';');
	}

	public function inData(Browser $b, array $fields) {
		$likes = $this->getLikes($fields);
		return (bool) $b->result('SELECT COUNT(*) FROM ~'.$this->table.' WHERE '.implode(' OR ', $likes[0]), $likes[1]);
	}

	public function isData(Browser $b, array $fields) {
		$likes = $this->getLikes($fields);
		return (bool) $b->result('SELECT COUNT(*) FROM ~'.$this->table.' WHERE '.implode(' AND ', $likes[0]), $likes[1]);
	}

	public function getData(Browser $b, array $fields, $limit = '1') {
		$likes = $this->getLikes($fields);

		$rows = $b->rows(
			'SELECT '.$this->data.' FROM ~'.$this->table.'
			WHERE '.implode(' AND ', $likes[0]).' LIMIT '.$limit,
			$likes[1]
		);

		if (empty($rows)) {
			return false;
		}
		
		foreach ($rows as &$data) {
			$data = @eval('return '.$data.';');
		}

		return $rows;
	}

	private function getLikes(array $fields) {
		$likes  = [[], []];

		foreach ($fields as $name => $value) {
			$likes[0][$name]  = ''.$this->data.' LIKE "%{'.$name.'}%"';
			$likes[1][$name] = '\''.$name.'\' => \''.$value.'\'';
		}

		return $likes;
	}

	public function fields() {
		return $this->field;
	}

	public function key(Browser $b, $target = false, $field = ['code'], $set = Key::CHR, $size = 7) {
		if ('' == $this->key) {
			$size = (int) $size;

			if ($size < 1) {
				$size = 7;
			}

			if (is_array($target)) {
				if (!in_array($this->table, $target)) {
					$target[] = $this->table;
					$field[]  = $this->id;
				}

				$this->key = (new Unique($target, $field))->getCode($b, $set, $size, true);
			}
			elseif (is_string($target)) {
				$this->key = (new Unique([$target, $this->table], [$field, $this->id]))->getCode($b, $set, $size, true);
			}
			else {
				$this->key = (new Unique([$this->table], [$this->id]))->getCode($b, $set, $size, true);
			}
		}

		return $this->key;
	}

	public function write(Browser $b, $target = false, $field = 'code') {
		if ('' == $this->key) {
			$this->key($b, $target, $field);
		}

		$b->run(
			'REPLACE INTO ~'.$this->table.' ('.$this->id.', '.$this->data.') VALUES ("{0}", "{1}")',
			[$this->key, var_export($this, true)]
		);
	}

	public function delete(Browser $b) {
		if ('' == $this->key) {
			return;
		}

		$b->run('DELETE FROM ~'.$this->table.' WHERE '.$this->id.' = "'.$this->key.'"');
	}

	public function __get($name) {
		if (isset($this->field[$name])) {
			return $this->field[$name];
		}

		if (property_exists($this, $name)) {
			return $this->$name;
		}
		else {
			return false;
		}
	}

	public function __set($name, $value) {
		$this->field[$name] = $value;

		if (NULL === $value) {
			unset($this->field[$name]);
		}
	}

	public function __unset($name) {
		unset($this->field[$name]);
	}

	public function insert(Browser $b, $table, array $fields=[]) {
		if (empty($this->field)) {
			return false;
		}

		if (empty($fields)) {
			$fields = array_keys($this->field);
		}

		foreach ($fields as $key) {
			if (!isset($this->field[$key])) {
				return false;
			}
		}

		if (!$b->run(
			'INSERT INTO ~'.$table.'
			('.implode(', ', $fields).')
			VALUES ('.$this->prepare($fields).')',
			$this->field
		)) return false;

		return $b->result('SELECT LAST_INSERT_ID()');
	}

	public function replace(Browser $b, $table, array $fields=[]) {
		if (empty($this->field)) {
			return false;
		}

		if (empty($fields)) {
			$fields = array_keys($this->field);
		}

		foreach ($fields as $key) {
			if (!isset($this->field[$key])) {
				return false;
			}
		}

		if (!$b->run(
			'REPLACE INTO ~'.$table.'
			('.implode(', ', $fields).')
			VALUES ('.$this->prepare($fields).')',
			$this->field
		)) return false;

		return true;
	}

	public function transfer(Browser $b, $table, array $fields=[])
	{
		if (empty($this->field)) return false;
		if (empty($fields)) $fields = array_keys($this->field);

		foreach ($fields as $key)
		{
			if (!isset($this->field[$key])) return false;
		}

		if (!$b->run(
			'REPLACE INTO ~'.$table.'
			('.implode(', ', $fields).')
			VALUES ('.$this->prepare($fields).')',
			$this->field
		)) return false;
		
		$b->run('DELETE FROM ~'.$this->table.' WHERE '.$this->id.' = "'.$this->key.'"');
		return true;
	}

	public function curdate(Browser $b)
	{
		return $b->result('SELECT CURDATE()');
	}

	public function curtime(Browser $b)
	{
		return $b->result('SELECT CURTME()');
	}

	public function now(Browser $b)
	{
		return $b->result('SELECT NOW()');
	}
}
