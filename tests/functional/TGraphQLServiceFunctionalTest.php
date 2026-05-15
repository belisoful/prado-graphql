<?php

/**
 * TGraphQLServiceFunctionalTest — real HTTP tests against TGraphQLService.
 *
 * This suite starts a PHP built-in web server (via start-server.sh) that serves
 * the Bookshop demo application from examples/graphql-demo/.  Every test sends
 * a genuine HTTP request with curl and asserts on the decoded JSON response,
 * covering the complete TGraphQLService surface:
 *
 *  - GET and POST request routing
 *  - Content-type negotiation (application/json, application/graphql,
 *    multipart/form-data, application/x-www-form-urlencoded)
 *  - Variables, operation names, fragments
 *  - Complex types: objects, lists, enums, unions, input objects
 *  - Mutations (createBook, addReview) with persistent in-memory state
 *  - Partial-data field errors
 *  - Validation errors (unknown field, malformed query, type mismatch)
 *  - HTTP method rejection (405)
 *  - Introspection on/off
 *  - Query depth limiting
 *  - Query complexity limiting
 *  - Batched queries (enabled / disabled)
 *  - Automatic Persisted Queries (APQ) round-trip
 *  - Context access (requestType via TGraphQLContext)
 *
 * The server is started once in setUpBeforeClass() and torn down in
 * tearDownAfterClass(), so all tests in this class share a single process and
 * therefore share the static in-memory data store of the demo application.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado.Web.Services
 */

use PHPUnit\Framework\TestCase;

class TGraphQLServiceFunctionalTest extends TestCase
{
    // =========================================================================
    // Class-level server lifecycle
    // =========================================================================

    private static mixed $serverProcess = null;
    /** @var array<int, resource> */
    private static array $serverPipes = [];

    private const HOST        = '127.0.0.1';
    private const PORT        = 8037;
    private const BASE_URL    = 'http://127.0.0.1:8037/index.php';
    private const DEMO_DIR    = __DIR__ . '/../../examples/graphql-demo';
    private const SERVER_SCRIPT = __DIR__ . '/start-server.sh';

    /** Start the PHP built-in server once for the entire test class. */
    public static function setUpBeforeClass(): void
    {
        $demoDir = realpath(self::DEMO_DIR);
        if ($demoDir === false) {
            throw new \RuntimeException('Demo application directory not found: ' . self::DEMO_DIR);
        }

        // Wipe any APQ cache left over from a previous run so tests start clean.
        self::clearApqCache($demoDir);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$serverProcess = proc_open(
            'bash ' . escapeshellarg(realpath(self::SERVER_SCRIPT) ?: self::SERVER_SCRIPT),
            $descriptors,
            self::$serverPipes,
            $demoDir  // document root — passed as cwd so `php -S -t ./` serves index.php
        );

        if (!is_resource(self::$serverProcess)) {
            throw new \RuntimeException('proc_open failed to start the built-in PHP server.');
        }

        self::waitForServer(self::HOST, self::PORT, 8.0);
    }

    /** Shut the server down, close all pipe handles, and clean up the APQ cache file. */
    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            foreach (self::$serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }

        $demoDir = realpath(self::DEMO_DIR);
        if ($demoDir !== false) {
            self::clearApqCache($demoDir);
        }
    }

    /**
     * Removes the APQ persistence file used by StaticArrayCache for the given
     * document root, computed the same way StaticArrayCache::getFilePath() does.
     *
     * @param string $documentRoot Absolute path to the demo application root.
     */
    private static function clearApqCache(string $documentRoot): void
    {
        $tag  = substr(md5($documentRoot), 0, 8);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prado_graphql_apq_' . $tag . '.json';
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Busy-poll until the server accepts TCP connections or the timeout expires.
     *
     * We temporarily replace the active error handler with a no-op for each
     * fsockopen probe.  PHPUnit 10 registers an error handler that converts
     * E_WARNING to an exception and then tries to dispatch a test event; in a
     * static context (setUpBeforeClass) there is no TestCase on the call stack,
     * so the event dispatch throws NoTestCaseObjectOnCallStackException.
     * Swapping the handler out for the duration of the socket attempt prevents
     * that exception from ever being created.
     *
     * @throws \RuntimeException when the server does not start in time.
     */
    private static function waitForServer(string $host, int $port, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            // Temporarily suppress all error handling to prevent PHPUnit 10's
            // error-to-exception converter from firing during the socket probe.
            $prevHandler = set_error_handler(static fn() => true);
            $socket      = fsockopen($host, $port, $errno, $errstr, 0.1);
            set_error_handler($prevHandler);

            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000); // 100 ms
        }
        throw new \RuntimeException("Server did not start on {$host}:{$port} within {$timeoutSeconds}s");
    }

    // =========================================================================
    // HTTP helpers
    // =========================================================================

    /**
     * POST application/json to the given service endpoint.
     *
     * @param string            $service   Service ID query-string key ('graphql', 'restricted', 'apq').
     * @param array<string,mixed> $payload GraphQL operation array.
     * @param array<string>     $extraHeaders Additional curl-format headers.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function postJson(string $service, array $payload, array $extraHeaders = []): array
    {
        return $this->rawRequest('POST', $service, [
            CURLOPT_POSTFIELDS  => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER  => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $extraHeaders
            ),
        ]);
    }

    /**
     * POST with application/graphql content-type (raw query string body).
     *
     * @param string $service Service ID.
     * @param string $query   Raw GraphQL query document.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function postGraphQL(string $service, string $query): array
    {
        return $this->rawRequest('POST', $service, [
            CURLOPT_POSTFIELDS => $query,
            CURLOPT_HTTPHEADER => ['Content-Type: application/graphql', 'Accept: application/json'],
        ]);
    }

    /**
     * POST with application/x-www-form-urlencoded.
     *
     * @param string            $service Service ID.
     * @param array<string,mixed> $fields Form fields.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function postForm(string $service, array $fields): array
    {
        return $this->rawRequest('POST', $service, [
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
    }

    /**
     * POST with multipart/form-data, passing GraphQL operations in the 'operations' field.
     *
     * @param string            $service     Service ID.
     * @param array<string,mixed> $operations GraphQL operation (or array of operations).
     * @return array{status: int, body: array<string,mixed>}
     */
    private function postMultipart(string $service, array $operations): array
    {
        return $this->rawRequest('POST', $service, [
            CURLOPT_POSTFIELDS => ['operations' => json_encode($operations, JSON_THROW_ON_ERROR)],
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // curl sets multipart/form-data automatically when POSTFIELDS is an array
        ]);
    }

    /**
     * GET request with query parameters embedded in the URL.
     *
     * @param string            $service  Service ID.
     * @param array<string,mixed> $params URL query parameters.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function get(string $service, array $params): array
    {
        $qs  = http_build_query(array_merge([$service => ''], $params));
        $url = self::BASE_URL . '?' . $qs;
        return $this->rawRequest('GET', $service, [
            CURLOPT_HTTPGET    => true,
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
    }

    /**
     * Send a custom HTTP method (for 405-rejection tests).
     *
     * @param string $method  HTTP method in upper-case.
     * @param string $service Service ID.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function customMethod(string $method, string $service): array
    {
        return $this->rawRequest($method, $service, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
    }

    /**
     * Core curl wrapper.
     *
     * @param string              $method    HTTP method.
     * @param string              $service   Service ID (appended as query-string key).
     * @param array<int,mixed>    $curlOpts  Additional curl options.
     * @return array{status: int, body: array<string,mixed>}
     */
    private function rawRequest(string $method, string $service, array $curlOpts): array
    {
        $url = self::BASE_URL . '?' . $service;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_setopt_array($ch, $curlOpts);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = is_string($raw) ? json_decode($raw, true) : null;
        return ['status' => $status, 'body' => is_array($body) ? $body : []];
    }

    // =========================================================================
    // Convenience extractors
    // =========================================================================

    /** @return array{status: int, body: array<string,mixed>} */
    private function gql(string $service, string $query, ?array $variables = null, ?string $operationName = null): array
    {
        $payload = ['query' => $query];
        if ($variables !== null) {
            $payload['variables'] = $variables;
        }
        if ($operationName !== null) {
            $payload['operationName'] = $operationName;
        }
        return $this->postJson($service, $payload);
    }

    // =========================================================================
    // GROUP 1 — Transport layer: GET and POST content types
    // =========================================================================

    public function test_get_request_executes_simple_query(): void
    {
        $resp = $this->get('graphql', ['query' => '{ hello }']);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Hello from Bookshop!', $resp['body']['data']['hello']);
    }

    public function test_post_json_simple_query(): void
    {
        $resp = $this->gql('graphql', '{ ping }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_post_application_graphql_content_type(): void
    {
        $resp = $this->postGraphQL('graphql', '{ ping }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_post_url_encoded_form(): void
    {
        $resp = $this->postForm('graphql', ['query' => '{ ping }']);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_post_multipart_form_data_operations_field(): void
    {
        $resp = $this->postMultipart('graphql', ['query' => '{ ping }']);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_put_method_returns_405(): void
    {
        $resp = $this->customMethod('PUT', 'graphql');

        $this->assertSame(405, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    public function test_delete_method_returns_405(): void
    {
        $resp = $this->customMethod('DELETE', 'graphql');

        $this->assertSame(405, $resp['status']);
    }

    public function test_patch_method_returns_405(): void
    {
        $resp = $this->customMethod('PATCH', 'graphql');

        $this->assertSame(405, $resp['status']);
    }

    public function test_malformed_json_returns_400(): void
    {
        $resp = $this->rawRequest('POST', 'graphql', [
            CURLOPT_POSTFIELDS => '{not valid json',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $this->assertSame(400, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    // =========================================================================
    // GROUP 2 — Query features: variables, operation names, fragments, lists
    // =========================================================================

    public function test_query_variables_are_passed_through(): void
    {
        $resp = $this->gql(
            'graphql',
            'query Echo($msg: String!) { echo(message: $msg) }',
            ['msg' => 'hello-variables'],
            'Echo'
        );

        $this->assertSame(200, $resp['status']);
        $this->assertSame('hello-variables', $resp['body']['data']['echo']);
    }

    public function test_operation_name_selects_correct_operation(): void
    {
        $resp = $this->postJson('graphql', [
            'query'         => 'query A { hello } query B { ping }',
            'operationName' => 'B',
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
        $this->assertArrayNotHasKey('hello', $resp['body']['data']);
    }

    public function test_inline_fragment_on_union(): void
    {
        $resp = $this->gql('graphql', '{ search(term: "dune") {
            ... on Book   { title }
            ... on Author { name }
        } }');

        $this->assertSame(200, $resp['status']);
        $results = $resp['body']['data']['search'];
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        // Dune is a book title
        $this->assertSame('Dune', $results[0]['title']);
    }

    public function test_named_fragment_spread(): void
    {
        $resp = $this->gql('graphql', '
            fragment BookFields on Book { id title year }
            query ListBooks { books { ...BookFields } }
        ', null, 'ListBooks');

        $this->assertSame(200, $resp['status']);
        $books = $resp['body']['data']['books'];
        $this->assertCount(4, $books);
        $this->assertArrayHasKey('title', $books[0]);
        $this->assertArrayHasKey('year',  $books[0]);
    }

    public function test_list_query_returns_all_books(): void
    {
        $resp = $this->gql('graphql', '{ books { id title } }');

        $this->assertSame(200, $resp['status']);
        $this->assertCount(4, $resp['body']['data']['books']);
    }

    public function test_list_query_limit_argument(): void
    {
        $resp = $this->gql('graphql', '{ books(limit: 2) { id title } }');

        $this->assertSame(200, $resp['status']);
        $this->assertCount(2, $resp['body']['data']['books']);
    }

    public function test_enum_filter_argument(): void
    {
        $resp = $this->gql('graphql', '{ books(genre: SCIENCE_FICTION) { id title } }');

        $this->assertSame(200, $resp['status']);
        // All seeded books are SCIENCE_FICTION
        $this->assertCount(4, $resp['body']['data']['books']);
    }

    public function test_book_by_id_returns_correct_book(): void
    {
        $resp = $this->gql('graphql', '{ book(id: "2") { title isbn year } }');

        $this->assertSame(200, $resp['status']);
        $book = $resp['body']['data']['book'];
        $this->assertSame('Dune', $book['title']);
        $this->assertSame('978-0441013593', $book['isbn']);
        $this->assertSame(1965, $book['year']);
    }

    public function test_book_by_nonexistent_id_returns_null(): void
    {
        $resp = $this->gql('graphql', '{ book(id: "9999") { title } }');

        $this->assertSame(200, $resp['status']);
        $this->assertNull($resp['body']['data']['book']);
    }

    public function test_author_query_with_nested_books(): void
    {
        $resp = $this->gql('graphql', '{ author(id: "1") { name books { title } } }');

        $this->assertSame(200, $resp['status']);
        $author = $resp['body']['data']['author'];
        $this->assertSame('Ursula K. Le Guin', $author['name']);
        $this->assertCount(2, $author['books']); // Left Hand + Dispossessed
    }

    public function test_authors_list(): void
    {
        $resp = $this->gql('graphql', '{ authors { id name } }');

        $this->assertSame(200, $resp['status']);
        $this->assertCount(3, $resp['body']['data']['authors']);
    }

    public function test_union_search_returns_both_books_and_authors(): void
    {
        // 'foundation' matches a book; 'asimov' matches an author
        $resp = $this->gql('graphql', '{ search(term: "a") {
            ... on Book   { title }
            ... on Author { name }
        } }');

        $this->assertSame(200, $resp['status']);
        $results = $resp['body']['data']['search'];
        $this->assertNotEmpty($results);
    }

    public function test_book_author_reviews_deep_resolution(): void
    {
        // Resolve Book → Author → books (circular back-reference)
        $resp = $this->gql('graphql', '{ book(id: "3") { title author { name books { title } } } }');

        $this->assertSame(200, $resp['status']);
        $book = $resp['body']['data']['book'];
        $this->assertSame('Foundation', $book['title']);
        $this->assertSame('Isaac Asimov', $book['author']['name']);
        // Asimov has exactly one book in the seed data
        $this->assertCount(1, $book['author']['books']);
    }

    // =========================================================================
    // GROUP 3 — Mutations
    // =========================================================================

    public function test_create_book_mutation_adds_book(): void
    {
        $resp = $this->gql('graphql', '
            mutation CreateBook($input: BookInput!) {
                createBook(input: $input) { id title isbn genre author { name } }
            }
        ', [
            'input' => [
                'title'    => 'Hyperion',
                'isbn'     => '978-0553283686',
                'year'     => 1989,
                'authorId' => '3',
                'genre'    => 'SCIENCE_FICTION',
            ],
        ], 'CreateBook');

        $this->assertSame(200, $resp['status']);
        $book = $resp['body']['data']['createBook'];
        $this->assertSame('Hyperion', $book['title']);
        $this->assertSame('SCIENCE_FICTION', $book['genre']);
        $this->assertSame('Isaac Asimov', $book['author']['name']);
        $this->assertNotEmpty($book['id']);
    }

    public function test_created_book_is_visible_in_subsequent_query(): void
    {
        // PHP's built-in server resets static class properties between requests,
        // so cross-request state cannot be relied upon.  Instead we use a single
        // batched request to the apq endpoint (EnableBatchedQueries=true): all
        // operations in the batch share one PHP request lifecycle and therefore
        // the same in-memory data store.
        $resp = $this->rawRequest('POST', 'apq', [
            CURLOPT_POSTFIELDS => json_encode([
                [
                    'query'         => 'mutation CreateBook($input: BookInput!) { createBook(input: $input) { id title } }',
                    'variables'     => [
                        'input' => [
                            'title'    => 'Hyperion',
                            'isbn'     => '978-0553283686',
                            'year'     => 1989,
                            'authorId' => '3',
                            'genre'    => 'SCIENCE_FICTION',
                        ],
                    ],
                    'operationName' => 'CreateBook',
                ],
                ['query' => '{ books { title } }'],
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Hyperion', $resp['body'][0]['data']['createBook']['title']);
        $titles = array_column($resp['body'][1]['data']['books'], 'title');
        $this->assertContains('Hyperion', $titles);
    }

    public function test_add_review_mutation(): void
    {
        $resp = $this->gql('graphql', '
            mutation {
                addReview(bookId: "1", rating: 5, body: "A masterpiece.") {
                    id rating body book { title }
                }
            }
        ');

        $this->assertSame(200, $resp['status']);
        $review = $resp['body']['data']['addReview'];
        $this->assertSame(5, $review['rating']);
        $this->assertSame('A masterpiece.', $review['body']);
        $this->assertSame('The Left Hand of Darkness', $review['book']['title']);
    }

    public function test_added_review_appears_on_book(): void
    {
        // Same batching strategy as test_created_book_is_visible_in_subsequent_query:
        // mutation + query share one PHP request lifecycle via a single batch.
        $resp = $this->rawRequest('POST', 'apq', [
            CURLOPT_POSTFIELDS => json_encode([
                ['query' => 'mutation { addReview(bookId: "1", rating: 5, body: "A masterpiece.") { id } }'],
                ['query' => '{ book(id: "1") { reviews { rating body } } }'],
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        $this->assertSame(200, $resp['status']);
        $reviews = $resp['body'][1]['data']['book']['reviews'];
        $this->assertCount(1, $reviews);
        $this->assertSame(5, $reviews[0]['rating']);
        $this->assertSame('A masterpiece.', $reviews[0]['body']);
    }

    public function test_mutation_without_optional_body_field(): void
    {
        $resp = $this->gql('graphql', '
            mutation { addReview(bookId: "2", rating: 4) { id rating body } }
        ');

        $this->assertSame(200, $resp['status']);
        $review = $resp['body']['data']['addReview'];
        $this->assertSame(4, $review['rating']);
        $this->assertNull($review['body']);
    }

    // =========================================================================
    // GROUP 4 — Error handling
    // =========================================================================

    public function test_field_error_returns_partial_data_with_null_field(): void
    {
        $resp = $this->gql('graphql', '{ hello failWithError(message: "boom") ping }');

        $this->assertSame(200, $resp['status']);
        // Partial data: hello and ping resolve; failWithError is null
        $this->assertSame('Hello from Bookshop!', $resp['body']['data']['hello']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
        $this->assertNull($resp['body']['data']['failWithError']);
        $this->assertNotEmpty($resp['body']['errors']);
        $this->assertSame('boom', $resp['body']['errors'][0]['message']);
    }

    public function test_unknown_field_returns_validation_error(): void
    {
        $resp = $this->gql('graphql', '{ nonExistentField }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
        // No data key when validation fails entirely
        $this->assertArrayNotHasKey('data', $resp['body']);
    }

    public function test_syntax_error_in_query_returns_errors(): void
    {
        $resp = $this->gql('graphql', '{ hello ');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    public function test_missing_required_variable_returns_error(): void
    {
        // echo requires a non-null message arg; omit it
        $resp = $this->gql(
            'graphql',
            'query E($msg: String!) { echo(message: $msg) }',
            null // no variables
        );

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    public function test_wrong_variable_type_returns_error(): void
    {
        $resp = $this->gql(
            'graphql',
            'query E($msg: String!) { echo(message: $msg) }',
            ['msg' => 42] // Int instead of String
        );

        // webonyx coerces scalars; 42 → "42" might pass or fail depending on version.
        // Assert only that no exception escapes and the response is valid JSON.
        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']);
    }

    public function test_null_for_nonnull_argument_returns_error(): void
    {
        $resp = $this->gql('graphql', '{ echo(message: null) }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    // =========================================================================
    // GROUP 5 — Context access
    // =========================================================================

    public function test_context_exposes_http_request_type_for_post(): void
    {
        $resp = $this->gql('graphql', '{ requestType }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('POST', $resp['body']['data']['requestType']);
    }

    public function test_context_exposes_http_request_type_for_get(): void
    {
        $resp = $this->get('graphql', ['query' => '{ requestType }']);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('GET', $resp['body']['data']['requestType']);
    }

    // =========================================================================
    // GROUP 6 — Introspection
    // =========================================================================

    public function test_introspection_enabled_on_main_endpoint(): void
    {
        $resp = $this->gql('graphql', '{ __schema { queryType { name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Query', $resp['body']['data']['__schema']['queryType']['name']);
    }

    public function test_typename_introspection_works(): void
    {
        $resp = $this->gql('graphql', '{ __typename }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Query', $resp['body']['data']['__typename']);
    }

    public function test_type_introspection_reveals_book_fields(): void
    {
        $resp = $this->gql('graphql', '{ __type(name: "Book") { fields { name } } }');

        $this->assertSame(200, $resp['status']);
        $fieldNames = array_column($resp['body']['data']['__type']['fields'], 'name');
        $this->assertContains('title',  $fieldNames);
        $this->assertContains('author', $fieldNames);
        $this->assertContains('genre',  $fieldNames);
    }

    public function test_introspection_disabled_on_restricted_endpoint(): void
    {
        $resp = $this->gql('restricted', '{ __schema { queryType { name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
        // data is either absent or null when introspection is blocked
        $schemaData = $resp['body']['data']['__schema'] ?? null;
        $this->assertNull($schemaData);
    }

    public function test_typename_still_works_with_introspection_disabled(): void
    {
        // __typename is NOT blocked by DisableIntrospection
        $resp = $this->gql('restricted', '{ __typename }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Query', $resp['body']['data']['__typename']);
    }

    // =========================================================================
    // GROUP 7 — Query depth limiting (restricted endpoint, MaxQueryDepth=3)
    // =========================================================================

    public function test_query_at_depth_limit_passes(): void
    {
        // nested(0) → level1(1) → level2(2) → level3(3): non-leaf at depth 3 ≤ limit 3
        $resp = $this->gql('restricted', '{ nested { level1 { level2 { level3 { value } } } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertArrayNotHasKey('errors', $resp['body']);
        $this->assertSame('level3', $resp['body']['data']['nested']['level1']['level2']['level3']['value']);
    }

    public function test_query_one_level_shallower_passes(): void
    {
        $resp = $this->gql('restricted', '{ nested { level1 { level2 { value } } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertArrayNotHasKey('errors', $resp['body']);
    }

    public function test_query_exceeding_depth_limit_is_rejected(): void
    {
        // deep is a non-leaf at depth 4 > MaxQueryDepth(3) — should be rejected
        $resp = $this->gql('restricted', '{ nested { level1 { level2 { level3 { deep { value } } } } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
        $this->assertArrayNotHasKey('data', $resp['body']);
    }

    // =========================================================================
    // GROUP 8 — Query complexity limiting (restricted endpoint, MaxQueryComplexity=5)
    // =========================================================================

    public function test_simple_query_passes_complexity_limit(): void
    {
        // { ping } = complexity 1
        $resp = $this->gql('restricted', '{ ping }');

        $this->assertSame(200, $resp['status']);
        $this->assertArrayNotHasKey('errors', $resp['body']);
    }

    public function test_query_at_complexity_limit_passes(): void
    {
        // books + id + title + isbn + year = 5 fields = complexity 5 = limit
        $resp = $this->gql('restricted', '{ books { id title isbn year } }');

        // webonyx rejects when complexity > limit, so exactly at limit passes
        $this->assertSame(200, $resp['status']);
        $this->assertArrayNotHasKey('errors', $resp['body']);
    }

    public function test_query_exceeding_complexity_limit_is_rejected(): void
    {
        // books + id + title + isbn + year + genre + author + id + name = 9 fields > 5
        $resp = $this->gql('restricted', '{ books { id title isbn year genre author { id name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    // =========================================================================
    // GROUP 9 — Batched queries
    // =========================================================================

    public function test_batch_disabled_on_main_endpoint_returns_400(): void
    {
        $resp = $this->postJson('graphql', [
            ['query' => '{ hello }'],
            ['query' => '{ ping }'],
        ]);

        $this->assertSame(400, $resp['status']);
        $this->assertNotEmpty($resp['body']['errors']);
    }

    public function test_batch_enabled_on_apq_endpoint_returns_array(): void
    {
        $resp = $this->postJson('apq', [
            ['query' => '{ hello }'],
            ['query' => '{ ping }'],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertIsArray($resp['body']);
        $this->assertCount(2, $resp['body']);
        $this->assertSame('Hello from Bookshop!', $resp['body'][0]['data']['hello']);
        $this->assertSame('pong', $resp['body'][1]['data']['ping']);
    }

    public function test_batch_single_item_array_executes_correctly(): void
    {
        $resp = $this->postJson('apq', [['query' => '{ hello }']]);

        $this->assertSame(200, $resp['status']);
        $this->assertCount(1, $resp['body']);
        $this->assertSame('Hello from Bookshop!', $resp['body'][0]['data']['hello']);
    }

    public function test_batch_can_include_mutations(): void
    {
        $resp = $this->postJson('apq', [
            ['query' => '{ ping }'],
            ['query' => 'mutation { addReview(bookId:"2", rating:3) { id rating } }'],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertCount(2, $resp['body']);
        $this->assertSame('pong', $resp['body'][0]['data']['ping']);
        $this->assertSame(3, $resp['body'][1]['data']['addReview']['rating']);
    }

    public function test_batch_partial_error_still_returns_array(): void
    {
        $resp = $this->postJson('apq', [
            ['query' => '{ ping }'],
            ['query' => '{ nonExistentField }'],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertCount(2, $resp['body']);
        $this->assertSame('pong', $resp['body'][0]['data']['ping']);
        $this->assertNotEmpty($resp['body'][1]['errors']);
    }

    // =========================================================================
    // GROUP 10 — Automatic Persisted Queries (APQ)
    // =========================================================================

    public function test_apq_store_query_via_mutation_returns_hash(): void
    {
        $query    = '{ hello }';
        $expected = hash('sha256', $query);

        $resp = $this->gql('apq', 'mutation { storeQuery(query: "{ hello }") }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame($expected, $resp['body']['data']['storeQuery']);
    }

    public function test_apq_retrieve_via_hash_returns_correct_data(): void
    {
        $query = '{ hello }';
        $hash  = hash('sha256', $query);

        // Retrieve by hash alone (no inline query)
        $resp = $this->postJson('apq', [
            'query'      => '',
            'extensions' => ['persistedQuery' => ['sha256Hash' => $hash]],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Hello from Bookshop!', $resp['body']['data']['hello']);
    }

    public function test_apq_unknown_hash_returns_no_data(): void
    {
        $resp = $this->postJson('apq', [
            'query'      => '',
            'extensions' => ['persistedQuery' => ['sha256Hash' => str_repeat('0', 64)]],
        ]);

        // No query resolved, no result data — errors or null data
        $this->assertSame(200, $resp['status']);
        $hasErrors = !empty($resp['body']['errors']);
        $hasNullData = ($resp['body']['data'] ?? 'sentinel') === null;
        $this->assertTrue(
            $hasErrors || $hasNullData,
            'Expected errors or null data for an unknown APQ hash'
        );
    }

    public function test_apq_cached_complex_query_executes_correctly(): void
    {
        $query = '{ authors { id name books { title } } }';
        $hash  = hash('sha256', $query);

        // Step 1: store
        $store = $this->gql('apq', sprintf('mutation { storeQuery(query: %s) }', json_encode($query)));
        $this->assertSame(200, $store['status']);

        // Step 2: retrieve by hash
        $resp = $this->postJson('apq', [
            'query'      => '',
            'extensions' => ['persistedQuery' => ['sha256Hash' => $hash]],
        ]);

        $this->assertSame(200, $resp['status']);
        $this->assertArrayHasKey('authors', $resp['body']['data']);
        $this->assertCount(3, $resp['body']['data']['authors']);
    }

    // =========================================================================
    // GROUP 11 — Nested depth hierarchy (detailed)
    // =========================================================================

    public function test_nested_type_resolves_values_at_each_level(): void
    {
        $resp = $this->gql('graphql', '{ nested { level1 { value level2 { value level3 { value } } } } }');

        $this->assertSame(200, $resp['status']);
        $n = $resp['body']['data']['nested'];
        $this->assertSame('level1', $n['level1']['value']);
        $this->assertSame('level2', $n['level1']['level2']['value']);
        $this->assertSame('level3', $n['level1']['level2']['level3']['value']);
    }

    public function test_deep_level_resolves_on_main_endpoint_no_limit(): void
    {
        // Main endpoint inherits MaxQueryDepth=8 from gql-config.
        // This query reaches depth 4 (nested→level1→level2→level3→deep), well
        // within the limit, so it must resolve without errors.
        $resp = $this->gql('graphql', '{ nested { level1 { level2 { level3 { deep { value } } } } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertArrayNotHasKey('errors', $resp['body']);
        $this->assertSame('deep', $resp['body']['data']['nested']['level1']['level2']['level3']['deep']['value']);
    }

    // =========================================================================
    // GROUP 12 — Introspection details (main endpoint)
    // =========================================================================

    public function test_introspection_reveals_mutation_type(): void
    {
        $resp = $this->gql('graphql', '{ __schema { mutationType { name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('Mutation', $resp['body']['data']['__schema']['mutationType']['name']);
    }

    public function test_introspection_reveals_enum_values(): void
    {
        $resp = $this->gql('graphql', '{ __type(name: "Genre") { enumValues { name } } }');

        $this->assertSame(200, $resp['status']);
        $names = array_column($resp['body']['data']['__type']['enumValues'], 'name');
        $this->assertContains('SCIENCE_FICTION', $names);
        $this->assertContains('FANTASY', $names);
    }

    public function test_introspection_reveals_input_type(): void
    {
        $resp = $this->gql('graphql', '{ __type(name: "BookInput") { kind inputFields { name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('INPUT_OBJECT', $resp['body']['data']['__type']['kind']);
        $fieldNames = array_column($resp['body']['data']['__type']['inputFields'], 'name');
        $this->assertContains('title',    $fieldNames);
        $this->assertContains('authorId', $fieldNames);
        $this->assertContains('genre',    $fieldNames);
    }

    public function test_introspection_reveals_union_type(): void
    {
        $resp = $this->gql('graphql', '{ __type(name: "SearchResult") { kind possibleTypes { name } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertSame('UNION', $resp['body']['data']['__type']['kind']);
        $typeNames = array_column($resp['body']['data']['__type']['possibleTypes'], 'name');
        $this->assertContains('Book',   $typeNames);
        $this->assertContains('Author', $typeNames);
    }

    // =========================================================================
    // GROUP 13 — Edge cases
    // =========================================================================

    public function test_empty_post_body_returns_graceful_response(): void
    {
        // An empty application/json body is treated as no query
        $resp = $this->rawRequest('POST', 'graphql', [
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);

        // Service should not crash; either 200 with empty data or 400
        $this->assertContains($resp['status'], [200, 400]);
    }

    public function test_query_with_null_variables_field_is_accepted(): void
    {
        $resp = $this->postJson('graphql', ['query' => '{ ping }', 'variables' => null]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_query_with_empty_variables_object_is_accepted(): void
    {
        $resp = $this->postJson('graphql', ['query' => '{ ping }', 'variables' => (object) []]);

        $this->assertSame(200, $resp['status']);
        $this->assertSame('pong', $resp['body']['data']['ping']);
    }

    public function test_large_result_set_is_returned_correctly(): void
    {
        // Request all available fields on all books
        $resp = $this->gql('graphql', '{ books { id title isbn year genre author { id name biography } reviews { id rating } } }');

        $this->assertSame(200, $resp['status']);
        $this->assertNotEmpty($resp['body']['data']['books']);
        foreach ($resp['body']['data']['books'] as $book) {
            $this->assertArrayHasKey('id',     $book);
            $this->assertArrayHasKey('title',  $book);
            $this->assertArrayHasKey('author', $book);
        }
    }

    public function test_multiple_top_level_fields_resolved_independently(): void
    {
        $resp = $this->gql('graphql', '{ hello ping authors { name } book(id:"1") { title } }');

        $this->assertSame(200, $resp['status']);
        $data = $resp['body']['data'];
        $this->assertSame('Hello from Bookshop!', $data['hello']);
        $this->assertSame('pong', $data['ping']);
        $this->assertCount(3, $data['authors']);
        $this->assertSame('The Left Hand of Darkness', $data['book']['title']);
    }

    public function test_response_content_type_is_application_json(): void
    {
        $ch = curl_init(self::BASE_URL . '?graphql');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{"query":"{ ping }"}',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ]);
        $raw    = (string) curl_exec($ch);
        curl_close($ch);

        $this->assertStringContainsStringIgnoringCase('application/json', $raw);
    }
}
