<?php

namespace App\Utils;

class Cache
{
    private static function getCacheDir()
    {
        $dir = BASE_PATH . '/cache/data/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function remember($key, $minutes, callable $callback)
    {
        $file = self::getCacheDir() . md5($key) . '.cache';
        
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['expiry']) && $data['expiry'] > time()) {
                return $data['content'];
            }
        }

        $content = $callback();
        
        $cacheData = [
            'expiry' => time() + ($minutes * 60),
            'content' => $content
        ];
        
        file_put_contents($file, json_encode($cacheData));
        return $content;
    }

    public static function clear()
    {
        $dir = self::getCacheDir();
        $files = glob($dir . '*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
