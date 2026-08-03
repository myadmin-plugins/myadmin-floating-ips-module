<?php

declare(strict_types=1);

namespace Detain\MyAdminFloatingIps\Tests\Support;

/**
 * Stands in for the MyAdmin database handle returned by get_module_db().
 *
 * get_module_db() hands back a clone of $GLOBALS['<module>_dbh'], so the queries a
 * handler runs are recorded statically and stay visible to the test that set it up.
 */
final class DbDouble
{
    /**
     * Every SQL statement that reached the database, in order.
     *
     * @var array<int, string>
     */
    public static $queries = [];

    /**
     * Rows the next query() will return.
     *
     * @var array<int, array<string, mixed>>
     */
    public static $nextRows = [];

    /**
     * @var array<string, mixed>
     */
    public $Record = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private $rows = [];

    /**
     * @var int
     */
    private $cursor = 0;

    public static function reset(): void
    {
        self::$queries = [];
        self::$nextRows = [];
    }

    /**
     * Queue the result set the next query() should return.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function willReturnRows(array $rows): void
    {
        self::$nextRows = $rows;
    }

    /**
     * Statements containing the given fragment.
     *
     * @return array<int, string>
     */
    public static function queriesMatching(string $fragment): array
    {
        return array_values(array_filter(self::$queries, static function ($sql) use ($fragment) {
            return strpos($sql, $fragment) !== false;
        }));
    }

    /**
     * @param  string     $sql
     * @param  int|null   $line
     * @param  string|null $file
     * @return bool
     */
    public function query($sql, $line = null, $file = null)
    {
        self::$queries[] = $sql;
        $this->rows = self::$nextRows;
        $this->cursor = 0;
        $this->Record = [];
        return true;
    }

    /**
     * @return int
     */
    public function num_rows()
    {
        return count($this->rows);
    }

    /**
     * @param  int|null $mode
     * @return bool
     */
    public function next_record($mode = null)
    {
        if (!isset($this->rows[$this->cursor])) {
            $this->Record = [];
            return false;
        }
        $this->Record = $this->rows[$this->cursor];
        $this->cursor++;
        return true;
    }

    /**
     * @return void
     */
    public function free()
    {
        $this->rows = [];
        $this->cursor = 0;
    }

    /**
     * @param  string $value
     * @return string
     */
    public function real_escape($value)
    {
        return addslashes((string) $value);
    }
}
