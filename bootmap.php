<?php declare(strict_types=1);
/**
 * (c) 2005-2024 Dmitry Lebedev <dl@adios.ru>
 * This source code is the Ultra autoloader.
 */
require_once dirname(__DIR__).'/core/src/Boot.php';
Ultra\Boot::map(basepath: __DIR__, source: ['src']);
