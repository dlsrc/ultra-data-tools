<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Data\Inquirer;

final class Tree {
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
	* Поле порядкового номера узла.
	*/
	private string $serial;

	/**
	* Конструктор
	*/
	public function __construct(
		string $table,
		string $id,
		string $parent,
		string $left,
		string $right,
		string $serial = ''
	) {
		$this->table   = $table;
		$this->id      = $id;
		$this->pid     = $parent;
		$this->left    = $left;
		$this->right   = $right;
		$this->serial  = $serial;
	}

	/**
	* Метод переиндексации дерева.
	*/
	public function reindex(Inquirer $db, array $root = []): void {
		$db->run(
			'UPDATE '.$this->table.' SET '.
			''.$this->left.' = 0, '.
			''.$this->right.' = 0 '.
			'WHERE '.$this->id.' <> 0'
		);

		if ('' != $this->serial) {
			$order = 'ORDER BY '.$this->serial.' ASC, '.$this->id.' ASC';
		}
		else {
			$order = 'ORDER BY '.$this->id.' ASC';
		}

		if (empty($root)) {
			$node = $db->assoc(
				'SELECT '.$this->id.' AS code FROM '.$this->table.' '.
				'WHERE '.$this->pid.' = 0 '.$order
			);
		}
		else {
			$node = [];

			foreach ($root as $value) {
				$node[] = ['code'=>$value];
			}
		}

		$size = sizeof($node);

		$left  = 1;
		$right = 2;

		for ($i = 0; $i < $size; $i++) {
			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$left.', '.
				''.$this->right.' = '.$right.' '.
				'WHERE '.$this->id.' = '.$node[$i]['code']
			);

			$left += 2;
			$right+= 2;
		}

		$i = 0;
		$k = $size;

		while (isset($node[$i]['code'])) {
			$child = $db->assoc(
				'SELECT '.$this->id.' AS code FROM '.$this->table.' '.
				'WHERE '.$this->pid.' = '.$node[$i]['code'].' '.$order
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
				'WHERE '.$this->id.' = '.$node[$k]['code']
			);

			$left = $db->result(
				'SELECT '.$this->right.' '.
				'FROM '.$this->table.' '.
				'WHERE '.$this->id.' = '.$parent
			);
		
			$right = $left + 1;

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$this->left.' + 2 '.
				'WHERE '.$this->left.' > '.$left
			);

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->right.' = '.$this->right.' + 2 '.
				'WHERE '.$this->right.' >= '.$left
			);

			$db->run(
				'UPDATE '.$this->table.' SET '.
				''.$this->left.' = '.$left.', '.
				''.$this->right.' = '.$right.' '.
				'WHERE '.$this->id.' = '.$node[$k]['code']
			);
		}
	}

	/**
	* Метод перерасчета глубины вложенности узлов дерева
	* @param string deep - имя поля в таблице хранящий уровень вложенности.
	*/
	public function levelizing(Inquirer $db, string $field): void {
		if ('' != $this->serial) {
			$order = 'ORDER BY '.$this->serial.' ASC, '.$this->id.' ASC';
		}
		else {
			$order = 'ORDER BY '.$this->id.' ASC';
		}

		$db->run(
			'UPDATE '.$this->table.' SET '.
			''.$field.' = 1 '.
			'WHERE '.$this->pid.' = 0'
		);

		$node = $db->combine(
			'SELECT '.$this->id.' FROM '.$this->table.' '.
			'WHERE '.$field.' = 1 '.$order
		);

		for($deep = 2; sizeof($node) > 0; $deep++) {
			$db->run(
				'UPDATE '.$this->table.' '.
				'SET '.$field.' = '.$deep.' '.
				'WHERE '.$this->pid.' IN('.implode(',',$node).')'
			);

			$node = $db->combine(
				'SELECT '.$this->id.' FROM '.$this->table.' '.
				'WHERE '.$field.' = '.$deep.' '.$order
			);
		}
	}
}
