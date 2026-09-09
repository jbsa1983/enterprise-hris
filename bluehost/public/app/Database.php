<?php
// PDO/MySQL data access — thin helpers over prepared statements.

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = Config::all();
            $dsn = "mysql:host={$cfg['db_host']};port={$cfg['db_port']};dbname={$cfg['db_name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Run a query, return all rows. */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Run a query, return the first row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Run a query, return a single scalar value. */
    public static function scalar(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** Execute an insert/update/delete; return affected rows. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Insert a row from an associative array; return last insert id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
        $st   = self::pdo()->prepare($sql);
        foreach ($data as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->execute();
        return (int) self::pdo()->lastInsertId();
    }

    /** Update a table by id from an associative array. */
    public static function update(string $table, int $id, array $data): void
    {
        if (!$data) return;
        $sets = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        $st = self::pdo()->prepare("UPDATE `$table` SET $sets WHERE id = :__id");
        foreach ($data as $k => $v) $st->bindValue(':' . $k, $v);
        $st->bindValue(':__id', $id, PDO::PARAM_INT);
        $st->execute();
    }
}
