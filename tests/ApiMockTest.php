<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Tests;

use EidCloud\ApiMock\MockServer;
use EidCloud\ApiMock\Parser\OpenApiParser;
use EidCloud\ApiMock\Parser\SimpleYamlParser;
use EidCloud\ApiMock\Generator\PayloadFaker;
use EidCloud\ApiMock\Storage\MemoryStore;
use EidCloud\ApiMock\Simulator\ChaosSimulator;
use RuntimeException;

class ApiMockTest
{
    private int $passes = 0;
    private int $fails = 0;

    public function runAll(): void
    {
        $this->testSimpleYamlParser();
        $this->testOpenApiParser();
        $this->testSwaggerParser();
        $this->testPayloadFaker();
        $this->testMemoryStore();
        $this->testChaosSimulator();
        $this->testMockServerFullLifecycle();
        $this->testStatefulCrudOperations();
        $this->testGenerateStaticDataset();

        echo "\n------------------------------------------------------------\n";
        echo sprintf("Test Results: %d Passed, %d Failed\n", $this->passes, $this->fails);
        echo "------------------------------------------------------------\n";

        if ($this->fails > 0) {
            throw new RuntimeException("{$this->fails} test assertions failed!");
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passes++;
            echo "  \033[32m✔\033[0m {$message}\n";
        } else {
            $this->fails++;
            echo "  \033[31m✖\033[0m {$message}\n";
        }
    }

    private function assertEqual(mixed $actual, mixed $expected, string $message): void
    {
        $this->assert($actual === $expected, $message . " [Expected: " . json_encode($expected) . ", Got: " . json_encode($actual) . "]");
    }

    public function testSimpleYamlParser(): void
    {
        echo "\n\033[1;34mTesting SimpleYamlParser...\033[0m\n";

        // JSON string parsing
        $json = '{"name": "EidCloud", "port": 8080, "active": true}';
        $resJson = SimpleYamlParser::parse($json);
        $this->assertEqual($resJson['name'], 'EidCloud', 'Parses JSON object name');
        $this->assertEqual($resJson['port'], 8080, 'Parses JSON numeric port');
        $this->assertEqual($resJson['active'], true, 'Parses JSON boolean value');

        // YAML block parsing
        $yaml = <<<YAML
title: "Mock API"
version: 1.0.0
enabled: true
tags:
  - users
  - payments
server:
  host: 127.0.0.1
  port: 8080
YAML;

        $resYaml = SimpleYamlParser::parse($yaml);
        $this->assertEqual($resYaml['title'], 'Mock API', 'Parses YAML scalar title');
        $this->assertEqual($resYaml['enabled'], true, 'Parses YAML boolean enabled');
        $this->assertEqual(count($resYaml['tags']), 2, 'Parses YAML list items');
        $this->assertEqual($resYaml['server']['port'], 8080, 'Parses nested YAML object');
    }

    public function testOpenApiParser(): void
    {
        echo "\n\033[1;34mTesting OpenApiParser (OpenAPI 3.0 / 3.1)...\033[0m\n";

        $spec = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Sample User Service', 'version' => '1.0.0'],
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                            'name' => ['type' => 'string']
                        ],
                        'required' => ['id', 'email', 'name']
                    ]
                ]
            ],
            'paths' => [
                '/users' => [
                    'get' => [
                        'summary' => 'List users',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/User']
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'post' => [
                        'summary' => 'Create user',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/User']
                                ]
                            ]
                        ],
                        'responses' => [
                            '201' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/User']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/users/{id}' => [
                    'get' => [
                        'summary' => 'Get user by id',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                        ],
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/User']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $parser = new OpenApiParser($spec);
        $this->assertEqual($parser->getVersion(), '3.0.3', 'Detects OpenAPI 3.0.3 version');
        $this->assertEqual(count($parser->getEndpoints()), 3, 'Parses all 3 defined endpoints');

        // Path matching with path parameters
        $match = $parser->matchEndpoint('GET', '/users/42');
        $this->assert($match !== null, 'Matches route /users/42');
        $this->assertEqual($match['pathParams']['id'] ?? '', '42', 'Extracts path parameter {id} as 42');

        // Verify local $ref resolution
        $getEndpoint = $match['endpoint'];
        $respSchema = $getEndpoint['responses']['200']['schema'];
        $this->assert(isset($respSchema['properties']['email']), 'Resolves #/components/schemas/User $ref in response');
    }

    public function testSwaggerParser(): void
    {
        echo "\n\033[1;34mTesting Swagger 2.0 Ingestion...\033[0m\n";

        $swagger = [
            'swagger' => '2.0',
            'info' => ['title' => 'Legacy Swagger API', 'version' => '2.0.0'],
            'paths' => [
                '/health' => [
                    'get' => [
                        'summary' => 'Health check',
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'status' => ['type' => 'string']
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $parser = new OpenApiParser($swagger);
        $this->assertEqual($parser->getVersion(), '2.0', 'Detects Swagger 2.0 version');
        $match = $parser->matchEndpoint('GET', '/health');
        $this->assert($match !== null, 'Matches Swagger 2.0 route /health');
    }

    public function testPayloadFaker(): void
    {
        echo "\n\033[1;34mTesting PayloadFaker Schema Generation...\033[0m\n";

        PayloadFaker::reset();

        $schema = [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string', 'format' => 'uuid'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'website' => ['type' => 'string', 'format' => 'uri'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'roles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ],
                'is_verified' => ['type' => 'boolean']
            ]
        ];

        $data = PayloadFaker::generate($schema);

        $this->assert(is_int($data['user_id']), 'Generates integer user_id');
        $this->assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['uuid']), 'Generates valid UUID string');
        $this->assert(str_contains($data['email'], '@'), 'Generates valid email address');
        $this->assert(str_starts_with($data['website'], 'https://'), 'Generates valid URI format');
        $this->assert(str_contains($data['created_at'], 'T'), 'Generates valid RFC3339 date-time');
        $this->assert(is_array($data['roles']), 'Generates array for roles');
        $this->assert(is_bool($data['is_verified']), 'Generates boolean for is_verified');
    }

    public function testMemoryStore(): void
    {
        echo "\n\033[1;34mTesting MemoryStore Stateful Operations...\033[0m\n";

        $store = new MemoryStore();
        $user1 = $store->create('users', ['name' => 'Alice', 'role' => 'admin']);
        $this->assertEqual($user1['id'], 1, 'Assigns auto-increment ID 1 to first user');

        $found = $store->find('users', 1);
        $this->assert($found !== null && $found['name'] === 'Alice', 'Finds user by ID 1');

        $updated = $store->update('users', 1, ['role' => 'superadmin']);
        $this->assertEqual($updated['role'], 'superadmin', 'Updates existing entity');

        $user2 = $store->create('users', ['name' => 'Bob', 'role' => 'user']);
        $this->assertEqual($user2['id'], 2, 'Assigns ID 2 to second user');
        $this->assertEqual(count($store->all('users')), 2, 'Returns all 2 stored users');

        $deleted = $store->delete('users', 1);
        $this->assert($deleted, 'Deletes user with ID 1');
        $this->assert($store->find('users', 1) === null, 'User 1 is no longer in store');
    }

    public function testChaosSimulator(): void
    {
        echo "\n\033[1;34mTesting ChaosSimulator...\033[0m\n";

        // Latency test without real sleep
        $chaos = new ChaosSimulator(150, 0.0);
        $delay = $chaos->applyDelay(false);
        $this->assert($delay >= 130 && $delay <= 170, 'Calculates delay around 150ms with jitter');

        // Zero error rate
        $this->assert($chaos->maybeTriggerChaosError() === null, 'No error triggered when errorRate = 0%');

        // 100% error rate
        $chaos->setErrorRate(1.0);
        $err = $chaos->maybeTriggerChaosError();
        $this->assert($err !== null, 'Chaos error triggered when errorRate = 100%');
        $this->assert(in_array($err['statusCode'], [500, 502, 503, 504], true), 'Chaos error has 5xx status code');
    }

    public function testMockServerFullLifecycle(): void
    {
        echo "\n\033[1;34mTesting MockServer Full Request Lifecycle...\033[0m\n";

        $spec = [
            'openapi' => '3.0.0',
            'paths' => [
                '/health' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'status' => ['type' => 'string', 'example' => 'healthy'],
                                                'version' => ['type' => 'string', 'example' => '1.0.0']
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $server = new MockServer($spec);
        $response = $server->handle('GET', '/health', [], null, false);

        $this->assertEqual($response['statusCode'], 200, 'Returns HTTP 200 OK for /health');
        $this->assertEqual($response['body']['status'], 'healthy', 'Uses example payload values');
        $this->assertEqual($response['headers']['X-Powered-By'], 'EidCloud-ApiMock/1.0', 'Includes custom powered-by header');

        // 404 test
        $res404 = $server->handle('GET', '/non-existent-route', [], null, false);
        $this->assertEqual($res404['statusCode'], 404, 'Returns HTTP 404 for unmapped route');
    }

    public function testStatefulCrudOperations(): void
    {
        echo "\n\033[1;34mTesting MockServer Stateful CRUD Endpoints...\033[0m\n";

        $spec = [
            'openapi' => '3.0.0',
            'paths' => [
                '/products' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'id' => ['type' => 'integer'],
                                                    'title' => ['type' => 'string'],
                                                    'price' => ['type' => 'number']
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'post' => [
                        'responses' => [
                            '201' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer'],
                                                'title' => ['type' => 'string']
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/products/{id}' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer'],
                                                'title' => ['type' => 'string'],
                                                'price' => ['type' => 'number']
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'put' => [
                        'responses' => [
                            '200' => [
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]]
                            ]
                        ]
                    ],
                    'delete' => [
                        'responses' => [
                            '200' => [
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $server = new MockServer($spec);
        $server->getStore()->clear();

        // 1. Create a product via POST /products
        $createRes = $server->handle('POST', '/products', [], ['title' => 'Cloud Server Tier 1', 'price' => 29.99], false);
        $this->assertEqual($createRes['statusCode'], 201, 'POST /products returns 201 Created');
        $this->assertEqual($createRes['body']['id'], 1, 'Assigned ID 1');
        $this->assertEqual($createRes['body']['title'], 'Cloud Server Tier 1', 'Stored product title');

        // 2. Fetch created product via GET /products/1
        $getRes = $server->handle('GET', '/products/1', [], null, false);
        $this->assertEqual($getRes['statusCode'], 200, 'GET /products/1 returns 200 OK');
        $this->assertEqual($getRes['body']['title'], 'Cloud Server Tier 1', 'Retrieved exact persisted state');

        // 3. Update via PUT /products/1
        $putRes = $server->handle('PUT', '/products/1', [], ['title' => 'Cloud Server Tier 1 (Upgraded)'], false);
        $this->assertEqual($putRes['statusCode'], 200, 'PUT /products/1 returns 200 OK');
        $this->assertEqual($putRes['body']['title'], 'Cloud Server Tier 1 (Upgraded)', 'Updated entity state');

        // 4. Verify in List GET /products
        $listRes = $server->handle('GET', '/products', [], null, false);
        $this->assertEqual($listRes['statusCode'], 200, 'GET /products returns 200 OK');
        $this->assertEqual(count($listRes['body']), 1, 'List contains 1 entity');

        // 5. Delete via DELETE /products/1
        $delRes = $server->handle('DELETE', '/products/1', [], null, false);
        $this->assertEqual($delRes['statusCode'], 200, 'DELETE /products/1 returns 200 OK');
    }

    public function testGenerateStaticDataset(): void
    {
        echo "\n\033[1;34mTesting generateStaticDataset()...\033[0m\n";

        $spec = [
            'openapi' => '3.0.0',
            'paths' => [
                '/orders' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'order_id' => ['type' => 'integer'],
                                                    'total' => ['type' => 'number']
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $server = new MockServer($spec);
        $dataset = $server->generateStaticDataset();
        $this->assert(isset($dataset['GET /orders']), 'Contains GET /orders mock data snapshot');
        $this->assert(is_array($dataset['GET /orders']), 'Generated valid array structure for orders');
    }
}
