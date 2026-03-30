<?php

namespace App\Support;

trait InterpretsOperationResult
{
    protected function isSuccessfulResult($result): bool
    {
        if (is_array($result) && array_key_exists('success', $result)) {
            return (bool) $result['success'];
        }

        if (is_bool($result)) {
            return $result;
        }

        return $result !== null;
    }
}
