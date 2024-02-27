<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Core;
use Ultra\IO;

class Replicator {
	private $server;
	private $query;

	public static function connect($exchanger) {
		if (!\preg_match('/^https?\:\/\//is', $exchanger)) {
			$exchanger = 'http://'.$exchanger;
		}

		//$query = \file_get_contents($exchanger);
		if (!$ch = \curl_init($exchanger)) {
			return false;
		}

		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_HEADER, false);
		$query = \curl_exec($ch);
		\curl_close($ch);

		if (!$query) {
			return false;
		}

		if (!$query || !$q = Query::decode($query)) {
			return false;
		}

		return new Replicator($exchanger, $q);
	}

	private function __construct($server, array $query) {
		$this->server = $server;
		$this->query  = $query;
		$this->query['host'] = $_SERVER['HTTP_HOST'];
	}

	public function __get($name) {
		if (isset($this->query[$name])) return $this->query[$name];
		return false;
	}

	public function __call($name, $value) {
		switch ($name) {
		case 'rows': case 'assoc': case 'column': case 'combine':
		case 'table': case 'view': case 'row': case 'result':
		case 'run': case 'affect': case 'slice': case 'aslice':
		case 'shift': case 'ashift': case 'columns': case 'combines':
			if (isset($value[1])) {
				return $this->sql($name, $value[0], $value[1]);
			}

			return $this->sql($name, $value[0]);

		case 'load':
			unset($this->query['data'], $this->query['errno'], $this->query['error']);
			$this->query['action'] = 'LOAD';
			$this->query['source_id'] = $value[0];
			return $this->getData();

		case 'send':
			unset($this->query['data'], $this->query['errno'], $this->query['error']);
			$this->query['action'] = 'SEND';
			$this->query['send'] = $value[0];
			$send = $this->getData('send');
			unset($this->query['send']);

			if (isset($send['replica_id'])) {
				return $send['replica_id'];
			}

			return 0;
		}
	}

	public function copy($src, $dist) {
		if (!IO::indir($dist)) {
			return false;
		}

		if (!$ch = \curl_init($src)) {
			return false;
		}

		if (!$fp = \fopen($dist, 'w')) {
			return false;
		}

		\curl_setopt($ch, CURLOPT_FILE, $fp);
		$exec = \curl_exec($ch);
		\curl_close($ch);
		\fclose($fp);
		return $exec;
	}

	private function sql($method, $sql, array $value=[]) {
		unset($this->query['data'], $this->query['errno'], $this->query['error']);
		$this->query['action'] = 'SQL';
		$this->query['method'] = $method;
		$this->query['sql']    = $sql;
		$this->query['value']  = $value;
		return $this->getData();
	}

	private function getData($name='data') {
		//if (!$query = \file_get_contents($this->server.'/index.php?'.Query::pack($this->query, true))) return false;
		if (!$ch = \curl_init($this->server)) {
			return false;
		}

		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_HEADER, false);
		\curl_setopt($ch, CURLOPT_POST, true);
		\curl_setopt($ch, CURLOPT_POSTFIELDS, [Query::NAME => Query::pack($this->query)]);
		$query = \curl_exec($ch);
		\curl_close($ch);

		if (!$query) {
			return false;
		}

		$this->query = Query::decode($query);
		$this->query[$name] ??= [];
		return $this->query[$name];
	}
}
