<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Chars\Key;
use Ultra\Data\Browser;
use Ultra\Data\Statement;

class Temp {
	private string $key;
	private string $table;
	private string $id;
	private string $data;
	private string $ts;
	private array $field;

	private function __construct($table, string $id, string $data, string $ts, array $field = [], string $key='') {
		$this->key   = $key;
		$this->table = $table;
		$this->id    = $id;
		$this->data  = $data;
		$this->ts    = $ts;
		$this->field = $field;
	}

	private function prepare(Browser $b, array $fields=[]): string {
		$field = [];

		if (empty($fields)) {
			$fields = array_keys($this->field);
		}

		$s = Statement::get($b);
		$date = $s->current_date;
		$time = $s->current_time;
		$now  = $s->current_timestamp;

		foreach ($this->field as $key => $val) {
			if (!in_array($key, $fields)) {
				continue;
			}

			$field[] = match ($val) {
				$date, $time, $now => $val,
				default => '\'{'.$key.'}\'',
			};
		}

		return implode(', ', $field);
	}

	public static function __set_state(array $state): self {
		return new Temp($state['table'], $state['id'], $state['data'], $state['ts'], $state['field'], $state['key']);
	}

	public static function open(string $table, string $id='temp_id', string $data='temp_data', string $ts='temp_ts'): self {
		return new Temp($table, $id, $data, $ts);
	}

	/*
	* Вернуть объект с ключем $key из таблицы table
	*/
	public static function read(Browser $b, string $key, string $table, string $id='temp_id', string $data='temp_data'): mixed {
		$data = $b->result('SELECT '.$data.' FROM '.$table.' WHERE '.$id.' = \'{0}\'', [$key]);

		if (!$data) {
			return false;
		}

		return @eval('return '.$data.';');
	}

	/*
	* Вернуть объект с параметром $name и значением $value из таблицы table
	*/
	public static function seek(Browser $b, string $name, int|float|string $value, string $table, string $data='temp_data'): mixed {
		if (is_int($value) || is_float($value)) {
			$like = [
				'\''.$name.'\' => \''.$value.'\'',
				'\''.$name.'\' => '.$value.',',
			];

			$data = $b->result(
				'SELECT '.$data.' FROM '.$table.' WHERE '.$data.' LIKE \'%{0}%\' OR '.$data.' LIKE \'%{1}%\' LIMIT 1',
				$like
			);
		}
		else {
			$data = $b->result(
				'SELECT '.$data.' FROM '.$table.' WHERE '.$data.' LIKE \'%{0}%\' LIMIT 1',
				['\''.$name.'\' => \''.$value.'\'']
			);
		}

		if (!$data) {
			return false;
		}

		return @eval('return '.$data.';');
	}

	public function inData(Browser $b, array $fields): bool {
		$likes = $this->getLikes($fields);
		return (bool) $b->result('SELECT COUNT(*) FROM '.$this->table.' WHERE '.implode(' OR ', $likes[0]), $likes[1]);
	}

	public function isData(Browser $b, array $fields): bool {
		$likes = $this->getLikes($fields);
		return (bool) $b->result('SELECT COUNT(*) FROM '.$this->table.' WHERE '.implode(' AND ', $likes[0]), $likes[1]);
	}

	public function getData(Browser $b, array $fields, int $limit = 1): array|false {
		$likes = $this->getLikes($fields);

		$rows = $b->rows(
			'SELECT '.$this->data.' FROM '.$this->table.'
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

	private function getLikes(array $fields): array {
		$likes  = [[], []];

		foreach ($fields as $name => $value) {
			$likes[0][$name]  = ''.$this->data.' LIKE \'%{'.$name.'}%\'';
			$likes[1][$name] = '\''.$name.'\' => \''.$value.'\'';
		}

		return $likes;
	}

	public function fields(): array {
		return $this->field;
	}

	public function key(
		Browser $b,
		array|string|null $target = null,
		array|string $field = ['code'],
		Key $set = Key::CHR,
		int $size = 7,
	): string {
		if ('' == $this->key) {
			if ($size < 1) {
				$size = 7;
			}

			if (is_array($target)) {
				if (!in_array($this->table, $target)) {
					$target[] = $this->table;
					$field[]  = $this->id;
				}

				$this->key = new Unique($target, $field)->getCode($b, $set, $size, true);
			}
			elseif (is_string($target)) {
				$this->key = new Unique([$target, $this->table], [$field, $this->id])->getCode($b, $set, $size, true);
			}
			else {
				$this->key = new Unique([$this->table], [$this->id])->getCode($b, $set, $size, true);
			}
		}

		return $this->key;
	}

	public function write(Browser $b, array|string|null $target = null, array|string $field = 'code'): void {
		if ('' == $this->key) {
			$this->key($b, $target, $field);
		}

		$b->run(
			'REPLACE INTO '.$this->table.' ('.$this->id.', '.$this->data.') VALUES (\'{0}\', \'{1}\')',
			[$this->key, var_export($this, true)]
		);
	}

	public function delete(Browser $b): void {
		if ('' == $this->key) {
			return;
		}

		$b->run('DELETE FROM '.$this->table.' WHERE '.$this->id.' = \''.$this->key.'\'');
	}

	public function __get(string $name): mixed {
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

	public function __set(string $name, string $value): void {
		$this->field[$name] = $value;

		if (NULL === $value) {
			unset($this->field[$name]);
		}
	}

	public function __unset(string $name): void {
		unset($this->field[$name]);
	}

	public function insert(Browser $b, string $table, array $fields = []): string {
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

		if (!$b->run('INSERT INTO '.$table.' ('.implode(', ', $fields).') VALUES ('.$this->prepare($b, $fields).')', $this->field)) {
			return false;
		}

		return $b->result('SELECT '.Statement::get($b)->last_insert_id);
	}

	public function replace(Browser $b, string $table, array $fields=[]): bool {
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

		if (!$b->run('REPLACE INTO '.$table.' ('.implode(', ', $fields).') VALUES ('.$this->prepare($b, $fields).')', $this->field)) {
			return false;
		}

		return true;
	}

	public function transfer(Browser $b, string $table, array $fields = []): bool {
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

		if (!$b->run('REPLACE INTO '.$table.' ('.implode(', ', $fields).') VALUES ('.$this->prepare($b, $fields).')', $this->field)) {
			return false;
		}
		
		$b->run('DELETE FROM '.$this->table.' WHERE '.$this->id.' = \''.$this->key.'\'');

		return true;
	}

	public function curdate(Browser $b): string	{
		return $b->result('SELECT '.Statement::get($b)->current_date);
	}

	public function curtime(Browser $b): string	{
		return $b->result('SELECT '.Statement::get($b)->current_time);
	}

	public function now(Browser $b): string	{
		return $b->result('SELECT '.Statement::get($b)->current_timestamp);
	}
}
