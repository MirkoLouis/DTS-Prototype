<?php

namespace App\Core;

class Validator
{
    /**
     * Validates an array of data against a set of rules.
     * 
     * @param array $data The data to validate (usually $_POST)
     * @param array $rules Associative array of field => 'required|email|min:5'
     * @param string $redirectUrl URL to redirect to on failure
     * @return array Validated and sanitized data
     */
    public static function validate(array $data, array $rules, string $redirectUrl): array
    {
        $errors = [];
        $validatedData = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $ruleSet = explode('|', $ruleString);

            foreach ($ruleSet as $rule) {
                if ($rule === 'required' && (is_null($value) || trim((string)$value) === '')) {
                    $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
                    break;
                }
                if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be a valid email.";
                }
                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (strlen((string)$value) < $min) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at least {$min} characters.";
                    }
                }
            }
            
            // Sanitize standard text inputs to prevent XSS (basic)
            if (is_string($value)) {
                $validatedData[$field] = trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            } else {
                $validatedData[$field] = $value;
            }
        }

        if (!empty($errors)) {
            $_SESSION['error'] = implode("<br>", $errors);
            header("Location: " . $redirectUrl);
            exit;
        }

        return $validatedData;
    }
}
