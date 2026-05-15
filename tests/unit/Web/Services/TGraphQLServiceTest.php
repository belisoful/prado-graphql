<?php

use GraphQL\Error\DebugFlag;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Prado\Exceptions\TConfigurationException;
use Prado\TApplication;
use Prado\Web\Services\IGraphQLSchemaBuilder;
use Prado\Web\Services\TGraphQLContext;
use Prado\Web\Services\TGraphQLService;
use Prado\Web\Services\TGraphQLServiceConfig;
use Prado\Web\THttpRequest;
use Prado\Web\THttpResponse;

/**
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado.Web.Services
 */
class TGraphQLServiceTest extends PHPUnit\Framework\TestCase
{
	// -----------------------------------------------------------------------
	// Lifecycle
	// -----------------------------------------------------------------------

	protected function tearDown(): void
	{
		// Reset the PRADO application singleton so tests do not bleed into each other.
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, null);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function makeSimpleSchema(): Schema
	{
		return new Schema([
			'query' => new ObjectType([
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
				],
			]),
		]);
	}

	/**
	 * Creates a TGraphQLService with mocked PRADO internals and a
	 * pre-built schema, bypassing the application lifecycle.
	 */
	private function makeService(
		?Schema $schema = null,
		array $getParams = [],
		array $postParams = [],
		string $method = 'POST',
		string $contentType = 'application/json',
		string $rawBody = ''
	): TestableTGraphQLService {
		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn($method);
		$request->method('getContentType')->willReturn($contentType);
		$request->method('itemAt')->willReturnCallback(
			static function (string $key) use ($getParams, $postParams, $method) {
				$params = strtoupper($method) === 'GET' ? $getParams : $postParams;
				return $params[$key] ?? null;
			}
		);

		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$service = new TestableTGraphQLService();
		$service->setRawBody($rawBody);

		// Inject the application via reflection (TComponent::_application is private
		// and normally set by the PRADO bootstrap; we poke it directly in tests).
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		if ($schema !== null) {
			$service->setSchema($schema);
		}

		return $service;
	}

	// -----------------------------------------------------------------------
	// Property defaults
	// -----------------------------------------------------------------------

	public function test_schema_builder_class_defaults_to_empty_string()
	{
		$svc = new TGraphQLService();
		$this->assertSame('', $svc->getSchemaBuilderClass());
	}

	public function test_enable_introspection_defaults_to_true()
	{
		$svc = new TGraphQLService();
		$this->assertTrue($svc->getEnableIntrospection());
	}

	public function test_enable_batched_queries_defaults_to_false()
	{
		$svc = new TGraphQLService();
		$this->assertFalse($svc->getEnableBatchedQueries());
	}

	public function test_max_query_depth_defaults_to_zero()
	{
		$svc = new TGraphQLService();
		$this->assertSame(0, $svc->getMaxQueryDepth());
	}

	public function test_max_query_complexity_defaults_to_zero()
	{
		$svc = new TGraphQLService();
		$this->assertSame(0, $svc->getMaxQueryComplexity());
	}

	public function test_debug_flag_defaults_to_none()
	{
		$svc = new TGraphQLService();
		$this->assertSame(DebugFlag::NONE, $svc->getDebugFlag());
	}

	public function test_cache_id_defaults_to_empty_string()
	{
		$svc = new TGraphQLService();
		$this->assertSame('', $svc->getCacheID());
	}

	public function test_config_id_defaults_to_empty_string()
	{
		$svc = new TGraphQLService();
		$this->assertSame('', $svc->getConfigID());
	}

	// -----------------------------------------------------------------------
	// Property setters
	// -----------------------------------------------------------------------

	public function test_set_schema_builder_class()
	{
		$svc = new TGraphQLService();
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class);
		$this->assertSame(TestSchemaBuilder::class, $svc->getSchemaBuilderClass());
	}

	public function test_set_enable_introspection_false()
	{
		$svc = new TGraphQLService();
		$svc->setEnableIntrospection(false);
		$this->assertFalse($svc->getEnableIntrospection());
	}

	public function test_set_enable_introspection_from_string()
	{
		$svc = new TGraphQLService();
		$svc->setEnableIntrospection('false');
		$this->assertFalse($svc->getEnableIntrospection());
	}

	public function test_set_enable_batched_queries_true()
	{
		$svc = new TGraphQLService();
		$svc->setEnableBatchedQueries(true);
		$this->assertTrue($svc->getEnableBatchedQueries());
	}

	public function test_set_max_query_depth()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryDepth(10);
		$this->assertSame(10, $svc->getMaxQueryDepth());
	}

	public function test_set_max_query_depth_clamps_negative()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryDepth(-5);
		$this->assertSame(0, $svc->getMaxQueryDepth());
	}

	public function test_set_max_query_complexity()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryComplexity(500);
		$this->assertSame(500, $svc->getMaxQueryComplexity());
	}

	public function test_set_max_query_complexity_clamps_negative()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryComplexity(-1);
		$this->assertSame(0, $svc->getMaxQueryComplexity());
	}

	public function test_set_debug_flag()
	{
		$svc = new TGraphQLService();
		$svc->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);
		$this->assertSame(DebugFlag::INCLUDE_DEBUG_MESSAGE, $svc->getDebugFlag());
	}

	public function test_set_cache_id()
	{
		$svc = new TGraphQLService();
		$svc->setCacheID('cache');
		$this->assertSame('cache', $svc->getCacheID());
	}

	public function test_set_config_id()
	{
		$svc = new TGraphQLService();
		$svc->setConfigID('graphql-config');
		$this->assertSame('graphql-config', $svc->getConfigID());
	}

	// -----------------------------------------------------------------------
	// Schema injection
	// -----------------------------------------------------------------------

	public function test_set_schema_bypasses_builder()
	{
		$svc = new TGraphQLService();
		$schema = $this->makeSimpleSchema();
		$svc->setSchema($schema);
		$this->assertSame($schema, $svc->getSchema());
	}

	public function test_set_schema_builder_class_resets_schema()
	{
		$svc = new TGraphQLService();
		$schema = $this->makeSimpleSchema();
		$svc->setSchema($schema);
		// Changing the builder class should reset the cached schema
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class);
		// After reset the cached schema is null; getSchema() would rebuild via builder
		// We can verify by injecting a new schema again
		$schema2 = $this->makeSimpleSchema();
		$svc->setSchema($schema2);
		$this->assertSame($schema2, $svc->getSchema());
	}

	// -----------------------------------------------------------------------
	// getSchemaBuilder() — error cases
	// -----------------------------------------------------------------------

	public function test_get_schema_builder_throws_when_class_not_set()
	{
		$svc = new TGraphQLService();
		$this->expectException(TConfigurationException::class);
		$svc->getSchemaBuilder();
	}

	public function test_get_schema_builder_throws_when_class_not_implements_interface()
	{
		$svc = new TGraphQLService();
		$svc->setSchemaBuilderClass(\stdClass::class);
		$this->expectException(TConfigurationException::class);
		$svc->getSchemaBuilder();
	}

	public function test_get_schema_builder_returns_builder_instance()
	{
		$svc = new TGraphQLService();
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class);
		$builder = $svc->getSchemaBuilder();
		$this->assertInstanceOf(IGraphQLSchemaBuilder::class, $builder);
	}

	public function test_get_schema_builder_is_cached()
	{
		$svc = new TGraphQLService();
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class);
		$b1 = $svc->getSchemaBuilder();
		$b2 = $svc->getSchemaBuilder();
		$this->assertSame($b1, $b2);
	}

	// -----------------------------------------------------------------------
	// parseRequestBody — JSON POST
	// -----------------------------------------------------------------------

	public function test_parse_json_body_single_operation()
	{
		$body = json_encode(['query' => '{ hello }', 'variables' => null, 'operationName' => null]);
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', $body);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$request = $svc->getApplication()->getRequest();
		$result = $rfl->invoke($svc, $request, 'POST');

		$this->assertSame('{ hello }', $result['query']);
	}

	public function test_parse_json_body_with_variables_object()
	{
		$body = json_encode(['query' => '{ echo(msg: $m) }', 'variables' => ['m' => 'hi']]);
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', $body);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame(['m' => 'hi'], $result['variables']);
	}

	public function test_parse_json_body_with_variables_as_json_string()
	{
		$body = json_encode(['query' => '{ hello }', 'variables' => '{"m":"hi"}']);
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', $body);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame(['m' => 'hi'], $result['variables']);
	}

	public function test_parse_empty_json_body_returns_empty_array()
	{
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', '');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame([], $result);
	}

	public function test_parse_json_body_with_operation_name()
	{
		$body = json_encode(['query' => '{ hello }', 'operationName' => 'HelloOp']);
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', $body);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame('HelloOp', $result['operationName']);
	}

	public function test_parse_json_body_batch_returns_raw_array()
	{
		$body = json_encode([['query' => '{ hello }'], ['query' => '{ hello }']]);
		$svc = $this->makeService(null, [], [], 'POST', 'application/json', $body);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertCount(2, $result);
		$this->assertSame('{ hello }', $result[0]['query']);
	}

	// -----------------------------------------------------------------------
	// parseRequestBody — application/graphql
	// -----------------------------------------------------------------------

	public function test_parse_application_graphql_content_type()
	{
		$svc = $this->makeService(null, [], [], 'POST', 'application/graphql', '{ hello }');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame('{ hello }', $result['query']);
	}

	// -----------------------------------------------------------------------
	// parseRequestBody — GET
	// -----------------------------------------------------------------------

	public function test_parse_get_request_reads_query_string()
	{
		$svc = $this->makeService(null, ['query' => '{ hello }'], [], 'GET', '');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'GET');
		$this->assertSame('{ hello }', $result['query']);
	}

	public function test_parse_get_request_variables_json_string()
	{
		$svc = $this->makeService(
			null,
			['query' => '{ echo(msg: $m) }', 'variables' => '{"m":"greet"}'],
			[],
			'GET'
		);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'GET');
		$this->assertSame(['m' => 'greet'], $result['variables']);
	}

	// -----------------------------------------------------------------------
	// parseRequestBody — multipart/form-data and form-urlencoded
	// -----------------------------------------------------------------------

	public function test_parse_multipart_form_data_operations()
	{
		$operations = json_encode(['query' => '{ hello }']);
		$svc = $this->makeService(null, [], ['operations' => $operations], 'POST', 'multipart/form-data');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame('{ hello }', $result['query']);
	}

	public function test_parse_form_urlencoded_post_fallback()
	{
		$svc = $this->makeService(null, [], ['query' => '{ hello }', 'operationName' => 'Q'], 'POST', 'application/x-www-form-urlencoded');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'parseRequestBody');
		$rfl->setAccessible(true);

		$result = $rfl->invoke($svc, $svc->getApplication()->getRequest(), 'POST');
		$this->assertSame('{ hello }', $result['query']);
		$this->assertSame('Q', $result['operationName']);
	}

	// -----------------------------------------------------------------------
	// isBatchInput
	// -----------------------------------------------------------------------

	public function test_is_batch_input_single_operation_is_false()
	{
		$svc = new TGraphQLService();
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'isBatchInput');
		$rfl->setAccessible(true);

		$this->assertFalse($rfl->invoke($svc, ['query' => '{ hello }']));
	}

	public function test_is_batch_input_array_of_operations_is_true()
	{
		$svc = new TGraphQLService();
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'isBatchInput');
		$rfl->setAccessible(true);

		$this->assertTrue($rfl->invoke($svc, [
			['query' => '{ hello }'],
			['query' => '{ hello }'],
		]));
	}

	public function test_is_batch_input_empty_array_is_false()
	{
		$svc = new TGraphQLService();
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'isBatchInput');
		$rfl->setAccessible(true);

		$this->assertFalse($rfl->invoke($svc, []));
	}

	// -----------------------------------------------------------------------
	// buildValidationRules
	// -----------------------------------------------------------------------

	public function test_build_validation_rules_returns_standard_rules_by_default()
	{
		$svc = new TGraphQLService();
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$this->assertIsArray($rules);
		$this->assertNotEmpty($rules);
	}

	public function test_build_validation_rules_adds_depth_rule_when_set()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryDepth(5);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$types = array_map('get_class', $rules);
		$this->assertContains(\GraphQL\Validator\Rules\QueryDepth::class, $types);
	}

	public function test_build_validation_rules_adds_complexity_rule_when_set()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryComplexity(100);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$types = array_map('get_class', $rules);
		$this->assertContains(\GraphQL\Validator\Rules\QueryComplexity::class, $types);
	}

	public function test_build_validation_rules_adds_disable_introspection_when_disabled()
	{
		$svc = new TGraphQLService();
		$svc->setEnableIntrospection(false);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$types = array_map('get_class', $rules);
		$this->assertContains(\GraphQL\Validator\Rules\DisableIntrospection::class, $types);
	}

	public function test_build_validation_rules_no_depth_rule_when_zero()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryDepth(0);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$types = array_map('get_class', $rules);
		$this->assertNotContains(\GraphQL\Validator\Rules\QueryDepth::class, $types);
	}

	public function test_build_validation_rules_no_complexity_rule_when_zero()
	{
		$svc = new TGraphQLService();
		$svc->setMaxQueryComplexity(0);

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'buildValidationRules');
		$rfl->setAccessible(true);

		$rules = $rfl->invoke($svc);
		$types = array_map('get_class', $rules);
		$this->assertNotContains(\GraphQL\Validator\Rules\QueryComplexity::class, $types);
	}

	// -----------------------------------------------------------------------
	// executeSingleQuery — direct execution
	// -----------------------------------------------------------------------

	public function test_execute_single_query_returns_data()
	{
		$svc = $this->makeService($this->makeSimpleSchema());

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'executeSingleQuery');
		$rfl->setAccessible(true);

		$app = $svc->getApplication();
		$ctx = new TGraphQLContext($app, $app->getRequest());

		$result = $rfl->invoke($svc, $svc->getSchema(), $ctx, ['query' => '{ hello }']);
		$this->assertEmpty($result->errors);
		$this->assertEquals(['hello' => 'world'], $result->data);
	}

	public function test_execute_single_query_with_variables()
	{
		$svc = $this->makeService($this->makeSimpleSchema());

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'executeSingleQuery');
		$rfl->setAccessible(true);

		$app = $svc->getApplication();
		$ctx = new TGraphQLContext($app, $app->getRequest());

		$result = $rfl->invoke($svc, $svc->getSchema(), $ctx, [
			'query' => 'query($m: String) { echo(msg: $m) }',
			'variables' => ['m' => 'ping'],
		]);

		$this->assertEmpty($result->errors);
		$this->assertEquals(['echo' => 'ping'], $result->data);
	}

	public function test_execute_single_query_invalid_query_returns_errors()
	{
		$svc = $this->makeService($this->makeSimpleSchema());

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'executeSingleQuery');
		$rfl->setAccessible(true);

		$app = $svc->getApplication();
		$ctx = new TGraphQLContext($app, $app->getRequest());

		$result = $rfl->invoke($svc, $svc->getSchema(), $ctx, ['query' => '{ nonExistentField }']);
		$this->assertNotEmpty($result->errors);
	}

	// -----------------------------------------------------------------------
	// run() — integration through captured output
	// -----------------------------------------------------------------------

	public function test_run_sends_json_response_for_valid_query()
	{
		$written = '';
		$statusCode = null;
		$contentType = null;

		$schema = $this->makeSimpleSchema();
		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');

		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});
		$response->method('setContentType')->willReturnCallback(
			function (string $ct) use (&$contentType) {
				$contentType = $ct;
			}
		);

		$service = new TestableTGraphQLService();
		$service->setRawBody(json_encode(['query' => '{ hello }']));
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($schema);

		$service->run();

		$decoded = json_decode($written, true);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('data', $decoded);
		$this->assertEquals(['hello' => 'world'], $decoded['data']);
		$this->assertSame('application/json; charset=UTF-8', $contentType);
	}

	public function test_run_returns_405_for_put_method()
	{
		$statusCode = null;
		$written = '';

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('PUT');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('setStatusCode')->willReturnCallback(
			function (int $code) use (&$statusCode) {
				$statusCode = $code;
			}
		);
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->run();

		$this->assertSame(405, $statusCode);
		$decoded = json_decode($written, true);
		$this->assertArrayHasKey('errors', $decoded);
	}

	public function test_run_returns_400_for_batch_when_disabled()
	{
		$statusCode = null;
		$written = '';

		$batchBody = json_encode([['query' => '{ hello }'], ['query' => '{ hello }']]);

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('setStatusCode')->willReturnCallback(
			function (int $code) use (&$statusCode) {
				$statusCode = $code;
			}
		);
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody($batchBody);
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->setEnableBatchedQueries(false);
		$service->run();

		$this->assertSame(400, $statusCode);
		$decoded = json_decode($written, true);
		$this->assertArrayHasKey('errors', $decoded);
	}

	public function test_run_executes_batched_queries_when_enabled()
	{
		$written = '';
		$batchBody = json_encode([['query' => '{ hello }'], ['query' => '{ hello }']]);

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody($batchBody);
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->setEnableBatchedQueries(true);
		$service->run();

		$decoded = json_decode($written, true);
		$this->assertIsArray($decoded);
		$this->assertCount(2, $decoded);
		$this->assertEquals(['hello' => 'world'], $decoded[0]['data']);
		$this->assertEquals(['hello' => 'world'], $decoded[1]['data']);
	}

	public function test_run_returns_500_when_schema_builder_not_configured()
	{
		$statusCode = null;
		$written = '';

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('setStatusCode')->willReturnCallback(
			function (int $code) use (&$statusCode) {
				$statusCode = $code;
			}
		);
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody(json_encode(['query' => '{ hello }']));
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		// No schema or builder configured.
		$service->run();

		$this->assertSame(500, $statusCode);
		$decoded = json_decode($written, true);
		$this->assertArrayHasKey('errors', $decoded);
	}

	public function test_run_get_valid_query()
	{
		$written = '';

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('GET');
		$request->method('getContentType')->willReturn('');
		$request->method('itemAt')->willReturnCallback(
			static fn(string $key) => $key === 'query' ? '{ hello }' : null
		);
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->run();

		$decoded = json_decode($written, true);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('data', $decoded);
		$this->assertEquals(['hello' => 'world'], $decoded['data']);
	}

	public function test_run_application_graphql_content_type()
	{
		$written = '';

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/graphql');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody('{ hello }');
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->run();

		$decoded = json_decode($written, true);
		$this->assertIsArray($decoded);
		$this->assertEquals(['hello' => 'world'], $decoded['data']);
	}

	public function test_run_returns_400_for_invalid_json_body()
	{
		$statusCode = null;
		$written = '';

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);

		$response->method('setStatusCode')->willReturnCallback(function (int $code) use (&$statusCode) {
			$statusCode = $code;
		});
		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody('{invalid json}');
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setSchema($this->makeSimpleSchema());
		$service->run();

		$this->assertSame(400, $statusCode);
		$decoded = json_decode($written, true);
		$this->assertArrayHasKey('errors', $decoded);
	}

	public function test_run_resolves_apq_and_executes()
	{
		$written = '';
		$query = '{ hello }';
		$hash = hash('sha256', $query);

		$store = ['TGraphQLService:graphql:pq:' . $hash => $query];
		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('get')->willReturnCallback(
			static fn(string $key): mixed => $store[$key] ?? false
		);

		$app = $this->createMock(TApplication::class);
		$request = $this->createMock(THttpRequest::class);
		$response = $this->createMock(THttpResponse::class);

		$request->method('getRequestType')->willReturn('POST');
		$request->method('getContentType')->willReturn('application/json');
		$app->method('getRequest')->willReturn($request);
		$app->method('getResponse')->willReturn($response);
		$app->method('getModule')->willReturn($cache);

		$response->method('write')->willReturnCallback(function (string $s) use (&$written) {
			$written .= $s;
		});

		$service = new TestableTGraphQLService();
		$service->setRawBody(json_encode(['extensions' => ['persistedQuery' => ['sha256Hash' => $hash]]]));
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$service->setID('graphql');
		$service->setSchema($this->makeSimpleSchema());
		$service->setCacheID('cache');
		$service->run();

		$decoded = json_decode($written, true);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('data', $decoded);
		$this->assertEquals(['hello' => 'world'], $decoded['data']);
	}

	// -----------------------------------------------------------------------
	// applySharedConfig
	// -----------------------------------------------------------------------

	public function test_apply_shared_config_copies_module_defaults()
	{
		$config = new TGraphQLServiceConfig();
		$config->setMaxQueryDepth(10);
		$config->setMaxQueryComplexity(500);
		$config->setEnableIntrospection(false);
		$config->setEnableBatchedQueries(true);
		$config->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($config);

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$svc->setConfigID('graphql-config');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'applySharedConfig');
		$rfl->setAccessible(true);
		$rfl->invoke($svc);

		$this->assertSame(10, $svc->getMaxQueryDepth());
		$this->assertSame(500, $svc->getMaxQueryComplexity());
		$this->assertFalse($svc->getEnableIntrospection());
		$this->assertTrue($svc->getEnableBatchedQueries());
		$this->assertSame(DebugFlag::INCLUDE_DEBUG_MESSAGE, $svc->getDebugFlag());
	}

	public function test_apply_shared_config_service_depth_overrides_module()
	{
		$config = new TGraphQLServiceConfig();
		$config->setMaxQueryDepth(5);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($config);

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$svc->setMaxQueryDepth(20);
		$svc->setConfigID('graphql-config');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'applySharedConfig');
		$rfl->setAccessible(true);
		$rfl->invoke($svc);

		$this->assertSame(20, $svc->getMaxQueryDepth());
	}

	public function test_apply_shared_config_throws_for_invalid_module()
	{
		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn(new \stdClass());

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$svc->setConfigID('wrong');

		$this->expectException(\Prado\Exceptions\TConfigurationException::class);
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'applySharedConfig');
		$rfl->setAccessible(true);
		$rfl->invoke($svc);
	}

	public function test_apply_shared_config_is_idempotent()
	{
		$config = new TGraphQLServiceConfig();
		$config->setMaxQueryDepth(7);

		$app = $this->createMock(TApplication::class);
		// getModule must be called exactly once — the second applySharedConfig call is a no-op.
		$app->expects($this->once())->method('getModule')->willReturn($config);

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$svc->setConfigID('graphql-config');

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'applySharedConfig');
		$rfl->setAccessible(true);
		$rfl->invoke($svc);
		$rfl->invoke($svc); // second call must be a no-op

		$this->assertSame(7, $svc->getMaxQueryDepth());
	}

	// -----------------------------------------------------------------------
	// createContext
	// -----------------------------------------------------------------------

	public function test_create_context_returns_tgraphqlcontext_with_app_and_request()
	{
		$svc = $this->makeService($this->makeSimpleSchema());

		$rfl = new \ReflectionMethod(TGraphQLService::class, 'createContext');
		$rfl->setAccessible(true);

		$ctx = $rfl->invoke($svc, $svc->getApplication()->getRequest());

		$this->assertInstanceOf(TGraphQLContext::class, $ctx);
		$this->assertSame($svc->getApplication(), $ctx->getApplication());
		$this->assertSame($svc->getApplication()->getRequest(), $ctx->getRequest());
	}

	// -----------------------------------------------------------------------
	// setSchemaBuilderClass — cache reset
	// -----------------------------------------------------------------------

	public function test_set_schema_builder_class_resets_cached_builder_instance()
	{
		$svc = new TGraphQLService();
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class);
		$b1 = $svc->getSchemaBuilder();
		$svc->setSchemaBuilderClass(TestSchemaBuilder::class); // trigger reset
		$b2 = $svc->getSchemaBuilder();
		$this->assertNotSame($b1, $b2);
	}

	// -----------------------------------------------------------------------
	// persistQuery / resolvePersistedQuery
	// -----------------------------------------------------------------------

	public function test_persist_query_returns_false_when_no_cache_id()
	{
		$svc = new TGraphQLService();
		$this->assertFalse($svc->persistQuery('{ hello }'));
	}

	public function test_persist_query_returns_false_when_cache_module_not_found()
	{
		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn(null);

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);
		$svc->setCacheID('cache');

		$this->assertFalse($svc->persistQuery('{ hello }'));
	}

	public function test_persist_query_and_resolve_via_cache()
	{
		$store = [];
		$cache = $this->createMock(\Prado\Caching\TCache::class);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store): mixed {
				return $store[$key] ?? false;
			}
		);

		$app = $this->createMock(TApplication::class);
		$app->method('getModule')->willReturn($cache);

		$svc = new TGraphQLService();
		$rp = new \ReflectionProperty(\Prado\Prado::class, '_application');
		$rp->setAccessible(true);
		$rp->setValue(null, $app);

		$svc->setID('graphql');

		$svc->setCacheID('cache');

		$query = '{ hello }';
		$this->assertTrue($svc->persistQuery($query));

		// Resolve via the protected method
		$hash = hash('sha256', $query);
		$rfl = new \ReflectionMethod(TGraphQLService::class, 'resolvePersistedQuery');
		$rfl->setAccessible(true);

		$resolved = $rfl->invoke($svc, $hash);
		$this->assertSame($query, $resolved);
	}

	// -----------------------------------------------------------------------
	// Class contract
	// -----------------------------------------------------------------------

	public function test_is_tservice()
	{
		$svc = new TGraphQLService();
		$this->assertInstanceOf(\Prado\TService::class, $svc);
	}

	public function test_is_tcomponent()
	{
		$svc = new TGraphQLService();
		$this->assertInstanceOf(\Prado\TComponent::class, $svc);
	}

}


// -----------------------------------------------------------------------
// Test doubles
// -----------------------------------------------------------------------

/**
 * Testable subclass that lets tests inject an arbitrary raw request body
 * without touching the real PHP input stream.
 */
class TestableTGraphQLService extends TGraphQLService
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

/**
 * Minimal IGraphQLSchemaBuilder implementation used only in tests.
 */
class TestSchemaBuilder implements IGraphQLSchemaBuilder
{
	public function buildSchema(TGraphQLService $service): Schema
	{
		return new Schema([
			'query' => new ObjectType([
				'name' => 'Query',
				'fields' => [
					'hello' => [
						'type' => Type::string(),
						'resolve' => fn() => 'world',
					],
				],
			]),
		]);
	}
}
