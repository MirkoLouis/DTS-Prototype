<?php

namespace App\Common\Cache;

use App\Common\Database\Database;

class Cache
{
    // Prefix of every key whose value is computed from daily_department_metrics; the backfill clears exactly these keys
    public const METRICS_PREFIX = 'metrics:';

    /**
     * Get a value from the cache.
     */
    public static function get($key)
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT value, expiration FROM cache WHERE `key` = :key", [':key' => $key]);
        $result = $stmt->fetch();
        
        if ($result) {
            if (time() > (int)$result['expiration']) {
                self::forget($key);
                return null;
            }
            return json_decode($result['value'], true);
        }
        return null;
    }

    /**
     * Store a value in the cache.
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttlSeconds Time to live in seconds
     */
    public static function put($key, $value, $ttlSeconds)
    {
        $db = Database::getInstance();
        $expiration = time() + $ttlSeconds;
        $encodedValue = json_encode($value);
        
        $db->query("REPLACE INTO cache (`key`, `value`, `expiration`) VALUES (:key, :val, :exp)", [
            ':key' => $key,
            ':val' => $encodedValue,
            ':exp' => $expiration
        ]);
    }

    /**
     * Remove a value from the cache.
     */
    public static function forget($key)
    {
        $db = Database::getInstance();
        $db->query("DELETE FROM cache WHERE `key` = :key", [':key' => $key]);
    }

    /**
     * Retrieve a value, or cache it if it doesn't exist.
     */
    public static function remember($key, $ttlSeconds, callable $callback)
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::put($key, $value, $ttlSeconds);
        return $value;
    }

    /**
     * Delete every entry that has expired at $now. Returns the number of rows deleted.
     *
     * get() already ignores an expired entry, so this only bounds the table's size; it reads the
     * cache(expiration) index and so touches only the expired range.
     */
    public static function purgeExpired(int $now): int
    {
        // Same boundary as get(): an entry is expired once the clock is past its expiration
        return Database::getInstance()->query("DELETE FROM cache WHERE expiration < :now", [':now' => $now])->rowCount();
    }

    /**
     * Remove every entry whose key starts with $prefix. Returns the number of rows deleted.
     */
    public static function forgetPrefix(string $prefix): int
    {
        // LIKE wildcards in the prefix are escaped so they match literally
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
        $stmt = Database::getInstance()->query('DELETE FROM cache WHERE `key` LIKE :prefix ESCAPE \'\\\\\'', [':prefix' => $escaped . '%']);

        return $stmt->rowCount();
    }

    /**
     * Remove all entries from the cache.
     */
    public static function clear()
    {
        $db = Database::getInstance();
        $db->query("DELETE FROM cache");
    }
}
