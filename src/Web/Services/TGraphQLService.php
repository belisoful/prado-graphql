<?php

/**
 * TGraphQLService class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-graphql
 * @license https://github.com/pradosoft/prado-graphql/blob/master/LICENSE
 */

namespace Prado\Web\Services;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error as GraphQLError;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Prado\Exceptions\TConfigurationException;
use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\THttpRequest;

/**
 * TGraphQLService handles GraphQL requests in a PRADO application.
 *
 * TGraphQLService is a {@see \Prado\Web\Services\TService} implementation that
 * accepts GraphQL document requests, executes them against a user-supplied schema,
 * and returns JSON responses.  It uses the
 * {@link https://webonyx.github.io/graphql-php/ webonyx/graphql-php} library for
 * all parsing, validation, and execution work.
 *
 * **Why TGraphQLService does NOT extend TRpcService**
 *
 * `TRpcService` dispatches by procedure name (`method → callable`).
 * GraphQL's model is fundamentally different: a single document drives a nested
 * resolver graph where each field resolves independently, context threads through
 * all resolvers, the schema is introspectable, mutations are typed and validated,
 * and subscriptions require real-time transport.  The `TRpcProtocol`
 * encode/decode contract has no mapping to any of these concepts.
 * `TGraphQLService` therefore extends `TService` directly and is entirely new.
 *
 * **Minimum setup — application.xml:**
 * ```xml
 * <services>
 *     <service id="graphql"
 *              class="Prado\Web\Services\TGraphQLService"
 *              SchemaBuilderClass="MyApp\GraphQL\AppSchemaBuilder" />
 * </services>
 * ```
 *
 * **With shared config module:**
 * ```xml
 * <modules>
 *     <module id="gql-cfg"
 *             class="Prado\Web\Services\TGraphQLServiceConfig"
 *             EnableIntrospection="false"
 *             MaxQueryDepth="10"
 *             MaxQueryComplexity="500" />
 * </modules>
 * <services>
 *     <service id="graphql"
 *              class="Prado\Web\Services\TGraphQLService"
 *              SchemaBuilderClass="MyApp\GraphQL\AppSchemaBuilder"
 *              ConfigID="gql-cfg" />
 * </services>
 * ```
 *
 * **Request content types accepted:**
 * - `application/json`  — `{"query":"...","variables":{...},"operationName":"..."}`
 * - `application/graphql` — raw query string body
 * - `multipart/form-data` — standard form POST or GraphQL multipart upload spec
 * - GET request — `?query=...&variables=...&operationName=...`
 *
 * **Persisted queries (APQ):**
 *
 * When `CacheID` is set to a PRADO cache module ID, the service looks for a
 * cached query string under `TGraphQLService:<serviceId>:pq:<hash>` whenever
 * the request contains only an `extensions.persistedQuery.sha256Hash` and an
 * empty or absent `query` field.  Store a persisted query with:
 * ```php
 * $cache->set('TGraphQLService:graphql:pq:' . hash('sha256', $query), $query);
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 *
 * @method mixed dyCreateContext(TGraphQLContext $context) dynamic event — lets attached behaviors
 *   replace or decorate the context object before it is passed to the resolver graph.
 * @method mixed dyBuildValidationRules(array $rules) dynamic event — lets attached behaviors
 *   append custom validation rules to the execution pass.
 * @method array dyFormatError(array $formatted, GraphQLError $error) dynamic event — lets attached
 *   behaviors replace or augment a single error's serialized representation before the response is
 *   serialised. $formatted is the default-formatted error array; $error is the raw Error object.
 *   Return a modified array to override the default format.
 */
class TGraphQLService extends \Prado\TService
{
	// =========================================================================
	// Private state
	// =========================================================================

	/** @var string FQCN implementing IGraphQLSchemaBuilder. */
	private string $_schemaBuilderClass = '';

	/** @var bool whether introspection queries are accepted. */
	private bool $_enableIntrospection = true;

	/** @var bool whether an array of operations is accepted as a single request. */
	private bool $_enableBatchedQueries = false;

	/** @var int maximum query nesting depth (0 = unlimited). */
	private int $_maxQueryDepth = 0;

	/** @var int maximum query complexity score (0 = unlimited). */
	private int $_maxQueryComplexity = 0;

	/** @var int DebugFlag bitmask for ExecutionResult::toArray(). */
	private int $_debugFlag = DebugFlag::NONE;

	/** @var string ID of a PRADO cache module used for persisted queries. */
	private string $_cacheID = '';

	/** @var string ID of a TGraphQLServiceConfig module supplying shared defaults. */
	private string $_configID = '';

	/** @var bool whether shared config has already been applied. */
	private bool $_configApplied = false;

	/** @var null|IGraphQLSchemaBuilder lazily instantiated schema builder. */
	private ?IGraphQLSchemaBuilder $_schemaBuilder = null;

	/** @var null|Schema schema cached for the duration of this request. */
	private ?Schema $_schema = null;

	// =========================================================================
	// TService — lifecycle
	// =========================================================================

	/**
	 * Initialises the service from XML configuration.
	 *
	 * Reads all public properties from XML attributes, then applies any shared
	 * defaults from a {@see TGraphQLServiceConfig} module if {@see setConfigID
	 * ConfigID} was set. Service-level properties always win over config-module
	 * defaults.
	 *
	 * @param mixed $config the XML configuration element (may be null).
	 */
	public function init($config): void
	{
		parent::init($config);
		$this->applySharedConfig();
	}

	/**
	 * Handles a GraphQL HTTP request.
	 *
	 * Accepted methods: GET (queries only) and POST.
	 * Accepted content types: `application/json`, `application/graphql`,
	 * `multipart/form-data`, `application/x-www-form-urlencoded`.
	 *
	 * The response is always `application/json`.  HTTP 200 is returned for
	 * all valid GraphQL responses (even those containing field errors). HTTP
	 * 400 is returned for malformed requests and HTTP 405 for wrong methods.
	 */
	public function run(): void
	{
		$request = $this->getRequest();
		$response = $this->getResponse();

		$method = strtoupper((string) $request->getRequestType());

		if (!in_array($method, ['GET', 'POST'], true)) {
			$response->setStatusCode(405, 'Method Not Allowed');
			$response->appendHeader('Allow: GET, POST');
			$this->sendJsonResponse(['errors' => [['message' => Prado::localize('graphqlservice_method_not_allowed', [$method])]]]);
			return;
		}

		try {
			$schema = $this->getSchema();
			$context = $this->createContext($request);
			$input = $this->parseRequestBody($request, $method);

			// Batch request
			if ($this->isBatchInput($input)) {
				if (!$this->getEnableBatchedQueries()) {
					$response->setStatusCode(400, 'Bad Request');
					$this->sendJsonResponse(['errors' => [['message' => Prado::localize('graphqlservice_batch_not_allowed')]]]);
					return;
				}
				$results = [];
				foreach ($input as $operation) {
					$results[] = $this->executeSingleQuery($schema, $context, (array) $operation)->toArray($this->getDebugFlag());
				}
				$this->sendJsonResponse($results);
				return;
			}

			$result = $this->executeSingleQuery($schema, $context, $input);
			$this->sendJsonResponse($result->toArray($this->getDebugFlag()));
		} catch (TConfigurationException $e) {
			$response->setStatusCode(500, 'Internal Server Error');
			$this->sendJsonResponse(['errors' => [['message' => $e->getMessage()]]]);
		} catch (\JsonException $e) {
			$response->setStatusCode(400, 'Bad Request');
			$this->sendJsonResponse(['errors' => [['message' => Prado::localize('graphqlservice_request_parse_failed', [$e->getMessage()])]]]);
		} catch (\Throwable $e) {
			$response->setStatusCode(500, 'Internal Server Error');
			$this->sendJsonResponse(['errors' => [['message' => $e->getMessage()]]]);
		}
	}

	// =========================================================================
	// Schema and schema builder
	// =========================================================================

	/**
	 * Returns the GraphQL schema, building it on the first call.
	 *
	 * The schema is cached on this service instance for the duration of the
	 * request. Call {@see setSchema} to inject a pre-built schema (useful in
	 * tests or for schema stitching).
	 *
	 * @throws TConfigurationException if no SchemaBuilderClass has been set.
	 * @return Schema the GraphQL schema.
	 */
	public function getSchema(): Schema
	{
		if ($this->getSchemaDirect() === null) {
			$this->setSchema($schema = $this->getSchemaBuilder()->buildSchema($this));
		}
		return $this->getSchemaDirect();
	}
	/**
	 * Returns the GraphQL schema, building it on the first call.
	 *
	 * The schema is cached on this service instance for the duration of the
	 * request. Call {@see setSchema} to inject a pre-built schema (useful in
	 * tests or for schema stitching).
	 *
	 * @throws TConfigurationException if no SchemaBuilderClass has been set.
	 * @return ?Schema the GraphQL schema.
	 */
	protected function getSchemaDirect(): ?Schema
	{
		return $this->_schema;
	}

	/**
	 * Injects a pre-built schema, bypassing the schema builder.
	 *
	 * Useful in tests and for schema-stitching scenarios where the schema is
	 * assembled outside PRADO's configuration machinery.
	 *
	 * @param Schema $schema the schema to use for this request.
	 */
	public function setSchema(Schema $schema): void
	{
		$this->_schema = $schema;
	}

	/**
	 * Returns the schema builder instance, creating it if needed.
	 *
	 * @throws TConfigurationException if no SchemaBuilderClass is set or if the
	 *   class does not implement {@see IGraphQLSchemaBuilder}.
	 * @return IGraphQLSchemaBuilder the schema builder.
	 */
	public function getSchemaBuilder(): IGraphQLSchemaBuilder
	{
		if ($this->_schemaBuilder === null) {
			if ($this->getSchemaBuilderClass() === '') {
				throw new TConfigurationException('graphqlservice_schema_builder_required');
			}
			$builder = Prado::createComponent($this->getSchemaBuilderClass());
			if (!($builder instanceof IGraphQLSchemaBuilder)) {
				throw new TConfigurationException('graphqlservice_schema_builder_invalid', $this->getSchemaBuilderClass());
			}
			$this->_schemaBuilder = $builder;
		}
		return $this->_schemaBuilder;
	}

	// =========================================================================
	// Request parsing
	// =========================================================================

	/**
	 * Returns the raw HTTP request body.
	 *
	 * Isolated into a protected method so subclasses (and tests) can override
	 * it without touching the real PHP input stream.
	 *
	 * @return string the raw request body bytes.
	 */
	protected function getRequestRawBody(): string
	{
		return (string) file_get_contents('php://input');
	}

	/**
	 * Parses the HTTP request into a GraphQL input array.
	 *
	 * For GET requests the query, variables, and operationName come from the
	 * URL query string. For POST requests the content type governs parsing:
	 *
	 * - `application/json`  — JSON-decoded body.
	 * - `application/graphql` — body used as the raw query string.
	 * - `multipart/form-data` — GraphQL multipart upload spec: `operations`
	 *   JSON field contains the operation(s).
	 * - All others — `$_POST` parameters.
	 *
	 * @param THttpRequest $request the current HTTP request.
	 * @param string $method the uppercased HTTP method ('GET' or 'POST').
	 * @throws \JsonException if the JSON body cannot be decoded.
	 * @return array the parsed GraphQL input (single operation or batch array).
	 */
	protected function parseRequestBody(THttpRequest $request, string $method): array
	{
		if ($method === 'GET') {
			return $this->normalizeInput([
				'query' => $request->itemAt('query') ?? '',
				'variables' => $request->itemAt('variables'),
				'operationName' => $request->itemAt('operationName'),
			]);
		}

		$contentType = (string) ($request->getContentType() ?? '');

		if (str_starts_with($contentType, 'application/json')) {
			$rawBody = $this->getRequestRawBody();
			if ($rawBody === '') {
				return [];
			}
			$decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
			if (is_array($decoded)) {
				return $this->isBatchInput($decoded)
					? $decoded
					: $this->normalizeInput($decoded);
			}
			return [];
		}

		if (str_starts_with($contentType, 'application/graphql')) {
			return ['query' => $this->getRequestRawBody()];
		}

		// multipart/form-data — GraphQL multipart request spec
		$operations = $request->itemAt('operations');
		if ($operations !== null) {
			$decoded = json_decode((string) $operations, true, 512, JSON_THROW_ON_ERROR);
			if (is_array($decoded)) {
				return $this->isBatchInput($decoded)
					? $decoded
					: $this->normalizeInput($decoded);
			}
		}

		// application/x-www-form-urlencoded or bare POST
		return $this->normalizeInput([
			'query' => $request->itemAt('query') ?? '',
			'variables' => $request->itemAt('variables'),
			'operationName' => $request->itemAt('operationName'),
		]);
	}

	/**
	 * Normalises a single-operation input array.
	 *
	 * Decodes a JSON-encoded `variables` string if present and ensures the
	 * `query`, `variables`, and `operationName` keys all exist.
	 *
	 * @param array $input a raw input array.
	 * @throws \JsonException if `variables` is a non-decodable string.
	 * @return array the normalised input.
	 */
	protected function normalizeInput(array $input): array
	{
		$variables = $input['variables'] ?? null;
		if (is_string($variables) && $variables !== '') {
			$variables = json_decode($variables, true, 512, JSON_THROW_ON_ERROR);
		}
		return [
			'query' => $input['query'] ?? '',
			'variables' => is_array($variables) ? $variables : null,
			'operationName' => isset($input['operationName']) && $input['operationName'] !== ''
				? (string) $input['operationName']
				: null,
			'extensions' => $input['extensions'] ?? null,
		];
	}

	/**
	 * Returns true if $input is a list of operations (batch request).
	 *
	 * A batch is a non-empty, numerically-indexed array whose first element is
	 * itself an array (an operation object).
	 *
	 * @param array $input the decoded request body.
	 * @return bool true if this is a batch.
	 */
	protected function isBatchInput(array $input): bool
	{
		return array_is_list($input) && isset($input[0]) && is_array($input[0]);
	}

	// =========================================================================
	// Execution
	// =========================================================================

	/**
	 * Creates the per-request GraphQL context.
	 *
	 * Raises the `dyCreateContext` dynamic event so attached behaviors can
	 * replace or augment the context before it enters the resolver graph.
	 *
	 * @param THttpRequest $request the current HTTP request.
	 * @return TGraphQLContext the context object.
	 */
	protected function createContext(THttpRequest $request): TGraphQLContext
	{
		$context = new TGraphQLContext($this->getApplication(), $request);
		return $this->dyCreateContext($context);
	}

	/**
	 * Executes a single GraphQL operation and returns the result.
	 *
	 * Resolves persisted queries (APQ) if CacheID is configured and the input
	 * contains `extensions.persistedQuery.sha256Hash` with no inline query.
	 *
	 * @param Schema $schema the compiled schema.
	 * @param TGraphQLContext $context the per-request context.
	 * @param array $input a normalised single-operation input array.
	 * @return ExecutionResult the execution result.
	 */
	protected function executeSingleQuery(Schema $schema, TGraphQLContext $context, array $input): ExecutionResult
	{
		$query = (string) ($input['query'] ?? '');
		$variables = $input['variables'] ?? null;
		$operationName = $input['operationName'] ?? null;

		// Resolve persisted query by hash (Automatic Persisted Queries)
		if ($query === '' && $this->getCacheID() !== '') {
			$hash = $input['extensions']['persistedQuery']['sha256Hash'] ?? null;
			if ($hash !== null) {
				$query = $this->resolvePersistedQuery((string) $hash) ?? '';
			}
		}

		$validationRules = $this->buildValidationRules();

		$result = GraphQL::executeQuery(
			$schema,
			$query,
			null,
			$context,
			$variables,
			$operationName,
			null,
			$validationRules ?: null
		);
		$result->setErrorFormatter(fn (GraphQLError $e): array => $this->formatError($e));
		return $result;
	}

	/**
	 * Formats a single GraphQL error into a serialisable array.
	 *
	 * Produces the default webonyx formatted-error array and then passes it
	 * through the `dyFormatError` dynamic event, allowing attached behaviors to
	 * augment, redact, or replace the representation (e.g. add error codes,
	 * log to an error tracker, or remove internal message text in production).
	 *
	 * Note: debug information is layered on top by {@see \GraphQL\Error\FormattedError::prepareFormatter}
	 * according to the {@see getDebugFlag()} bitmask; behaviors should not duplicate that work.
	 *
	 * @param GraphQLError $error the raw GraphQL error.
	 * @return array the formatted error array (must conform to the GraphQL spec).
	 */
	protected function formatError(GraphQLError $error): array
	{
		$formatted = FormattedError::createFromException($error);
		return $this->dyFormatError($formatted, $error);
	}

	/**
	 * Builds the list of validation rules applied to every query.
	 *
	 * Starts from webonyx's standard rule set, then appends:
	 * - {@see QueryDepth} when {@see getMaxQueryDepth()} > 0.
	 * - {@see QueryComplexity} when {@see getMaxQueryComplexity()} > 0.
	 * - {@see DisableIntrospection} when {@see getEnableIntrospection()} is false.
	 *
	 * Raises the `dyBuildValidationRules` dynamic event so attached behaviors
	 * can append custom rules without subclassing.
	 *
	 * @return array the ordered list of validation rule instances.
	 */
	protected function buildValidationRules(): array
	{
		$rules = GraphQL::getStandardValidationRules();

		if ($this->getMaxQueryDepth() > 0) {
			$rules[] = new QueryDepth($this->getMaxQueryDepth());
		}

		if ($this->getMaxQueryComplexity() > 0) {
			$rules[] = new QueryComplexity($this->getMaxQueryComplexity());
		}

		if (!$this->getEnableIntrospection()) {
			$rules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
		}

		return $this->dyBuildValidationRules($rules);
	}

	// =========================================================================
	// Persisted queries
	// =========================================================================

	/**
	 * Looks up a persisted query string from the PRADO cache.
	 *
	 * The cache key is `TGraphQLService:<serviceId>:pq:<sha256Hash>`.
	 *
	 * @param string $hash the SHA-256 hash of the persisted query.
	 * @return null|string the cached query string, or null if not found.
	 */
	protected function resolvePersistedQuery(string $hash): ?string
	{
		if ($this->getCacheID() === '') {
			return null;
		}
		$cache = $this->getApplication()->getModule($this->getCacheID());
		if (!($cache instanceof \Prado\Caching\TCache)) {
			return null;
		}
		$value = $cache->get('TGraphQLService:' . $this->getID() . ':pq:' . $hash);
		return is_string($value) ? $value : null;
	}

	/**
	 * Stores a query string in the PRADO cache under its SHA-256 hash.
	 *
	 * @param string $query the GraphQL query document to persist.
	 * @param int $expire cache TTL in seconds; 0 means never expire.
	 * @return bool true if the value was successfully cached.
	 */
	public function persistQuery(string $query, int $expire = 0): bool
	{
		if ($this->getCacheID() === '') {
			return false;
		}
		$cache = $this->getApplication()->getModule($this->getCacheID());
		if (!($cache instanceof \Prado\Caching\TCache)) {
			return false;
		}
		$hash = hash('sha256', $query);
		return $cache->set('TGraphQLService:' . $this->getID() . ':pq:' . $hash, $query, $expire);
	}

	// =========================================================================
	// Response helpers
	// =========================================================================

	/**
	 * Writes a JSON-encoded response body and sets the content type.
	 *
	 * @param array $data the data to encode as JSON.
	 */
	protected function sendJsonResponse(array $data): void
	{
		$response = $this->getResponse();
		$response->setContentType('application/json; charset=UTF-8');
		$response->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	// =========================================================================
	// Shared-config application
	// =========================================================================

	/**
	 * Applies defaults from a {@see TGraphQLServiceConfig} module.
	 *
	 * Called once from {@see init}. Config-module values only take effect when
	 * the corresponding service-level property has not been explicitly set
	 * (i.e., still holds its compiled-in default).
	 */
	protected function applySharedConfig(): void
	{
		if ($this->isConfigApplied() || $this->getConfigID() === '') {
			$this->setConfigApplied(true);
			return;
		}
		$module = $this->getApplication()->getModule($this->getConfigID());
		if (!($module instanceof TGraphQLServiceConfig)) {
			throw new TConfigurationException('graphqlserviceconfig_invalid', $this->getConfigID());
		}
		// Apply module defaults; service-level properties already set by XML
		// attributes will have already overridden these via PRADO's property
		// setter mechanism, so we only copy values the service has not
		// explicitly overridden (i.e., those still at their compile-time default).
		if ($this->getMaxQueryDepth() === 0) {
			$this->setMaxQueryDepth($module->getMaxQueryDepth());
		}
		if ($this->getMaxQueryComplexity() === 0) {
			$this->setMaxQueryComplexity($module->getMaxQueryComplexity());
		}
		if ($this->getDebugFlag() === DebugFlag::NONE) {
			$this->setDebugFlag($module->getDebugFlag());
		}
		// Boolean flags default to true/false — only copy when service still
		// holds the constructor default (true for introspection, false for batch).
		if ($this->getEnableIntrospection()) {
			$this->setEnableIntrospection($module->getEnableIntrospection());
		}
		if (!$this->getEnableBatchedQueries()) {
			$this->setEnableBatchedQueries($module->getEnableBatchedQueries());
		}
		$this->setConfigApplied(true);
	}

	// =========================================================================
	// Property accessors
	// =========================================================================

	/**
	 * @return string the FQCN of the schema builder. Empty string when unset.
	 */
	public function getSchemaBuilderClass(): string
	{
		return $this->_schemaBuilderClass;
	}

	/**
	 * Sets the class name of the {@see IGraphQLSchemaBuilder} implementation.
	 *
	 * Must be set before any request is handled. The class is instantiated
	 * lazily on the first call to {@see getSchemaBuilder()}.
	 *
	 * @param mixed $value the fully-qualified class name.
	 */
	public function setSchemaBuilderClass($value): void
	{
		$this->_schemaBuilderClass = TPropertyValue::ensureString($value);
		// Reset cached builder/schema so the new class takes effect.
		$this->resetSchemaCache();
	}

	/**
	 * @return bool whether GraphQL introspection queries are accepted. Defaults to true.
	 */
	public function getEnableIntrospection(): bool
	{
		return $this->_enableIntrospection;
	}

	/**
	 * Enables or disables GraphQL introspection.
	 *
	 * When false, a {@see DisableIntrospection} validation rule is added to
	 * every execution pass, causing introspection queries to return an error
	 * before any resolution occurs.
	 *
	 * @param mixed $value true to allow introspection (default); false to block it.
	 */
	public function setEnableIntrospection($value): void
	{
		$this->_enableIntrospection = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return bool whether batched query arrays are accepted. Defaults to false.
	 */
	public function getEnableBatchedQueries(): bool
	{
		return $this->_enableBatchedQueries;
	}

	/**
	 * Enables or disables batched query support.
	 *
	 * When true, a JSON array of operation objects is accepted and each is
	 * executed sequentially; an array of results is returned.
	 *
	 * @param mixed $value true to accept batched requests.
	 */
	public function setEnableBatchedQueries($value): void
	{
		$this->_enableBatchedQueries = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * @return int the maximum query nesting depth. 0 means no limit.
	 */
	public function getMaxQueryDepth(): int
	{
		return $this->_maxQueryDepth;
	}

	/**
	 * Sets the maximum allowed query nesting depth.
	 *
	 * When greater than 0, a {@see QueryDepth} validation rule is added.
	 *
	 * @param mixed $value depth limit; 0 disables the check.
	 */
	public function setMaxQueryDepth($value): void
	{
		$this->_maxQueryDepth = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * @return int the maximum query complexity score. 0 means no limit.
	 */
	public function getMaxQueryComplexity(): int
	{
		return $this->_maxQueryComplexity;
	}

	/**
	 * Sets the maximum allowed query complexity score.
	 *
	 * When greater than 0, a {@see QueryComplexity} validation rule is added.
	 *
	 * @param mixed $value complexity limit; 0 disables the check.
	 */
	public function setMaxQueryComplexity($value): void
	{
		$this->_maxQueryComplexity = max(0, TPropertyValue::ensureInteger($value));
	}

	/**
	 * Returns the {@see DebugFlag} bitmask passed to
	 * {@see ExecutionResult::toArray()}.
	 *
	 * @return int the debug flag bitmask.
	 */
	public function getDebugFlag(): int
	{
		return $this->_debugFlag;
	}

	/**
	 * Sets the {@see DebugFlag} bitmask.
	 *
	 * Compose the value from {@see DebugFlag} constants. Set to 0 in production.
	 *
	 * @param mixed $value a {@see DebugFlag} bitmask.
	 */
	public function setDebugFlag($value): void
	{
		$this->_debugFlag = TPropertyValue::ensureInteger($value);
	}

	/**
	 * @return string the PRADO cache module ID used for persisted queries. Empty string when unset.
	 */
	public function getCacheID(): string
	{
		return $this->_cacheID;
	}

	/**
	 * Sets the PRADO cache module ID for persisted-query storage.
	 *
	 * When set, the service resolves APQ hashes by looking up the query string
	 * in the named cache module.  Any {@see \Prado\Caching\TCache} subclass
	 * (APCu, Memcached, MongoDB, etc.) can be used.
	 *
	 * @param mixed $value the module ID (e.g. 'cache').
	 */
	public function setCacheID($value): void
	{
		$this->_cacheID = TPropertyValue::ensureString($value);
	}

	/**
	 * @return string the ID of a {@see TGraphQLServiceConfig} module. Empty string when unset.
	 */
	public function getConfigID(): string
	{
		return $this->_configID;
	}

	/**
	 * Sets the ID of a {@see TGraphQLServiceConfig} module providing shared defaults.
	 *
	 * Must be set before {@see init} is called (i.e., in the XML configuration).
	 *
	 * @param mixed $value the module ID.
	 */
	public function setConfigID($value): void
	{
		$this->_configID = TPropertyValue::ensureString($value);
	}

	// =========================================================================
	// Protected implementation-detail accessors
	// =========================================================================

	/**
	 * Returns whether the shared config has already been applied.
	 *
	 * @return bool true once {@see applySharedConfig} has run.
	 */
	protected function isConfigApplied(): bool
	{
		return $this->_configApplied;
	}

	/**
	 * Records whether the shared config has been applied.
	 *
	 * @param bool $value true after {@see applySharedConfig} completes.
	 */
	protected function setConfigApplied(bool $value): void
	{
		$this->_configApplied = $value;
	}

	/**
	 * Clears the lazily-cached schema and schema-builder instances.
	 *
	 * Called by {@see setSchemaBuilderClass} so that the next call to
	 * {@see getSchema} or {@see getSchemaBuilder} rebuilds from the new class.
	 */
	protected function resetSchemaCache(): void
	{
		$this->_schemaBuilder = null;
		$this->_schema = null;
	}
}
