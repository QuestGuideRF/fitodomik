<?php
function fitodomikParseEnvValue(string $value): string
{
    if ($value === '') {
        return '';
    }
    $quote = $value[0];
    if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
        $value = substr($value, 1, -1);
        if ($quote === '"') {
            $value = str_replace(['\n', '\r', '\t', '\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
        }
        return $value;
    }
    $comment = strpos($value, ' #');
    return $comment === false ? $value : rtrim(substr($value, 0, $comment));
}
function fitodomikIsValidEnvKey(string $key): bool
{
    return preg_match('/^[A-Za-z_]\w*$/', $key) === 1;
}
function fitodomikNormalizeEnvValue(string $value): string
{
    return fitodomikParseEnvValue($value);
}
function fitodomikParseEnvLine(string $line)
{
    $line = trim($line);
    $pair = null;
    if ($line !== '' && $line[0] !== '#') {
        if (strpos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }
        $separator = strpos($line, '=');
        if ($separator !== false) {
            $key = trim(substr($line, 0, $separator));
            if (fitodomikIsValidEnvKey($key)) {
                $value = fitodomikNormalizeEnvValue(trim(substr($line, $separator + 1)));
                $pair = [$key, $value];
            }
        }
    }
    return $pair;
}
function fitodomikParseEnvLines(array $lines): array
{
    $values = [];
    foreach ($lines as $line) {
        $pair = fitodomikParseEnvLine($line);
        if ($pair !== null) {
            $values[$pair[0]] = $pair[1];
        }
    }
    return $values;
}
function fitodomikPublishEnvValues(array $values): void
{
    foreach ($values as $key => $value) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}
function fitodomikPublishEnvValue(array &$values, string $key, string $value): void
{
    $values[$key] = $value;
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}
function fitodomikReadEnvFile(string $path, bool $publishValues = true): array
{
    $values = [];
    if (!is_file($path) || !is_readable($path)) {
        return $values;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $values;
    }
    foreach ($lines as $line) {
        $pair = fitodomikParseEnvLine($line);
        if ($pair !== null) {
            if ($publishValues) {
                fitodomikPublishEnvValue($values, $pair[0], $pair[1]);
            } else {
                $values[$pair[0]] = $pair[1];
            }
        }
    }
    return $values;
}
