<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra data package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

use Ultra\Core;
use Ultra\Log;
use Ultra\Data\Provider;

class Exchanger {
	const
	E_NOREG    = 700,
	E_TOKEN    = 701,
	E_NOHOST   = 702,
	E_CONNECT  = 703,
	E_DOMAIN   = 704,
	E_ACTION   = 705,
	E_BADACT   = 706,
	E_QUERY    = 707,
	E_METHOD   = 708,
	E_NOMETHOD = 709,
	E_SQL      = 710,
	E_SOURCE   = 711,
	E_NOSRC    = 712,
	E_MEDIATOR = 713,
	E_SEND     = 714,
	E_EXISTS   = 715;

	public static function run($token, $reg, $px='') {
		Core::get()->listen();

		if (!$q = Query::unpack(Query::NAME)) {
			exit(Query::pack(['token' => $token]));
		}

		if (!isset($q['token'])) {
			$q['token'] = $token;
			$q['errno'] = self::E_TOKEN;
			$q['error'] = 'Bad request';
			exit(Query::pack($q));
		}

		if (!\file_exists($reg)) {
			$q['errno'] = self::E_NOREG;
			$q['error'] = 'Register not exists';
			exit(Query::pack($q));
		}

		if (!isset($q['host'])) {
			$q['errno'] = self::E_NOHOST;
			$q['error'] = 'Host unavailable';
			exit(Query::pack($q));
		}

		$q['prefix'] ??= $px;

		if (isset($q['action']) && 'SEND' == $q['action']) {
			$dsn = 'type=sqlite3&db='.$reg.'&mode=full&prefix='.$q['prefix'];
		}
		elseif (isset($q['sql']) && 'SELECT' != \substr($q['sql'], 0, 6)) {
			$dsn = 'type=sqlite3&db='.$reg.'&mode=full&prefix='.$q['prefix'];
		}
		else {
			$dsn = 'type=sqlite3&db='.$reg.'&mode=read&prefix='.$q['prefix'];
		}

		if (!$b = Provider::Browser($dsn)) {
			$q['errno'] = self::E_CONNECT;
			$q['error'] = Log::get()->last()->message;
			exit(Query::pack($q));
		}

		$domain = $b->row(
			'SELECT `domain`, `proxy`, `mediator` FROM `~domain`
			WHERE `domain` = "{host}"', $q
		);

		if (empty($domain)) {
			$q['errno'] = self::E_DOMAIN;
			$q['error'] = 'Bad domain';
			exit(Query::pack($q));
		}

		unset($domain[0], $domain[1], $domain[2]);
		$q+= $domain;

		if (!isset($q['action'])) {
			$q['errno'] = self::E_ACTION;
			$q['error'] = 'Action unavailable';
			exit(Query::pack($q));
		}

		switch ($q['action']) {
		case 'SQL':
			if (!isset($q['sql'])) {
				$q['errno'] = self::E_QUERY;
				$q['error'] = 'Query unavailable';
				exit(Query::pack($q));
			}

			if (!isset($q['method'])) {
				$q['errno'] = self::E_METHOD;
				$q['error'] = 'Method unavailable';
				exit(Query::pack($q));
			}

			if (!\method_exists($b, $q['method'])) {
				$q['errno'] = self::E_NOMETHOD;
				$q['error'] = 'Method not exists';
				exit(Query::pack($q));
			}

			if (!isset($q['value'])) {
				$q['value'] = [];
			}

			if (!$q['data'] = $b->{$q['method']}($q['sql'], $q['value'])) {
				$q['errno'] = self::E_SQL;
				$q['error'] = Log::get()->last()->message;
			}

			exit(Query::pack($q));

		case 'LOAD':
			if (!isset($q['source_id'])) {
				$q['errno'] = self::E_SOURCE;
				$q['error'] = 'Source ID unavailable';
				exit(Query::pack($q));
			}

			$url = $b->row(
				'SELECT `post_id`, `url` FROM `~register`
				WHERE `mediator` = ""
				AND `source_id` = "'.$q['source_id'].'"
				AND `recipient` NOT LIKE "%['.$q['host'].']%"'
			);

			if (empty($url)) {
				$q['errno'] = self::E_NOSRC;
				$q['error'] = 'Source not available';
				exit(Query::pack($q));
			}

			$q['post_id'] = $url[0];

			if ($ch = \curl_init($url[1])) {
				\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				\curl_setopt($ch, CURLOPT_HEADER, false);
				\curl_setopt($ch, CURLOPT_POST, true);
				\curl_setopt($ch, CURLOPT_POSTFIELDS, [Query::NAME => Query::pack($q)]);
				$m = \curl_exec($ch);
				\curl_close($ch);
				if ($m) exit($m);
			}

			$q['errno'] = self::E_MEDIATOR;
			$q['error'] = 'Mediator not responsed';
			exit(Query::pack($q));

		case 'SEND':
			if ($q['send']['source_id'] = $b->result('SELECT `source_id` FROM `~source` WHERE `source` = "{source}"', $q['send'])) {
				$q['errno'] = self::E_EXISTS;
				$q['error'] = 'Source exists';
				exit(Query::pack($q));
			}

			if (!$b->run(
				'INSERT INTO `~source` (`timestamp`, `source`, `recipient`, `status`)
				VALUES ("{timestamp}", "{source}", "{recipient}", "{status}")',
				$q['send']
			)) {
				$q['errno'] = self::E_SEND;
				$q['error'] = 'Source sended data error';
				exit(Query::pack($q));
			}

			$q['send']['source_id'] = $b->result('SELECT last_insert_rowid()');

			foreach ($q['send']['ml'] as $data) {
				$b->run(
					'INSERT INTO `~source_header` (`source_id`, `lang_id`, `header`)
					VALUES ("'.$q['send']['source_id'].'", "{0}", "{1}")',
					$data
				);
			}

			$b->run(
				'INSERT INTO `~replica` (`timestamp`, `source_id`, `post_id`, `post_code`, `url`, `domain`, `mediator`)
				VALUES ("{timestamp}", "{source_id}", "{post_id}", "{post_code}", "{url}", "{domain}", "{mediator}")',
				$q['send']
			);

			$q['send']['replica_id'] = $b->result('SELECT last_insert_rowid()');

			exit(Query::pack($q));

		default:
			$q['errno'] = self::E_BADACT;
			$q['error'] = 'Unknown action';
			exit(Query::pack($q));
		}
	}
}
