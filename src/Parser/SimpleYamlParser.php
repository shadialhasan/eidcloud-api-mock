<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Parser;

use RuntimeException;
use InvalidArgumentException;

/**
 * Lightweight pure-PHP parser for YAML and JSON OpenAPI / Swagger specs without external dependencies.
 */
class SimpleYamlParser
{
    /**
     * Parses a YAML or JSON string into a PHP array.
     */
    public static function parse(string $yaml): array
    {
        $trimmed = trim($yaml);
        if ($trimmed === '') {
            return [];
        }

        // If it's JSON, decode directly
        if (($trimmed[0] === '{' && substr($trimmed, -1) === '}') ||
            ($trimmed[0] === '[' && substr($trimmed, -1) === ']')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Clean lines & remove comments/blank lines
        $rawLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));
        $lines = [];
        foreach ($rawLines as $line) {
            // Check for full line comments or blank
            $trimmedLine = trim($line);
            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                continue;
            }
            // Strip trailing inline comments if not inside quotes
            $lineClean = self::stripInlineComment($line);
            if (trim($lineClean) === '') {
                continue;
            }
            $indent = strlen($lineClean) - strlen(ltrim($lineClean, ' '));
            $lines[] = [
                'indent' => $indent,
                'content' => trim($lineClean),
                'raw' => $lineClean
            ];
        }

        if (empty($lines)) {
            return [];
        }

        $index = 0;
        return self::parseBlock($lines, $index, 0);
    }

    private static function stripInlineComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($char === '#' && !$inSingle && !$inDouble) {
                // Preceded by whitespace or start of line
                if ($i === 0 || ctype_space($line[$i - 1])) {
                    return substr($line, 0, $i);
                }
            }
        }
        return $line;
    }

    private static function parseBlock(array $lines, int &$index, int $currentIndent): mixed
    {
        $result = [];
        $isList = null;

        while ($index < count($lines)) {
            $line = $lines[$index];
            $indent = $line['indent'];
            $content = $line['content'];

            if ($indent < $currentIndent) {
                break;
            }

            if ($indent > $currentIndent && !empty($result)) {
                // If it's an unexpected deeper indent, skip or break
                break;
            }

            if (str_starts_with($content, '- ')) {
                if ($isList === null) {
                    $isList = true;
                    $result = [];
                }

                $itemContent = trim(substr($content, 2));
                $index++;

                if ($itemContent === '') {
                    // Nested block
                    if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
                        $val = self::parseBlock($lines, $index, $lines[$index]['indent']);
                        $result[] = $val;
                    } else {
                        $result[] = null;
                    }
                } elseif (str_contains($itemContent, ': ') || str_ends_with($itemContent, ':')) {
                    // Inline key-value inside array item, e.g. - name: test
                    // Treat as beginning of a sub-map
                    $subLines = [
                        [
                            'indent' => $indent + 2,
                            'content' => $itemContent,
                            'raw' => str_repeat(' ', $indent + 2) . $itemContent
                        ]
                    ];
                    while ($index < count($lines) && $lines[$index]['indent'] > $indent) {
                        $subLines[] = $lines[$index];
                        $index++;
                    }
                    $subIdx = 0;
                    $result[] = self::parseBlock($subLines, $subIdx, $indent + 2);
                } else {
                    $result[] = self::parseScalar($itemContent);
                }
            } else {
                if ($isList === null) {
                    $isList = false;
                }

                $colonPos = strpos($content, ':');
                if ($colonPos === false) {
                    // Lone scalar
                    $index++;
                    return self::parseScalar($content);
                }

                $key = trim(substr($content, 0, $colonPos));
                $key = self::unquote($key);
                $rest = trim(substr($content, $colonPos + 1));

                $index++;

                if ($rest === '' || $rest === '|' || $rest === '>') {
                    // Next lines might be multiline or child object/list
                    if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
                        $nextIndent = $lines[$index]['indent'];
                        if ($rest === '|' || $rest === '>') {
                            // Multiline string
                            $strLines = [];
                            while ($index < count($lines) && $lines[$index]['indent'] >= $nextIndent) {
                                $strLines[] = $lines[$index]['content'];
                                $index++;
                            }
                            $result[$key] = implode("\n", $strLines);
                        } else {
                            $result[$key] = self::parseBlock($lines, $index, $nextIndent);
                        }
                    } else {
                        $result[$key] = null;
                    }
                } else {
                    $result[$key] = self::parseScalar($rest);
                }
            }
        }

        return $result;
    }

    public static function parseScalar(string $val): mixed
    {
        $val = trim($val);
        if ($val === '' || $val === '~' || $val === 'null' || $val === 'NULL') {
            return null;
        }
        if ($val === 'true' || $val === 'TRUE' || $val === 'True') {
            return true;
        }
        if ($val === 'false' || $val === 'FALSE' || $val === 'False') {
            return false;
        }
        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float) $val : (int) $val;
        }
        // Inline array: [a, b, c]
        if (str_starts_with($val, '[') && str_ends_with($val, ']')) {
            $inner = trim(substr($val, 1, -1));
            if ($inner === '') return [];
            $items = explode(',', $inner);
            return array_map(fn($i) => self::parseScalar(trim($i)), $items);
        }
        // Inline map: {a: 1, b: 2}
        if (str_starts_with($val, '{') && str_ends_with($val, '}')) {
            $inner = trim(substr($val, 1, -1));
            if ($inner === '') return [];
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return self::unquote($val);
    }

    private static function unquote(string $str): string
    {
        $str = trim($str);
        $len = strlen($str);
        if ($len >= 2) {
            if (($str[0] === '"' && $str[$len - 1] === '"') ||
                ($str[0] === "'" && $str[$len - 1] === "'")) {
                $sub = substr($str, 1, -1);
                return str_replace(['\\"', "\\'"], ['"', "'"], $sub);
            }
        }
        return $str;
    }
}
