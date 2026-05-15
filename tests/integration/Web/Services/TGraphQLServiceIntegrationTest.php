<?php

/**
 * TGraphQLServiceIntegrationTest — end-to-end tests for TGraphQLService.
 *
 * These tests exercise the complete execution pipeline with real webonyx
 * GraphQL execution and the full run() → parse → validate → execute →
 * format → respond cycle.  Only TApplication / THttpRequest / THttpResponse
 * are mocked because they require a live web-server context unavailable in CLI.
 *
 * **What makes these integration tests different from the unit tests:**
 * - Security rules are ACTUALLY enforced (queries are rejected, not just rules
 *   added to the list).
 * - Dynamic events (dyFormatError, dyCreateContext, dyBuildValidationRules) are
 *   tested via real PRADO behavior attachments.
 * - Complex query patterns (fragments, mutations, variables, nested types) run
 *   through the full resolver graph.
 * - The GraphQL spec requirement of partial data alongside field errors is
 *   verified end-to-end.
 * - APQ store-and-retrieve round-trips execute a real query.
 * - The shared-config init() lifecycle is exercised with a real
 *   TGraphQLServiceConfig module.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado.Web.Services
 */

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error as GraphQLError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Validator\Rules\ValidationRule;
use Prado\TApplication;
use Prado\Web\Services\IGraphQLSchemaBuilder;
use Prado\Web\Services\TGraphQLContext;
use Prado\Web\Services\TGraphQLService;
use Prado\Web\Services\TGraphQLServiceConfig;
use Prado\Web\THttpRequest;
use Prado\Web\THttpResponse;

class TGraphQLServiceIntegrationTest extends PHPUnit\Framework\TestCase
{
	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function tearDown(): void
	{
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, null);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Builds a rich schema with queries, mutations, nested types, and fragments.
	 */
	private function makeRichSchema(): Schema
	{
		$bookType = new ObjectType([
			'name' => 'Book',
			'fields' => [
				'id'     => ['type' => Type::int()],
				'title'  => ['type' => Type::string()],
				'author' => ['type' => Type::string()],
				'year'   => ['type' => Type::int()],
			],
		]);

		$authorType = new ObjectType([
			'name' => 'Author',
			'fields' => [
				'id'    => ['type' => Type::int()],
				'name'  => ['type' => Type::string()],
				'books' => [
					'type' => Type::listOf($bookType),
					'resolve' => fn($author) => [
						['id' => 1, 'title' => 'Book A', 'author' => $author['name'], 'year' => 2020],
					],
				],
			],
		]);

		$bookInputType = new InputObjectType([
			'name' => 'BookInput',
			'fields' => [
				'title'  => ['type' => Type::nonNull(Type::string())],
				'author' => ['type' => Type::nonNull(Type::string())],
			],
		]);

		$queryType = new ObjectType([
			'name' => 'Query',
			'fields' => [
				'hello' => [
					'type' => Type::string(),
					'resolve' => fn() => 'world',
				],
				'echo' => [
					'type' => Type::string(),
					'args' => ['msg' => ['type' => Type::string()]],
					'resolve' => fn($root, array $args) => $args['msg'] ?? '',
				],
				'book' => [
					'type' => $bookType,
					'args' => ['id' => ['type' => Type::int()]],
					'resolve' => fn($root, array $args) => [
						'id'     => $args['id'] ?? 1,
						'title'  => 'Test Book',
						'author' => 'Test Author',
						'year'   => 2024,
					],
				],
				'author' => [
					'type' => $authorType,
					'args' => ['id' => ['type' => Type::int()]],
					'resolve' => fn($root, array $args) => [
						'id'   => $args['id'] ?? 1,
						'name' => 'Test Author',
					],
				],
				'contextUser' => [
					'type' => Type::string(),
					'resolve' => fn($root, $args, TGraphQLContext $ctx) =>
						$ctx->getUser() !== null ? $ctx->getUser()->getName() : 'anonymous',
				],
				'fail' => [
					'type' => Type::string(),
					'resolve' => fn() => throw new \RuntimeException('Internal resolver error'),
				],
				'fieldError' => [
					'type' => Type::string(),
					'resolve' => fn() => throw new GraphQLError('Intentional field error'),
				],
			],
		]);

		$mutationType = new ObjectType([
			'name' => 'Mutation',
			'fields' => [
				'addBook' => [
					'type' => $bookType,
					'args' => ['input' => ['type' => Type::nonNull($bookInputType)]],
					'resolve' => fn($root, array $args) => [
						'id'     => 99,
						'title'  => $args['input']['title'],
						'author' => $args['input']['author'],
						'year'   => 2024,
					],
				],
				'setMessage' => [
					'type' => Type::string(),
					'args' => ['msg' => ['type' => Type::nonNull(Type::string())]],
					'resolve' => fn($root, array $args) => 'Stored: ' . $args['msg'],
				],
			],
		]);

		return new Schema([
			'query'    => $queryType,
			'mutation' => $mutationType,
		]);
	}

	/**
	 * Wires up a TestableTGraphQLServiceIntegration with captured-output mocks
	 * and injects the PRADO application singleton.
	 *
	 * @return IntegrationTestEnv
	 */
	private function makeEnv(
		?Schema $schema = null,
		string $method = 'POST',
		string $contentType = 'application/json',
		string $rawBody = '',
		array $queryParams = [],
		array $postParams = [],
		array $modulesMap = []
	): IntegrationTestEnv {
		$env = new IntegrationTestEnv();

		$app      = $this->createMock(TApplication::class);
		$request  = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn($method);
		$request->method('getContentType')->willReturn($contentType);
		$request->method('itemAt')->willReturnCallback(
			static function (string $key) use ($method, $queryParams, $postParams) {
				$params = strtoupper($method) === 'GET' ? $queryParams : $postParams;
				return $params[$key] ?? null;
			}
		);

		$response->method('write')->willReturnCallback(
			function (string $s) use ($env): void { $env->written .= $s; }
		);
		$response->method('setStatusCode')->willReturnCallback(
			function (int $code) use ($env): void { $env->statusCode = $code; }
		);
		$response->method('setContentType')->willReturnCallback(
			function (string $ct) use ($env): void { $env->contentType = $ct; }
		);
		$response->method('appendHeader')->willReturnCallback(
			function (string $h) use ($env): void { $env->headers[] = $h; }
		);

		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		if ($modulesMap !== []) {
			$app->method('getModule')->willReturnCallback(
				static fn(string $id) => $modulesMap[$id] ?? null
			);
		}

		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$service = new TestableTGraphQLServiceIntegration();
		$service->setRawBody($rawBody);
		if ($schema !== null) {
			$service->setSchema($schema);
		}

		$env->service = $service;
		$env->app     = $app;
		$env->request = $request;

		return $env;
	}

	/**
	 * Run the service and return the decoded JSON body.
	 */
	private function runAndDecode(IntegrationTestEnv $env): array
	{
		$env->service->run();
		return json_decode($env->written, true) ?? [];
	}

	// =========================================================================
	// Full pipeline — basic queries
	// =========================================================================

	public function test_simple_query_returns_data()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('data', $data);
		$this->assertSame(['hello' => 'world'], $data['data']);
		$this->assertArrayNotHasKey('errors', $data);
	}

	public function test_query_with_variable_passes_through_to_resolver()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => 'query($msg: String) { echo(msg: $msg) }', 'variables' => ['msg' => 'integration']])
		);

		$data = $this->runAndDecode($env);

		$this->assertSame(['echo' => 'integration'], $data['data']);
	}

	public function test_nested_type_resolver_returns_all_fields()
	{
		$env  = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ book(id: 1) { id title author year } }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertSame('Test Book', $data['data']['book']['title']);
		$this->assertSame(2024, $data['data']['book']['year']);
		$this->assertArrayNotHasKey('errors', $data);
	}

	public function test_nested_list_resolver_returns_list()
	{
		$env  = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ author(id: 1) { name books { title } } }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertSame('Test Author', $data['data']['author']['name']);
		$this->assertCount(1, $data['data']['author']['books']);
		$this->assertSame('Book A', $data['data']['author']['books'][0]['title']);
	}

	// =========================================================================
	// Full pipeline — mutations
	// =========================================================================

	public function test_mutation_with_inline_argument_executes()
	{
		$env  = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => 'mutation { setMessage(msg: "hello world") }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Stored: hello world', $data['data']['setMessage']);
	}

	public function test_mutation_with_input_type_variable_executes()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([
				'query'     => 'mutation AddBook($input: BookInput!) { addBook(input: $input) { id title author } }',
				'variables' => ['input' => ['title' => 'New Book', 'author' => 'New Author']],
			])
		);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame(99, $data['data']['addBook']['id']);
		$this->assertSame('New Book', $data['data']['addBook']['title']);
		$this->assertSame('New Author', $data['data']['addBook']['author']);
	}

	// =========================================================================
	// Full pipeline — fragments
	// =========================================================================

	public function test_inline_fragment_resolves_correctly()
	{
		$env  = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ book(id: 1) { ... on Book { title year } } }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Test Book', $data['data']['book']['title']);
		$this->assertSame(2024, $data['data']['book']['year']);
	}

	public function test_named_fragment_resolves_correctly()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => 'fragment BookFields on Book { id title author } { book(id: 1) { ...BookFields } }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Test Book', $data['data']['book']['title']);
		$this->assertSame('Test Author', $data['data']['book']['author']);
	}

	// =========================================================================
	// Full pipeline — operation name selection
	// =========================================================================

	public function test_operation_name_selects_correct_operation()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([
				'query'         => 'query A { hello } query B { echo(msg: "selected") }',
				'operationName' => 'B',
			])
		);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('selected', $data['data']['echo']);
	}

	// =========================================================================
	// Content types
	// =========================================================================

	public function test_application_graphql_body_used_as_raw_query()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/graphql', '{ hello }');
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_multipart_form_data_operations_field_parsed()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'multipart/form-data',
			'',
			[],
			['operations' => json_encode(['query' => '{ hello }'])]
		);
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_form_urlencoded_post_fallback_parsed()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/x-www-form-urlencoded',
			'',
			[],
			['query' => '{ hello }']
		);
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_get_request_query_string_executes()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'GET', '', '', ['query' => '{ hello }']);
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_get_request_variables_as_json_string_decoded()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'GET',
			'',
			'',
			['query' => 'query($msg: String) { echo(msg: $msg) }', 'variables' => '{"msg":"from-get"}']
		);
		$data = $this->runAndDecode($env);

		$this->assertSame('from-get', $data['data']['echo']);
	}

	// =========================================================================
	// Batch queries
	// =========================================================================

	public function test_batch_two_queries_return_ordered_results()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([['query' => '{ hello }'], ['query' => '{ echo(msg: "second") }']])
		);
		$env->service->setEnableBatchedQueries(true);
		$data = $this->runAndDecode($env);

		$this->assertCount(2, $data);
		$this->assertSame(['hello' => 'world'], $data[0]['data']);
		$this->assertSame(['echo' => 'second'], $data[1]['data']);
	}

	public function test_batch_variables_are_independent_per_operation()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([
				['query' => 'query($m: String) { echo(msg: $m) }', 'variables' => ['m' => 'first']],
				['query' => 'query($m: String) { echo(msg: $m) }', 'variables' => ['m' => 'second']],
			])
		);
		$env->service->setEnableBatchedQueries(true);
		$data = $this->runAndDecode($env);

		$this->assertSame('first', $data[0]['data']['echo']);
		$this->assertSame('second', $data[1]['data']['echo']);
	}

	public function test_batch_one_error_does_not_abort_remaining_operations()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([['query' => '{ fieldError }'], ['query' => '{ hello }']])
		);
		$env->service->setEnableBatchedQueries(true);
		$data = $this->runAndDecode($env);

		$this->assertCount(2, $data);
		$this->assertArrayHasKey('errors', $data[0]);
		$this->assertSame(['hello' => 'world'], $data[1]['data']);
	}

	public function test_batch_mix_of_queries_and_mutations()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([
				['query' => '{ hello }'],
				['query' => 'mutation { setMessage(msg: "ok") }'],
			])
		);
		$env->service->setEnableBatchedQueries(true);
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data[0]['data']);
		$this->assertSame('Stored: ok', $data[1]['data']['setMessage']);
	}

	// =========================================================================
	// Security — introspection blocking (actual enforcement)
	// =========================================================================

	public function test_introspection_schema_query_succeeds_when_enabled()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ __schema { types { name } } }'])
		);
		$env->service->setEnableIntrospection(true);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertArrayHasKey('__schema', $data['data']);
	}

	public function test_introspection_schema_query_rejected_when_disabled()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ __schema { types { name } } }'])
		);
		$env->service->setEnableIntrospection(false);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertNotEmpty($data['errors']);
	}

	public function test_introspection_type_query_rejected_when_disabled()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ __type(name: "Query") { fields { name } } }'])
		);
		$env->service->setEnableIntrospection(false);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
	}

	public function test_typename_meta_field_allowed_when_introspection_disabled()
	{
		// __typename is always permitted per the GraphQL spec even with introspection off
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ __typename }'])
		);
		$env->service->setEnableIntrospection(false);
		$data = $this->runAndDecode($env);

		$this->assertSame('Query', $data['data']['__typename']);
	}

	// =========================================================================
	// Security — query depth enforcement (actual enforcement)
	// =========================================================================

	public function test_query_within_depth_limit_executes_successfully()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ author(id:1) { books { title } } }'])
		);
		$env->service->setMaxQueryDepth(10);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
		$this->assertSame('Book A', $data['data']['author']['books'][0]['title']);
	}

	public function test_query_exceeding_depth_limit_is_rejected_with_error()
	{
		// webonyx QueryDepth counts non-leaf nesting levels starting at depth=1
		// for the first child-of-root that has its own selection set, so we need
		// three nested object types to reach depth=2 which exceeds limit=1.
		// Schema: Query.outer -> Outer.inner -> Inner.deep -> Deep.v (leaf)
		// Query: { outer { inner { deep { v } } } }
		//   outer@depth=0  (0>0? no  → max stays 0)
		//   inner@depth=1  (1>0? yes → max=1)
		//   deep @depth=2  (2>1? yes → max=2)
		//   v    @depth=3  (leaf     → no update)
		// computed depth = 2 > limit 1 → rejected ✓
		$deepType  = new ObjectType(['name' => 'Deep',  'fields' => ['v'     => ['type' => Type::string(), 'resolve' => fn() => 'ok']]]);
		$innerType = new ObjectType(['name' => 'Inner', 'fields' => ['deep'  => ['type' => $deepType,  'resolve' => fn() => []]]]);
		$outerType = new ObjectType(['name' => 'Outer', 'fields' => ['inner' => ['type' => $innerType, 'resolve' => fn() => []]]]);
		$schema    = new Schema(['query' => new ObjectType(['name' => 'Query', 'fields' => [
			'outer' => ['type' => $outerType, 'resolve' => fn() => []],
		]])]);

		$env = $this->makeEnv(
			$schema,
			'POST',
			'application/json',
			json_encode(['query' => '{ outer { inner { deep { v } } } }'])
		);
		$env->service->setMaxQueryDepth(1);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertNotEmpty($data['errors']);
		$this->assertStringContainsStringIgnoringCase('depth', $data['errors'][0]['message']);
	}

	public function test_depth_limit_zero_imposes_no_restriction()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ author(id:1) { books { title } } }'])
		);
		$env->service->setMaxQueryDepth(0);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
	}

	// =========================================================================
	// Security — query complexity enforcement (actual enforcement)
	// =========================================================================

	public function test_query_within_complexity_limit_executes_successfully()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ hello }'])
		);
		$env->service->setMaxQueryComplexity(100);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
	}

	public function test_query_exceeding_complexity_limit_is_rejected_with_error()
	{
		// { hello echo book { id title author year } } has complexity > 2
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ hello echo(msg:"a") book(id:1) { id title author year } }'])
		);
		$env->service->setMaxQueryComplexity(2);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertStringContainsStringIgnoringCase('complexity', $data['errors'][0]['message']);
	}

	public function test_complexity_limit_zero_imposes_no_restriction()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ hello echo(msg:"a") book(id:1) { id title author year } }'])
		);
		$env->service->setMaxQueryComplexity(0);
		$data = $this->runAndDecode($env);

		$this->assertArrayNotHasKey('errors', $data);
	}

	// =========================================================================
	// Error formatting and partial data (GraphQL spec compliance)
	// =========================================================================

	public function test_field_error_message_appears_in_errors_array()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fieldError }']));
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertSame('Intentional field error', $data['errors'][0]['message']);
	}

	public function test_spec_partial_data_returned_alongside_field_error()
	{
		// Per the GraphQL spec, fields that succeed alongside an erroring sibling
		// still appear in `data`; the error is reported in `errors`.
		$env  = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello fieldError }']));
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('data', $data);
		$this->assertSame('world', $data['data']['hello']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertSame('Intentional field error', $data['errors'][0]['message']);
	}

	public function test_validation_error_returns_errors_without_500()
	{
		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ nonExistentField }'])
		);
		$data = $this->runAndDecode($env);

		$this->assertNull($env->statusCode, 'Validation errors should not set an HTTP error status');
		$this->assertArrayHasKey('errors', $data);
	}

	// =========================================================================
	// DebugFlag — production vs. development error exposure
	// =========================================================================

	public function test_debug_flag_none_sanitizes_internal_exception_message()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fail }']));
		$env->service->setDebugFlag(DebugFlag::NONE);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertStringNotContainsString('Internal resolver error', $data['errors'][0]['message']);
	}

	public function test_debug_flag_include_debug_message_exposes_internal_exception()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fail }']));
		$env->service->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertArrayHasKey('extensions', $data['errors'][0]);
		$this->assertSame('Internal resolver error', $data['errors'][0]['extensions']['debugMessage']);
	}

	// =========================================================================
	// dyFormatError — behavior-based event (actual augmentation tested)
	// =========================================================================

	public function test_dy_format_error_behavior_receives_formatted_array_and_raw_error()
	{
		$behavior = new CapturingFormatErrorBehavior();

		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fieldError }']));
		$env->service->attachBehavior('capture', $behavior);
		$this->runAndDecode($env);

		$this->assertIsArray($behavior->lastFormatted);
		$this->assertSame('Intentional field error', $behavior->lastFormatted['message']);
		$this->assertInstanceOf(GraphQLError::class, $behavior->lastError);
	}

	public function test_dy_format_error_behavior_can_add_extension_field()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fieldError }']));
		$env->service->attachBehavior('augment', new AugmentErrorCodeBehavior());
		$data = $this->runAndDecode($env);

		$this->assertSame('ERR_FIELD', $data['errors'][0]['extensions']['code']);
	}

	public function test_dy_format_error_behavior_can_redact_message()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fieldError }']));
		$env->service->attachBehavior('redact', new RedactErrorMessageBehavior());
		$data = $this->runAndDecode($env);

		$this->assertSame('An error occurred', $data['errors'][0]['message']);
	}

	public function test_dy_format_error_multiple_behaviors_chain_in_order()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ fieldError }']));
		$env->service->attachBehavior('code', new AugmentErrorCodeBehavior());
		$env->service->attachBehavior('redact', new RedactErrorMessageBehavior());
		$data = $this->runAndDecode($env);

		// Both behaviors should have run: code set AND message redacted
		$this->assertSame('ERR_FIELD', $data['errors'][0]['extensions']['code']);
		$this->assertSame('An error occurred', $data['errors'][0]['message']);
	}

	// =========================================================================
	// dyCreateContext — behavior-based event
	// =========================================================================

	public function test_dy_create_context_behavior_receives_default_context()
	{
		$behavior = new CapturingCreateContextBehavior();

		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->attachBehavior('capture', $behavior);
		$this->runAndDecode($env);

		$this->assertInstanceOf(TGraphQLContext::class, $behavior->capturedContext);
	}

	public function test_dy_create_context_behavior_can_replace_context_with_authenticated_user()
	{
		$user = $this->createMock(\Prado\Security\IUser::class);
		$user->method('getName')->willReturn('alice');

		$mockApp     = $this->createMock(TApplication::class);
		$mockRequest = $this->createMock(THttpRequest::class);
		$mockApp->method('getUser')->willReturn($user);

		$behavior = new ReplaceContextBehavior(new TGraphQLContext($mockApp, $mockRequest));

		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ contextUser }']));
		$env->service->attachBehavior('replace', $behavior);
		$data = $this->runAndDecode($env);

		$this->assertSame('alice', $data['data']['contextUser']);
	}

	// =========================================================================
	// dyBuildValidationRules — behavior-based event
	// =========================================================================

	public function test_dy_build_validation_rules_behavior_custom_rule_is_invoked()
	{
		$behavior = new TrackingValidationRuleBehavior();
		$env      = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->attachBehavior('tracker', $behavior);
		$this->runAndDecode($env);

		$this->assertTrue(
			$behavior->ruleWasInvoked,
			'Custom validation rule injected via dyBuildValidationRules must be called'
		);
	}

	public function test_dy_build_validation_rules_behavior_can_reject_queries()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->attachBehavior('block', new RejectAllQueriesBehavior());
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
		$this->assertStringContainsString('blocked', $data['errors'][0]['message']);
	}

	// =========================================================================
	// Context threading through resolvers
	// =========================================================================

	public function test_context_passed_to_resolver_is_tgraphqlcontext()
	{
		$capturedContext = null;

		$schema = new Schema([
			'query' => new ObjectType([
				'name' => 'Query',
				'fields' => [
					'check' => [
						'type' => Type::string(),
						'resolve' => function ($root, $args, $ctx) use (&$capturedContext): string {
							$capturedContext = $ctx;
							return 'ok';
						},
					],
				],
			]),
		]);

		$env = $this->makeEnv($schema, 'POST', 'application/json', json_encode(['query' => '{ check }']));
		$this->runAndDecode($env);

		$this->assertInstanceOf(TGraphQLContext::class, $capturedContext);
		$this->assertInstanceOf(TApplication::class, $capturedContext->getApplication());
		$this->assertInstanceOf(THttpRequest::class, $capturedContext->getRequest());
	}

	public function test_anonymous_user_returns_anonymous_string_from_context()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ contextUser }']));
		$env->app->method('getUser')->willReturn(null);
		$data = $this->runAndDecode($env);

		$this->assertSame('anonymous', $data['data']['contextUser']);
	}

	// =========================================================================
	// Schema builder lifecycle
	// =========================================================================

	public function test_schema_built_via_named_builder_class()
	{
		$env = $this->makeEnv(null, 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->setSchemaBuilderClass(IntegrationTestSchemaBuilder::class);
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_schema_builder_receives_service_instance()
	{
		$capturedService = null;
		$builder         = new ServiceCapturingSchemaBuilder($capturedService);

		$env = $this->makeEnv(null, 'POST', 'application/json', json_encode(['query' => '{ ok }']));
		$env->service->setSchema($builder->buildSchema($env->service));
		$this->runAndDecode($env);

		$this->assertSame($env->service, $capturedService);
	}

	// =========================================================================
	// Shared config — init() lifecycle
	// =========================================================================

	public function test_init_applies_introspection_disable_from_shared_config()
	{
		$config = new TGraphQLServiceConfig();
		$config->setEnableIntrospection(false);

		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['query' => '{ __schema { types { name } } }']),
			[],
			[],
			['graphql-cfg' => $config]
		);

		$env->service->setConfigID('graphql-cfg');
		$env->service->init(null);
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
	}

	public function test_init_service_property_overrides_shared_config_depth()
	{
		$config = new TGraphQLServiceConfig();
		$config->setMaxQueryDepth(2);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($config);

		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$service = new TestableTGraphQLServiceIntegration();
		$service->setMaxQueryDepth(10);
		$service->setConfigID('graphql-cfg');
		$service->init(null);

		$this->assertSame(10, $service->getMaxQueryDepth());
	}

	public function test_init_inherits_debug_flag_from_shared_config()
	{
		$config = new TGraphQLServiceConfig();
		$config->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($config);

		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$service = new TestableTGraphQLServiceIntegration();
		$service->setConfigID('graphql-cfg');
		$service->init(null);

		$this->assertSame(DebugFlag::INCLUDE_DEBUG_MESSAGE, $service->getDebugFlag());
	}

	public function test_init_inherits_batch_enabled_from_shared_config()
	{
		$config = new TGraphQLServiceConfig();
		$config->setEnableBatchedQueries(true);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($config);

		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$service = new TestableTGraphQLServiceIntegration();
		$service->setConfigID('graphql-cfg');
		$service->init(null);

		$this->assertTrue($service->getEnableBatchedQueries());
	}

	// =========================================================================
	// APQ — Automatic Persisted Queries (full pipeline)
	// =========================================================================

	public function test_apq_known_hash_resolves_and_executes_query()
	{
		$query = '{ hello }';
		$hash  = hash('sha256', $query);
		$key   = 'TGraphQLService:gql:pq:' . $hash;

		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('get')->willReturnCallback(
			static fn(string $k) => $k === $key ? $query : false
		);

		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['extensions' => ['persistedQuery' => ['sha256Hash' => $hash]]]),
			[],
			[],
			['apq-cache' => $cache]
		);
		$env->service->setID('gql');
		$env->service->setCacheID('apq-cache');
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	public function test_apq_missing_hash_results_in_graphql_error()
	{
		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('get')->willReturn(false);

		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode(['extensions' => ['persistedQuery' => ['sha256Hash' => 'nonexistent-hash']]]),
			[],
			[],
			['apq-cache' => $cache]
		);
		$env->service->setID('gql');
		$env->service->setCacheID('apq-cache');
		$data = $this->runAndDecode($env);

		$this->assertArrayHasKey('errors', $data);
	}

	public function test_apq_persist_and_retrieve_full_round_trip()
	{
		$store = [];

		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('set')->willReturnCallback(
			function (string $k, $v) use (&$store): bool {
				$store[$k] = $v;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(
			function (string $k) use (&$store) { return $store[$k] ?? false; }
		);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($cache);

		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$service = new TestableTGraphQLServiceIntegration();
		$service->setID('svc');
		$service->setCacheID('cache');

		$query = '{ hello }';
		$this->assertTrue($service->persistQuery($query));

		$hash = hash('sha256', $query);
		$rfl  = new \ReflectionMethod(TGraphQLService::class, 'resolvePersistedQuery');
		$rfl->setAccessible(true);

		$this->assertSame($query, $rfl->invoke($service, $hash));
	}

	public function test_apq_inline_query_also_executes_when_hash_present()
	{
		// When both query and hash are present, the inline query takes precedence
		$query = '{ hello }';
		$hash  = hash('sha256', $query);

		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('get')->willReturn(false); // cache miss — but inline query provided

		$env = $this->makeEnv(
			$this->makeRichSchema(),
			'POST',
			'application/json',
			json_encode([
				'query'      => $query,
				'extensions' => ['persistedQuery' => ['sha256Hash' => $hash]],
			]),
			[],
			[],
			['apq-cache' => $cache]
		);
		$env->service->setID('gql');
		$env->service->setCacheID('apq-cache');
		$data = $this->runAndDecode($env);

		$this->assertSame(['hello' => 'world'], $data['data']);
	}

	// =========================================================================
	// Response format
	// =========================================================================

	public function test_response_content_type_is_application_json_utf8()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->run();

		$this->assertSame('application/json; charset=UTF-8', $env->contentType);
	}

	public function test_response_body_is_valid_json()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$env->service->run();

		json_decode($env->written, true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error());
	}

	public function test_405_returned_for_delete_method()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'DELETE', 'application/json', '');
		$data = $this->runAndDecode($env);

		$this->assertSame(405, $env->statusCode);
		$this->assertArrayHasKey('errors', $data);
	}

	public function test_allow_header_set_on_405()
	{
		$env = $this->makeEnv($this->makeRichSchema(), 'PATCH', 'application/json', '');
		$env->service->run();

		$this->assertNotEmpty(array_filter($env->headers, fn($h) => str_starts_with($h, 'Allow:')));
	}

	public function test_400_returned_for_malformed_json_body()
	{
		$env  = $this->makeEnv($this->makeRichSchema(), 'POST', 'application/json', '{not valid json}');
		$data = $this->runAndDecode($env);

		$this->assertSame(400, $env->statusCode);
		$this->assertArrayHasKey('errors', $data);
	}

	public function test_500_returned_when_schema_builder_not_configured()
	{
		$env  = $this->makeEnv(null, 'POST', 'application/json', json_encode(['query' => '{ hello }']));
		$data = $this->runAndDecode($env);

		$this->assertSame(500, $env->statusCode);
		$this->assertArrayHasKey('errors', $data);
	}
}

// =========================================================================
// Value object for captured response state
// =========================================================================

class IntegrationTestEnv
{
	public string $written = '';
	public ?int $statusCode = null;
	public ?string $contentType = null;
	public array $headers = [];
	public TestableTGraphQLServiceIntegration $service;
	/** @var TApplication|\PHPUnit\Framework\MockObject\MockObject */
	public $app;
	/** @var THttpRequest|\PHPUnit\Framework\MockObject\MockObject */
	public $request;
}

// =========================================================================
// Service test double
// =========================================================================

class TestableTGraphQLServiceIntegration extends TGraphQLService
{
	private string $_rawBody = '';

	public function setRawBody(string $body): void
	{
		$this->_rawBody = $body;
	}

	protected function getRequestRawBody(): string
	{
		return $this->_rawBody;
	}
}

// =========================================================================
// Schema builder doubles
// =========================================================================

class IntegrationTestSchemaBuilder implements IGraphQLSchemaBuilder
{
	public function buildSchema(TGraphQLService $service): Schema
	{
		return new Schema([
			'query' => new ObjectType([
				'name'   => 'Query',
				'fields' => [
					'hello' => ['type' => Type::string(), 'resolve' => fn() => 'world'],
				],
			]),
		]);
	}
}

class ServiceCapturingSchemaBuilder implements IGraphQLSchemaBuilder
{
	public function __construct(private mixed &$captured) {}

	public function buildSchema(TGraphQLService $service): Schema
	{
		$this->captured = $service;
		return new Schema([
			'query' => new ObjectType([
				'name'   => 'Query',
				'fields' => [
					'ok' => ['type' => Type::string(), 'resolve' => fn() => 'yes'],
				],
			]),
		]);
	}
}

// =========================================================================
// Behavior doubles for dynamic event testing
// =========================================================================

/**
 * Captures the formatted error array and raw Error object from dyFormatError.
 */
class CapturingFormatErrorBehavior extends \Prado\Util\TBehavior
{
	public ?array $lastFormatted = null;
	public ?GraphQLError $lastError = null;

	public function dyFormatError(array $formatted, GraphQLError $error, \Prado\Util\TCallChain $callchain = null): array
	{
		$this->lastFormatted = $formatted;
		$this->lastError     = $error;
		return $callchain ? $callchain->dyFormatError($formatted, $error) : $formatted;
	}
}

/**
 * Adds a custom error code extension field via dyFormatError.
 */
class AugmentErrorCodeBehavior extends \Prado\Util\TBehavior
{
	public function dyFormatError(array $formatted, GraphQLError $error, \Prado\Util\TCallChain $callchain = null): array
	{
		$formatted['extensions']['code'] = 'ERR_FIELD';
		return $callchain ? $callchain->dyFormatError($formatted, $error) : $formatted;
	}
}

/**
 * Replaces the error message via dyFormatError.
 */
class RedactErrorMessageBehavior extends \Prado\Util\TBehavior
{
	public function dyFormatError(array $formatted, GraphQLError $error, \Prado\Util\TCallChain $callchain = null): array
	{
		$formatted['message'] = 'An error occurred';
		return $callchain ? $callchain->dyFormatError($formatted, $error) : $formatted;
	}
}

/**
 * Captures the TGraphQLContext passed to dyCreateContext.
 */
class CapturingCreateContextBehavior extends \Prado\Util\TBehavior
{
	public ?TGraphQLContext $capturedContext = null;

	public function dyCreateContext(TGraphQLContext $context, \Prado\Util\TCallChain $callchain = null): TGraphQLContext
	{
		$this->capturedContext = $context;
		return $callchain ? $callchain->dyCreateContext($context) : $context;
	}
}

/**
 * Replaces the context with a pre-built one via dyCreateContext.
 */
class ReplaceContextBehavior extends \Prado\Util\TBehavior
{
	public function __construct(private TGraphQLContext $replacement)
	{
		parent::__construct();
	}

	public function dyCreateContext(TGraphQLContext $context, \Prado\Util\TCallChain $callchain = null): TGraphQLContext
	{
		return $callchain ? $callchain->dyCreateContext($this->replacement) : $this->replacement;
	}
}

/**
 * Appends a tracking validation rule via dyBuildValidationRules.
 *
 * Uses an instance property so getVisitor() can record invocation by
 * closing over the behavior instance directly.  The test holds a reference
 * to the behavior and reads ruleWasInvoked after runAndDecode().
 */
class TrackingValidationRuleBehavior extends \Prado\Util\TBehavior
{
	/** @var bool set to true when the injected rule's getVisitor() is called. */
	public bool $ruleWasInvoked = false;

	public function dyBuildValidationRules(array $rules, \Prado\Util\TCallChain $callchain = null): array
	{
		$behavior = $this;
		$rules[]  = new class ($behavior) extends ValidationRule {
			public function __construct(private readonly TrackingValidationRuleBehavior $behavior)
			{
			}

			public function getVisitor(\GraphQL\Validator\QueryValidationContext $context): array
			{
				$this->behavior->ruleWasInvoked = true;
				return [];
			}
		};
		return $callchain ? $callchain->dyBuildValidationRules($rules) : $rules;
	}
}

/**
 * Appends a validation rule that always rejects queries via dyBuildValidationRules.
 */
class RejectAllQueriesBehavior extends \Prado\Util\TBehavior
{
	public function dyBuildValidationRules(array $rules, \Prado\Util\TCallChain $callchain = null): array
	{
		$rules[] = new class extends ValidationRule {
			public function getVisitor(\GraphQL\Validator\QueryValidationContext $context): array
			{
				return [
					'Document' => function () use ($context): void {
						$context->reportError(new GraphQLError('All queries are blocked'));
					},
				];
			}
		};
		return $callchain ? $callchain->dyBuildValidationRules($rules) : $rules;
	}
}
