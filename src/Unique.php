<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Chars\Key;
use Ultra\Data\Browser;

final class Unique {
	/**
	* Список полей таблиц с уникальным кодом
	* ключ списка - имя таблицы;
	* значение - имя поля в этой таблице;
	*/
	private array $field;

	public function __construct(array $table, array $field) {
		if (!$combine = array_combine($table, $field)) {
			$this->field = [];
		}
		else {
			$this->field = $combine;
		}
	}

	public function getCode(Browser $b, Key $key, int $length, bool $nod = false): string {
		if (empty($this->field)) {
			return '';
		}

		do {
			$code = $key->gen(length: $length, nodigits: $nod);

			foreach ($this->field as $table => $field) {
				if ($count = $b->result(
					'SELECT COUNT(*) FROM '.$table.' WHERE '.$field.' = \''.$code.'\''
				)) {
					break;
				}
			}
		}
		while ($count > 0);

		return $code;
	}
}
