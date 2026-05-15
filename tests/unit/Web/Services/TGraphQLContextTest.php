<?php

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Prado\TApplication;
use Prado\Web\Services\TGraphQLContext;
use Prado\Web\THttpRequest;
use Prado\Web\THttpResponse;

/**
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado.Web.Services
 */
class TGraphQLContextTest extends PHPUnit\Framework\TestCase
{
	private TApplication $_app;
	private THttpRequest $_request;

	protected function setUp(): void
	{
		$this->_app = $this->createMock(TApplication::class);
		$this->_request = $this->createMock(THttpRequest::class);
	}

	public function test_constructor_stores_application_and_request()
	{
		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertSame($this->_app, $ctx->getApplication());
		$this->assertSame($this->_request, $ctx->getRequest());
	}

	public function test_get_application_returns_application()
	{
		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertInstanceOf(TApplication::class, $ctx->getApplication());
	}

	public function test_get_request_returns_request()
	{
		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertInstanceOf(THttpRequest::class, $ctx->getRequest());
	}

	public function test_get_response_delegates_to_application()
	{
		$response = $this->createMock(THttpResponse::class);
		$this->_app->method('getResponse')->willReturn($response);

		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertSame($response, $ctx->getResponse());
	}

	public function test_get_user_returns_null_when_no_auth_module()
	{
		$this->_app->method('getUser')->willReturn(null);
		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertNull($ctx->getUser());
	}

	public function test_get_user_returns_user_when_authenticated()
	{
		$user = $this->createMock(\Prado\Security\IUser::class);
		$this->_app->method('getUser')->willReturn($user);

		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertSame($user, $ctx->getUser());
	}

	public function test_context_is_tcomponent()
	{
		$ctx = new TGraphQLContext($this->_app, $this->_request);
		$this->assertInstanceOf(\Prado\TComponent::class, $ctx);
	}

	public function test_context_passed_to_resolver()
	{
		$capturedContext = null;

		$schema = new Schema([
			'query' => new ObjectType([
				'name' => 'Query',
				'fields' => [
					'whoami' => [
						'type' => Type::string(),
						'resolve' => function ($root, array $args, $context) use (&$capturedContext): string {
							$capturedContext = $context;
							return 'ok';
						},
					],
				],
			]),
		]);

		$ctx = new TGraphQLContext($this->_app, $this->_request);

		$result = \GraphQL\GraphQL::executeQuery($schema, '{ whoami }', null, $ctx);
		$this->assertEmpty($result->errors);
		$this->assertSame($ctx, $capturedContext);
		$this->assertEquals(['data' => ['whoami' => 'ok']], $result->toArray());
	}
}
