<?php

/**
 * TGraphQLContext class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-graphql
 * @license https://github.com/pradosoft/prado-graphql/blob/master/LICENSE
 */

namespace Prado\Web\Services;

use Prado\TApplication;
use Prado\Web\THttpRequest;
use Prado\Web\THttpResponse;

/**
 * TGraphQLContext carries per-request context through the GraphQL resolver graph.
 *
 * An instance of TGraphQLContext is created by {@see TGraphQLService::createContext}
 * and passed as the `$contextValue` argument to
 * {@see \GraphQL\GraphQL::executeQuery()}. Every resolver in the schema receives
 * it as its third positional parameter.
 *
 * The context provides resolvers with:
 *
 * - The PRADO {@see TApplication} instance — for accessing modules, cache,
 *   auth, and any application-level service.
 * - The current {@see THttpRequest} — for reading headers, cookies, or raw
 *   request data inside a resolver.
 * - The current {@see THttpResponse} — for setting cookies or custom headers
 *   from within a resolver (use sparingly; prefer returning data).
 * - The authenticated user — via {@see getUser()}, which delegates to
 *   `TApplication::getUser()` and returns null when no auth module is
 *   configured or the request is anonymous.
 *
 * Example resolver using the context:
 * ```php
 * 'resolve' => function ($root, array $args, TGraphQLContext $ctx): ?array {
 *     $user = $ctx->getUser();
 *     if ($user === null || !$user->isInRole('admin')) {
 *         throw new \GraphQL\Error\Error('Unauthorized');
 *     }
 *     return MyModel::findById($args['id']);
 * },
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TGraphQLContext extends \Prado\TComponent
{
	/**
	 * Constructor.
	 * @param TApplication $_application the active PRADO application.
	 * @param THttpRequest $_request the current HTTP request.
	 */
	public function __construct(
		private TApplication $_application,
		private THttpRequest $_request
	) {
		parent::__construct();
	}

	/**
	 * Returns the PRADO application instance.
	 *
	 * Use this to access application modules, the cache layer, the security
	 * manager, or any other application-level component from within a resolver.
	 *
	 * @return TApplication the application.
	 */
	public function getApplication(): TApplication
	{
		return $this->_application;
	}

	/**
	 * Returns the current HTTP request.
	 *
	 * Provides access to request headers, cookies, query-string parameters,
	 * and the raw request body from within a resolver.
	 *
	 * @return THttpRequest the HTTP request.
	 */
	public function getRequest(): THttpRequest
	{
		return $this->_request;
	}

	/**
	 * Returns the current HTTP response.
	 *
	 * Delegates to {@see TApplication::getResponse()}. Typically used to set
	 * response cookies or custom headers from within a mutation resolver.
	 *
	 * @return THttpResponse the HTTP response.
	 */
	public function getResponse(): THttpResponse
	{
		return $this->_application->getResponse();
	}

	/**
	 * Returns the currently authenticated user, or null for anonymous requests.
	 *
	 * Delegates to {@see TApplication::getUser()}. Returns null when no auth
	 * module is configured or when the request carries no valid credentials.
	 *
	 * @return null|\Prado\Security\IUser the authenticated user, or null.
	 */
	public function getUser(): ?\Prado\Security\IUser
	{
		return $this->_application->getUser();
	}
}
