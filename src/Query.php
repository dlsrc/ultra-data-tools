<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is part of the Ultra net package.
 * Please see the LICENSE file for copyright and licensing information.
 */
namespace Ultra\Data\Tool;

class Query {
	public const string NAME = '_exchanger_query_package_';

	public static function decode(string $string): mixed {
		return unserialize(rawurldecode($string));
	}

	public static function encode(mixed $mixed): string {
		return rawurlencode(serialize($mixed));
	}

	public static function pack(mixed $mixed, string $query = '', string $name = self::NAME): string {
		if ('' != $query) {
			return $name.'='.self::encode($mixed);
		}

		return self::encode($mixed);
	}

	public static function unpack(string $name = self::NAME): mixed {
		if (!isset($_REQUEST[$name])) {
			return false;
		}

		return self::decode($_REQUEST[$name]);
	}
}
