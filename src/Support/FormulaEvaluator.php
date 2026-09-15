<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Throwable;

class FormulaEvaluator
{
    /**
     * Safely evaluate a mathematical formula substituting model attributes.
     * Supports basic arithmetic (+, -, *, /), grouping (), and ternary (? :).
     *
     * Example: "total_credit > 0 ? (total_credit - total_debit) / total_credit * 100 : 0"
     */
    public static function evaluate(string $expression, Model $model): mixed
    {
        // Extract all alphanumeric variable names (e.g., total_credit, net_change)
        // and replace them with their actual values from the model.
        $parsedExpression = preg_replace_callback('/[a-zA-Z_][a-zA-Z0-9_]*/', function ($matches) use ($model) {
            $attribute = $matches[0];
            $value = $model->getAttribute($attribute) ?? 0;

            return is_numeric($value) ? (string) $value : '0';
        }, $expression);

        if ($parsedExpression === null) {
            throw new InvalidArgumentException("Failed to parse expression: {$expression}");
        }

        // Extremely rudimentary parsing logic for basic mathematical operations.
        // For production, a robust parser (like symfony/expression-language) should be used.
        // For now, we will handle a simple ternary split or basic math via a safe fallback.

        // Check for ternary operator
        if (str_contains($parsedExpression, '?') && str_contains($parsedExpression, ':')) {
            return self::evaluateTernary($parsedExpression);
        }

        return self::evaluateMath($parsedExpression);
    }

    private static function evaluateTernary(string $expression): mixed
    {
        $parts = explode('?', $expression, 2);
        $condition = trim($parts[0]);

        $results = explode(':', $parts[1], 2);
        $truePart = trim($results[0]);
        $falsePart = trim($results[1]);

        return self::evaluateCondition($condition)
            ? self::evaluateMath($truePart)
            : self::evaluateMath($falsePart);
    }

    private static function evaluateCondition(string $condition): bool
    {
        // Support ">", "<", "==", ">=", "<="
        $operators = ['>=', '<=', '==', '>', '<', '!='];

        foreach ($operators as $op) {
            if (str_contains($condition, $op)) {
                $parts = explode($op, $condition, 2);
                $left = self::evaluateMath(trim($parts[0]));
                $right = self::evaluateMath(trim($parts[1]));

                if ($op === '>') {
                    return $left > $right;
                }
                if ($op === '<') {
                    return $left < $right;
                }
                if ($op === '>=') {
                    return $left >= $right;
                }
                if ($op === '<=') {
                    return $left <= $right;
                }
                if ($op === '==') {
                    return $left === $right;
                }

                return $left !== $right;
            }
        }

        return (bool) self::evaluateMath($condition);
    }

    private static function evaluateMath(string $expression): float
    {
        $expression = preg_replace('/[^0-9\.\+\-\*\/\(\) ]/', '', $expression);

        if (empty($expression)) {
            return 0.0;
        }

        try {
            // Using a simple safe eval for math only, as we sanitized out all letters/functions.
            // All variables were replaced by literal numbers.
            $result = @eval('return '.$expression.';');

            if ($result === false || is_infinite($result) || is_nan($result)) {
                return 0.0;
            }

            return (float) $result;
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
