<?php

namespace Arris\Toolkit\SphinxQL;

use Closure;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function call_user_func_array;
use function ceil;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_null;

class PDOWrapper implements PDOWrapperInterface
{
    /**
     * MySQL-коннектор.
     *
     * @var PDO
     */
    private PDO $mysql_connection;

    /**
     * PDO-коннектор к SearchD
     *
     * @var PDO
     */
    private PDO $searchd_connection;

    /**
     * Логгер
     *
     * @var LoggerInterface|NullLogger
     */
    private $logger;

    /**
     * Опции
     *
     * @var array
     */
    private array $options = [];

    /**
     * @var callable
     */
    private $messenger;

    /**
     * @param $mysql_connection
     * @param $sphinx_connection
     * @param array $options
     * @param LoggerInterface|null $logger
     */
    public function __construct($mysql_connection, $sphinx_connection, array $options = [], LoggerInterface $logger = null)
    {
        $this->mysql_connection = $mysql_connection;
        $this->searchd_connection = $sphinx_connection;

        $this->logger = is_null($logger) ? new NullLogger() : $logger;

        $this->messenger = function ($message = '', $linebreak = true) {
            echo $message;
            if ($linebreak) {
                echo PHP_EOL;
            }
        };

        $this->setOptions($options);
    }

    /**
     * Устанавливает опции для перестроителя RT-индекса
     *
     * @param array $options - новый набор опций
     * @return array - результирующий набор опций
     */
    public function setOptions(array $options = []):array
    {
        // разворачиваем опции с установкой дефолтов
        $this->options['chunk_length'] = self::setOption($options, 'chunk_length', 500);

        $this->options['log_rows_inside_chunk'] = self::setOption($options, 'log_rows_inside_chunk', true);
        $this->options['log_total_rows_found'] = self::setOption($options, 'log_total_rows_found', true);

        $this->options['log_before_chunk'] = self::setOption($options, 'log_before_chunk', true);
        $this->options['log_after_chunk'] = self::setOption($options, 'log_after_chunk', true);

        $this->options['sleep_after_chunk'] = self::setOption($options, 'sleep_after_chunk', true);

        $this->options['sleep_time'] = self::setOption($options, 'sleep_time', 1);
        if ($this->options['sleep_time'] == 0) {
            $this->options['sleep_after_chunk'] = false;
        }

        $this->options['log_before_index'] = self::setOption($options, 'log_before_index', true);
        $this->options['log_after_index'] = self::setOption($options, 'log_after_index', true);

        return $this->options;
    }

    /**
     * Устанавливает кастомную функцию-мессенджер, которая выводит сообщения в консоль, например, так:
     *
     * setConsoleMessenger([ \Arris\Toolkit\CLIConsole::class, "say"]);
     *
     * @param callable $messenger
     * @return void
     */
    public function setConsoleMessenger(callable $messenger): void
    {
        $this->messenger = $messenger;
    }

    /**
     * Перестраивает RT-индекс
     *
     * @param string $mysql_table               -- SQL-таблица исходник
     * @param string $searchd_index              -- имя индекса (таблицы)
     * @param Closure $make_updated_set_method    -- замыкание, анонимная функция, преобразующая исходный набор данных в то, что вставляется в индекс
     * @param string $condition                 -- условие выборки из исходной таблицы (без WHERE !!!)
     *
     * @param bool $mva_columns_used                     -- используются ли MultiValued-атрибуты в наборе данных?
     * @param array $mva_indexes_list           -- список MVA-индексов, значения которых не нужно биндить через плейсхолдеры
     *
     * @return int                              -- количество обновленных записей в индексе
     */
    public function rebuildAbstractIndex(string $mysql_table, string $searchd_index, Closure $make_updated_set_method, string $condition = '', bool $mva_columns_used = false, array $mva_indexes_list = []):int
    {
        $mysql_connection = $this->mysql_connection;
        $sphinx_connection = $this->searchd_connection;

        if (empty($searchd_index)) {
            throw PDOWrapperException::create("Requested update of undefined index",[], 1);
        }

        if (empty($mysql_table)) {
            throw PDOWrapperException::create('Not defined source SQL table', [], 1);
        }

        // проверяем, существует ли индекс
        if (! self::RTIndexCheckExist($this->searchd_connection, $searchd_index)) {
            throw PDOWrapperException::create("Index [{$searchd_index}] not present", [],1);
        }


        $chunk_size = $this->options['chunk_length'];
        if (0 === $chunk_size) {
            throw PDOWrapperException::create("Chunk size is ZERO");
        }

        // truncate
        self::RTIndexTruncate($sphinx_connection, $searchd_index);

        // get total count
        $total_count = self::sqlGetRowsCount($mysql_connection, $mysql_table, $condition);
        $total_updated = 0;

        if ($this->options['log_before_index']) {
            call_user_func_array($this->messenger, [ "<font color='yellow'>[{$searchd_index}]</font> index : ", false ]);
        }

        if ($this->options['log_total_rows_found']) {
            call_user_func_array($this->messenger, [
                "<font color='green'>{$total_count}</font> elements found for rebuild."
            ]);
        }

        // iterate chunks (ASC)
        // для обратной итерации (DESC) надо писать do-while цикл?

        for ($i = 0; $i < ceil($total_count / $chunk_size); $i++) {
            $offset = $i * $chunk_size;

            if ($this->options['log_before_chunk']) {
                call_user_func_array($this->messenger, [
                    "Rebuilding elements from <font color='green'>{$offset}</font>, <font color='yellow'>{$chunk_size}</font> count... ",
                    false
                ]);
            }

            $query_chunk_data = "SELECT * FROM {$mysql_table} ";
            $query_chunk_data.= $condition != '' ? " WHERE {$condition} " : '';
            $query_chunk_data.= "ORDER BY id DESC LIMIT {$offset}, {$chunk_size} ";

            $sth = $mysql_connection->query($query_chunk_data);

            // iterate inside chunk
            while ($item = $sth->fetch()) {
                if ($this->options['log_rows_inside_chunk']) {
                    call_user_func_array($this->messenger, [
                        "{$mysql_table}: {$item['id']}"
                    ]);
                }

                $update_set = $make_updated_set_method($item);

                if ($mva_columns_used) {
                    list($update_query, $update_set) = self::buildReplaceQueryMVA($searchd_index, $update_set, $mva_indexes_list);
                } else {
                    $update_query = self::buildReplaceQuery($searchd_index, $update_set);
                }

                $update_statement = $sphinx_connection->prepare($update_query);
                $update_statement->execute($update_set);
                $total_updated++;
            } // while

            $breakline_after_chunk = !$this->options['sleep_after_chunk'];

            if ($this->options['log_after_chunk']) {
                call_user_func_array($this->messenger, [
                    "Updated RT-index <font color='yellow'>{$searchd_index}</font>.",
                    $breakline_after_chunk
                ]);
            } else {
                call_user_func_array($this->messenger, [
                    "<strong>Ok</strong>",
                    $breakline_after_chunk
                ]);
            }

            if ($this->options['sleep_after_chunk']) {
                call_user_func_array($this->messenger, [
                    "ZZZZzzz for {$this->options['sleep_time']} second(s)... ",
                    false
                ]);
                sleep($this->options['sleep_time']);
                call_user_func_array($this->messenger, [
                    "I woke up!"
                ]);
            }
        } // for
        if ($this->options['log_after_index']) {
            call_user_func_array($this->messenger, [
                "Total updated <strong>{$total_updated}</strong> elements for <font color='yellow'>{$searchd_index}</font> RT-index. <br>"
            ]);
        }

        return $total_updated;
    } // rebuildAbstractIndex

    /**
     * Проверяет наличие индекса в системе
     * (похоже, не используется?)
     *
     * @param string $index
     * @return bool
     */
    public function checkIndexExist(string $index):bool
    {
        $index_definition = $this->searchd_connection->query("SHOW TABLES LIKE '{$index}' ")->fetchAll();

        return count($index_definition) > 0;
    }

    /* ==== PRIVATE STATIC METHODS ==== */

    /**
     * Применять как:
     *
     * list($update_query, $newdataset) = BuildReplaceQueryMVA($table, $original_dataset, $mva_attributes_list);
     * $update_statement = $sphinx->prepare($update_query);
     * $update_statement->execute($newdataset);
     *
     *
     * @param string $table             -- имя таблицы
     * @param array $dataset            -- сет данных.
     * @param array $mva_attributes     -- массив с именами ключей MVA-атрибутов (они вставятся как значения, а не как placeholder-ы)
     * @return array                    -- возвращает массив с двумя значениями. Первый ключ - запрос, сет данных, очищенный от MVA-атрибутов.
     */
    private static function buildReplaceQueryMVA(string $table, array $dataset, array $mva_attributes):array
    {
        $query = "REPLACE INTO {$table} (";

        $dataset_keys = array_keys($dataset);

        $query .= implode(', ', array_map(function ($i){
            return "{$i}";
        }, $dataset_keys));

        $query .= " ) VALUES ( ";

        $query .= implode(', ', array_map(function ($i) use ($mva_attributes, $dataset){
            return in_array($i, $mva_attributes) ? "({$dataset[$i]})" : ":{$i}";
        }, $dataset_keys));

        $query .= " ) ";

        $new_dataset = array_filter($dataset, function ($value, $key) use ($mva_attributes) {
            return !in_array($key, $mva_attributes);
        }, ARRAY_FILTER_USE_BOTH);

        return [
            $query, $new_dataset
        ];
    } // BuildReplaceQueryMVA

    /**
     * @param string $table
     * @param array $dataset
     * @return string
     */
    private static function buildReplaceQuery(string $table, array $dataset):string
    {
        $dataset_keys = array_keys($dataset);

        $query = "REPLACE INTO {$table} (";

        $query.= implode(', ', array_map(function ($i){
            return "{$i}";
        }, $dataset_keys));

        $query.= " ) VALUES ( ";

        $query.= implode(', ', array_map(function ($i){
            return ":{$i}";
        }, $dataset_keys));

        $query.= " ) ";

        return $query;
    }

    /**
     *
     * @param array $options
     * @param null $key
     * @param null $default_value
     * @return mixed|null
     */
    private static function setOption(array $options = [], $key = null, $default_value = null)
    {
        if (!is_array($options)) {
            return $default_value;
        }

        if (is_null($key)) {
            return $default_value;
        }

        return array_key_exists($key, $options) ? $options[ $key ] : $default_value;
    }

    private static function RTIndexCheckExist($connection, string $index)
    {
        if (empty($index)) {
            return 0;
        }

        $index_definition = $connection->query("SHOW TABLES LIKE '{$index}' ")->fetchAll();

        return count($index_definition) > 0;
    }

    /**
     * @param $connection
     * @param $index
     * @param bool $reconfigure
     * @return void
     */
    private static function RTIndexTruncate($connection, $index, bool $reconfigure = true): void
    {
        $with = $reconfigure ? 'WITH RECONFIGURE' : '';
        (bool)$connection->query("TRUNCATE RTINDEX {$index} {$with}");
    }

    /**
     * Список элементов в таблице по условию
     *
     * @param $pdo
     * @param string $table
     * @param string $condition
     * @return int
     */
    private static function sqlGetRowsCount($pdo, string $table = '', string $condition = ''): int
    {
        if (empty($table)) {
            return 0;
        }

        $query = "SELECT COUNT(*) AS cnt FROM {$table}";
        if ($condition != '') {
            $query .= " WHERE {$condition}";
        }

        return $pdo->query($query)->fetchColumn() ?? 0;
    }
}

# -eof- #