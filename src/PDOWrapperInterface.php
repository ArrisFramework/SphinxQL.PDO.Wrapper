<?php

namespace Arris\Toolkit\SphinxQL;

use Closure;
use Psr\Log\LoggerInterface;

interface PDOWrapperInterface
{
    public function __construct($mysql_connection, $sphinx_connection, array $options = [], LoggerInterface $logger = null);
    public function setOptions(array $options = []):array;
    public function setConsoleMessenger(callable $messenger);
    public function rebuildAbstractIndex(string $mysql_table, string $searchd_index, Closure $make_updated_set_method, string $condition = '', bool $mva_columns_used = false, array $mva_indexes_list = []):int;

    public function checkIndexExist(string $index):bool;

}