<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Data\Browser;

final class TreeSegment {	
	/**
	* Поля индексов - по которым можно сегментировать дерево (множественные деревья).
	*/
	private array $segment;

	/**
	* Имя таблицы дерева.
	*/
	private string $table;

	/**
	* Поле первичного ключа.
	*/
	private string $id;

	/**
	* Поле индексов, рекурсивно указывающих на родительский узел.
	*/
	private string $pid;

	/**
	* Поле индексов - указателей на соседа слева.
	*/
	private string $left;

	/**
	* Поле индексов - указателей на соседа справа.
	*/
	private string $right;	
	
	/**
	* Поле индексов - глубины вложенности.
	*/
	private string $deep;

	/**
	* Поле порядкового номера узла.
	*/
	private string $serial;

	/**
	* Конструктор
	*/
	public function __construct(
		array  $segment,
		string $table,
		string $id,
		string $parent,
		string $left,
		string $right,
		string $deep,
		string $serial = ''
	) {
		$this->segment = $segment;
		$this->table   = $table;
		$this->id      = $id;
		$this->pid     = $parent;
		$this->left    = $left;
		$this->right   = $right;
		$this->deep    = $deep;
		$this->serial  = $serial;
	}

	/**
	* Метод переиндексации сегмента дерева.
	* $segment - значение индекса сегментации
	*/
	public function reindex(Browser $db): void {
		$segment = array_keys($this->segment);
		$data = array_values($this->segment);

		foreach (array_keys($segment) as $id) {
			$segment[$id] = ''.$segment[$id].' = "{'.$id.'}"';
		}

		$segment = implode(' AND ', $segment);

		if ('' != $this->serial) {
			$order = 'ORDER BY '.$this->serial.' ASC, '.$this->id.' ASC';
		}
		else {
			$order = 'ORDER BY '.$this->id.' ASC';
		}

		$db->run(
			'UPDATE '.$this->table.' SET '.
			''.$this->left.' = 0, '.
			''.$this->right.' = 0 '.
			'WHERE '.$segment,
			$data
		);

		$node = $db->assoc(
			'SELECT '.$this->id.' AS code FROM '.$this->table.' '.
			'WHERE '.$segment.' AND '.$this->pid.' = 0 '.$order,
			$data
		);

		$size = sizeof($node);

		$left  = 1;
		$right = 2;

		for ($i = 0; $i < $size; $i++) {
			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$left.', '.
				''.$this->right.' = '.$right.' '.
				'WHERE '.$segment.' '.
				'AND '.$this->id.' = '.$node[$i]['code'],
				$data
			);

			$left += 2;
			$right+= 2;
		}

		$i = 0;
		$k = $size;

		while (isset($node[$i]['code'])) {
			$child = $db->assoc(
				'SELECT '.$this->id.' AS code FROM '.$this->table.' '.
				'WHERE '.$segment.' AND '.$this->pid.' = '.$node[$i]['code'].' '.$order,
				$data
			);

			foreach ($child as $val) {
				$node[$size++] = $val;
			}

			$i++;
		}
	
		for (; $k < $size; $k++) {
			$parent = $db->result(
				'SELECT '.$this->pid.' '.
				'FROM '.$this->table.' '.
				'WHERE '.$segment.' '.
				'AND '.$this->id.' = '.$node[$k]['code'],
				$data
			);

			$left = $db->result(
				'SELECT '.$this->right.' '.
				'FROM '.$this->table.' '.
				'WHERE '.$segment.' '.
				'AND '.$this->id.' = '.$parent,
				$data
			);
		
			$right = $left + 1;

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$this->left.' + 2 '.
				'WHERE '.$segment.' '.
				'AND '.$this->left.' > '.$left,
				$data
			);

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->right.' = '.$this->right.' + 2 '.
				'WHERE '.$segment.' '.
				'AND '.$this->right.' >= '.$left,
				$data
			);

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$left.', '.
				''.$this->right.' = '.$right.' '.
				'WHERE '.$segment.' '.
				'AND '.$this->id.' = '.$node[$k]['code'],
				$data
			);
		}
	}

	/**
	* Метод перерасчета глубины вложенности узлов дерева
	* deep - имя поля в таблице хранящий уровень вложенности.
	*/
	public function levelizing(Browser $db): void {
		$segment = array_keys($this->segment);
		$data = array_values($this->segment);

		foreach (array_keys($segment) as $id) {
			$segment[$id] = ''.$segment[$id].' = "{'.$id.'}"';
		}

		$segment = implode(' AND ', $segment);

		if ('' != $this->serial) {
			$order = 'ORDER BY '.$this->serial.' ASC, '.$this->id.' ASC';
		}
		else {
			$order = 'ORDER BY '.$this->id.' ASC';
		}

		$db->run(
			'UPDATE '.$this->table.' SET '.
			''.$this->deep.' = 1 '.
			'WHERE '.$segment.' '.
			'AND '.$this->pid.' = 0',
			$data
		);

		$node = $db->combine(
			'SELECT '.$this->id.' FROM '.$this->table.' '.
			'WHERE '.$segment.' AND '.$this->pid.' = 0 '.$order,
			$data
		);

		for($deep=2; sizeof($node) > 0; $deep++) {
			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->deep.' = '.$deep.' '.
				'WHERE '.$segment.' '.
				'AND '.$this->pid.' IN('.implode(',',$node).')',
				$data
			);

			$node = $db->combine(
				'SELECT '.$this->id.' FROM '.$this->table.' '.
				'WHERE '.$segment.' AND '.$this->deep.' = '.$deep.' '.$order,
				$data
			);
		}
	}
}
