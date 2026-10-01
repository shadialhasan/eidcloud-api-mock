<?php

declare(strict_types=1);

namespace EidCloud\ApiMock;

use EidCloud\ApiMock\Parser\OpenApiParser;
use EidCloud\ApiMock\Generator\PayloadFaker;
use EidCloud\ApiMock\Storage\MemoryStore;
use EidCloud\ApiMock\Simulator\ChaosSimulator;
use InvalidArgumentException;

/**
 * Universal, schema-driven API Mock Server and sandbox engine.
 */
class MockServer
{
    private OpenApiParser $parser;
    private MemoryStore $store;
    private ChaosSimulator $chaos;
    private bool $seedDataOnStart = true;

    public function __construct(
        OpenApiParser|string|array $spec,
        ?MemoryStore $store = null,
        ?ChaosSimulator $chaos = null
    ) {
        $this->parser = $spec instanceof OpenApiParser ? $spec : new OpenApiParser($spec);
        $this->store = $store ?? new MemoryStore();
        $this->chaos = $chaos ?? new ChaosSimulator();

        if ($this->seedDataOnStart) {
            $this->seedInitialStore();
        }
    }

    public function getParser(): OpenApiParser
    {
        return $this->parser;
    }

    public function getStore(): MemoryStore
    {
        return $this->store;
    }

    public function getChaosSimulator(): ChaosSimulator
    {
        return $this->chaos;
    }

    /**
     * Seeds initial records into MemoryStore for known collections.
     */
    private function seedInitialStore(): void
    {
        foreach ($this->parser->getEndpoints() as $ep) {
            if ($ep['method'] === 'GET' && !str_contains($ep['path'], '{')) {
                $collection = MemoryStore::extractCollectionName($ep['path']);
                if (!$this->store->hasCollection($collection)) {
                    // Try finding schema for 200/201 response
                    $respDef = $ep['responses']['200'] ?? $ep['responses']['201'] ?? null;
                    if ($respDef && !empty($respDef['schema'])) {
                        $schema = $respDef['schema'];
                        if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
                            for ($i = 1; $i <= 3; $i++) {
                                $item = PayloadFaker::generate($schema['items']);
                                if (is_array($item)) {
                                    $item['id'] = $item['id'] ?? $i;
                                    $this->store->set($collection, $item['id'], $item);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Handles an incoming HTTP request and returns an array with [statusCode, headers, body].
     *
     * @param string $method GET, POST, PUT, DELETE, etc.
     * @param string $uri Request URI including path and query string.
     * @param array $headers Incoming headers.
     * @param array|string|null $body Request payload.
     * @param bool $applyRealDelay If true, usleep will be triggered.
     * @return array{statusCode: int, headers: array<string, string>, body: mixed}
     */
    public function handle(
        string $method,
        string $uri,
        array $headers = [],
        array|string|null $body = null,
        bool $applyRealDelay = true
    ): array {
        // 1. Simulate Latency
        $actualDelay = $this->chaos->applyDelay($applyRealDelay);

        // 2. Check for Chaos Error
        $chaosError = $this->chaos->maybeTriggerChaosError();
        if ($chaosError !== null) {
            $chaosError['headers']['X-Mock-Delay-Ms'] = (string) $actualDelay;
            return $chaosError;
        }

        // 3. Match Route in OpenAPI Spec
        $parsedUrl = parse_url($uri);
        $path = $parsedUrl['path'] ?? '/';
        $match = $this->parser->matchEndpoint($method, $path);

        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'X-Powered-By' => 'EidCloud-ApiMock/1.0',
            'X-Mock-Engine' => 'PHP8-Pure',
            'X-Mock-Delay-Ms' => (string) $actualDelay
        ];

        if ($match === null) {
            return [
                'statusCode' => 404,
                'headers' => $defaultHeaders,
                'body' => [
                    'error' => 'RouteNotFound',
                    'message' => "No matching endpoint for {$method} {$path} in provided OpenAPI spec",
                    'status' => 404
                ]
            ];
        }

        $endpoint = $match['endpoint'];
        $pathParams = $match['pathParams'];
        $collection = MemoryStore::extractCollectionName($endpoint['path']);

        // Decode incoming body if JSON string
        $parsedBody = [];
        if (is_string($body)) {
            $parsedBody = json_decode($body, true) ?? [];
        } elseif (is_array($body)) {
            $parsedBody = $body;
        }

        // 4. Handle Stateful REST semantics
        $idParam = $pathParams['id'] ?? $pathParams['userId'] ?? reset($pathParams) ?: null;

        // POST /collection -> create resource
        if ($method === 'POST') {
            $created = $this->store->create($collection, $parsedBody);
            return [
                'statusCode' => 201,
                'headers' => array_merge($defaultHeaders, [
                    'Location' => '/' . $collection . '/' . $created['id']
                ]),
                'body' => $created
            ];
        }

        // GET /collection/:id -> get specific resource
        if ($method === 'GET' && $idParam !== null) {
            $item = $this->store->find($collection, $idParam);
            if ($item !== null) {
                return [
                    'statusCode' => 200,
                    'headers' => $defaultHeaders,
                    'body' => $item
                ];
            }

            // Fallback: If not found in store, generate realistic response matching schema
            $targetResp = $endpoint['responses']['200'] ?? reset($endpoint['responses']) ?: null;
            if ($targetResp && !empty($targetResp['schema'])) {
                $generated = PayloadFaker::generate($targetResp['schema']);
                if (is_array($generated)) {
                    $generated['id'] = is_numeric($idParam) ? (int) $idParam : $idParam;
                    $this->store->set($collection, $idParam, $generated);
                }
                return [
                    'statusCode' => 200,
                    'headers' => $defaultHeaders,
                    'body' => $generated
                ];
            }

            return [
                'statusCode' => 404,
                'headers' => $defaultHeaders,
                'body' => [
                    'error' => 'NotFound',
                    'message' => "Resource with id '{$idParam}' not found in {$collection}",
                    'status' => 404
                ]
            ];
        }

        // GET /collection -> list resources
        if ($method === 'GET' && empty($pathParams)) {
            $items = $this->store->all($collection);
            if (!empty($items)) {
                return [
                    'statusCode' => 200,
                    'headers' => $defaultHeaders,
                    'body' => $items
                ];
            }
        }

        // PUT / PATCH /collection/:id -> update resource
        if (($method === 'PUT' || $method === 'PATCH') && $idParam !== null) {
            $updated = $this->store->update($collection, $idParam, $parsedBody);
            return [
                'statusCode' => 200,
                'headers' => $defaultHeaders,
                'body' => $updated
            ];
        }

        // DELETE /collection/:id -> delete resource
        if ($method === 'DELETE' && $idParam !== null) {
            $deleted = $this->store->delete($collection, $idParam);
            return [
                'statusCode' => $deleted ? 200 : 404,
                'headers' => $defaultHeaders,
                'body' => [
                    'success' => $deleted,
                    'id' => $idParam,
                    'message' => $deleted ? "Deleted resource {$idParam}" : "Resource {$idParam} not found"
                ]
            ];
        }

        // 5. Default Schema-driven generation for matched endpoints
        $bestStatus = 200;
        foreach (['200', '201', '204', 'default'] as $candidate) {
            if (isset($endpoint['responses'][$candidate])) {
                $bestStatus = $candidate === 'default' ? 200 : (int) $candidate;
                break;
            }
        }

        $respConfig = $endpoint['responses'][(string) $bestStatus] ?? reset($endpoint['responses']) ?: null;
        $schema = $respConfig['schema'] ?? null;

        $generatedBody = $schema ? PayloadFaker::generate($schema) : ['status' => 'ok'];

        return [
            'statusCode' => $bestStatus,
            'headers' => $defaultHeaders,
            'body' => $generatedBody
        ];
    }

    /**
     * Generates a complete mock data snapshot for all schema endpoints.
     */
    public function generateStaticDataset(): array
    {
        $dataset = [];
        foreach ($this->parser->getEndpoints() as $ep) {
            $key = $ep['method'] . ' ' . $ep['path'];
            $targetResp = $ep['responses']['200'] ?? $ep['responses']['201'] ?? reset($ep['responses']);
            if ($targetResp && !empty($targetResp['schema'])) {
                $dataset[$key] = PayloadFaker::generate($targetResp['schema']);
            } else {
                $dataset[$key] = ['status' => 'mocked', 'method' => $ep['method'], 'path' => $ep['path']];
            }
        }
        return $dataset;
    }
}
