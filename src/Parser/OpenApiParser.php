<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Parser;

use InvalidArgumentException;
use RuntimeException;

/**
 * Parses and normalizes OpenAPI 3.0, OpenAPI 3.1, and Swagger 2.0 specifications.
 */
class OpenApiParser
{
    private array $rawSpec = [];
    private array $endpoints = [];
    private string $version = '3.0.0';

    public function __construct(string|array $specOrPath)
    {
        if (is_string($specOrPath)) {
            if (file_exists($specOrPath)) {
                $content = file_get_contents($specOrPath);
                if ($content === false) {
                    throw new RuntimeException("Failed to read spec file: {$specOrPath}");
                }
                $this->rawSpec = SimpleYamlParser::parse($content);
            } else {
                $this->rawSpec = SimpleYamlParser::parse($specOrPath);
            }
        } else {
            $this->rawSpec = $specOrPath;
        }

        $this->detectVersion();
        $this->resolveReferences();
        $this->parseEndpoints();
    }

    public static function fromFile(string $filePath): self
    {
        return new self($filePath);
    }

    public static function fromString(string $content): self
    {
        return new self($content);
    }

    public function getRawSpec(): array
    {
        return $this->rawSpec;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getEndpoints(): array
    {
        return $this->endpoints;
    }

    private function detectVersion(): void
    {
        if (isset($this->rawSpec['openapi'])) {
            $this->version = (string) $this->rawSpec['openapi'];
        } elseif (isset($this->rawSpec['swagger'])) {
            $this->version = (string) $this->rawSpec['swagger'];
        } else {
            $this->version = '3.0.0';
        }
    }

    /**
     * Resolves local $ref schemas within the document recursively.
     */
    private function resolveReferences(): void
    {
        $this->rawSpec = $this->resolveRefNode($this->rawSpec, $this->rawSpec, 0);
    }

    private function resolveRefNode(mixed $node, array $root, int $depth): mixed
    {
        if ($depth > 20) {
            // Guard against cyclic references
            return $node;
        }

        if (!is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $refPath = $node['$ref'];
            $resolved = $this->lookupRef($refPath, $root);
            if ($resolved !== null && is_array($resolved)) {
                unset($node['$ref']);
                $merged = array_merge($resolved, $node);
                return $this->resolveRefNode($merged, $root, $depth + 1);
            }
        }

        foreach ($node as $k => $v) {
            $node[$k] = $this->resolveRefNode($v, $root, $depth + 1);
        }

        return $node;
    }

    private function lookupRef(string $ref, array $root): ?array
    {
        if (!str_starts_with($ref, '#/')) {
            return null; // External refs not handled in pure single-file mode
        }

        $parts = explode('/', substr($ref, 2));
        $current = $root;
        foreach ($parts as $part) {
            // Decode ~1 and ~0 per JSON Pointer RFC 6901
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return is_array($current) ? $current : null;
    }

    private function parseEndpoints(): void
    {
        $this->endpoints = [];
        $paths = $this->rawSpec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }

            // Path-level parameters
            $pathParams = $methods['parameters'] ?? [];

            foreach ($methods as $method => $operation) {
                $methodUpper = strtoupper($method);
                if (!in_array($methodUpper, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], true)) {
                    continue;
                }

                if (!is_array($operation)) {
                    continue;
                }

                $mergedParams = array_merge($pathParams, $operation['parameters'] ?? []);
                
                // Extract response schemas
                $responses = [];
                foreach ($operation['responses'] ?? [] as $statusCode => $respDef) {
                    $responses[$statusCode] = $this->extractResponseSchema($respDef);
                }

                // Extract request body schema
                $requestBodySchema = $this->extractRequestBodySchema($operation);

                $endpoint = [
                    'path' => $path,
                    'method' => $methodUpper,
                    'operationId' => $operation['operationId'] ?? null,
                    'summary' => $operation['summary'] ?? '',
                    'description' => $operation['description'] ?? '',
                    'parameters' => $mergedParams,
                    'responses' => $responses,
                    'requestBody' => $requestBodySchema,
                    'tags' => $operation['tags'] ?? []
                ];

                $this->endpoints[] = $endpoint;
            }
        }
    }

    private function extractResponseSchema(array $respDef): array
    {
        $schema = null;
        $description = $respDef['description'] ?? '';

        // OpenAPI 3.0 / 3.1: content -> application/json -> schema
        if (isset($respDef['content']) && is_array($respDef['content'])) {
            foreach ($respDef['content'] as $contentType => $mediaType) {
                if (isset($mediaType['schema'])) {
                    $schema = $mediaType['schema'];
                    break;
                }
            }
        }
        // Swagger 2.0: schema directly under response
        elseif (isset($respDef['schema'])) {
            $schema = $respDef['schema'];
        }

        return [
            'description' => $description,
            'schema' => $schema,
            'headers' => $respDef['headers'] ?? []
        ];
    }

    private function extractRequestBodySchema(array $operation): ?array
    {
        // OpenAPI 3.x
        if (isset($operation['requestBody']['content']) && is_array($operation['requestBody']['content'])) {
            foreach ($operation['requestBody']['content'] as $contentType => $mediaType) {
                if (isset($mediaType['schema'])) {
                    return $mediaType['schema'];
                }
            }
        }

        // Swagger 2.0 parameters with in: body
        if (isset($operation['parameters']) && is_array($operation['parameters'])) {
            foreach ($operation['parameters'] as $param) {
                if (($param['in'] ?? '') === 'body' && isset($param['schema'])) {
                    return $param['schema'];
                }
            }
        }

        return null;
    }

    /**
     * Matches a given HTTP method and URI path against the parsed endpoints.
     */
    public function matchEndpoint(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $cleanPath = '/' . trim(parse_url($path, PHP_URL_PATH) ?? '/', '/');
        if ($cleanPath === '//') $cleanPath = '/';

        foreach ($this->endpoints as $ep) {
            if ($ep['method'] !== $method) {
                continue;
            }

            $pattern = $this->pathPatternToRegex($ep['path']);
            if (preg_match($pattern, $cleanPath, $matches)) {
                $pathParams = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) {
                        $pathParams[$k] = $v;
                    }
                }
                return [
                    'endpoint' => $ep,
                    'pathParams' => $pathParams
                ];
            }
        }

        return null;
    }

    private function pathPatternToRegex(string $pattern): string
    {
        // Replace {param} or :param with named capture (?P<param>[^/]+)
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $regex = preg_replace_callback('/:([a-zA-Z0-9_]+)/', function ($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $regex);

        return '#^' . $regex . '$#';
    }
}
