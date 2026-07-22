<?php

if (!function_exists('e')) {
    /**
     * Escape HTML special characters in a string for safe output rendering (XSS mitigation).
     *
     * @param string|null $value
     * @param bool $doubleEncode
     * @return string
     */
    function e(?string $value, bool $doubleEncode = true): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }
}
