<?php

/**
 * TGraphQLServiceConfig class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-graphql
 * @license https://github.com/pradosoft/prado-graphql/blob/master/LICENSE
 */

namespace Prado\Web\Services;

use Prado\TPropertyValue;

/**
 * TGraphQLServiceConfig provides shared configuration for GraphQL endpoints in a
 * PRADO application and acts as the Composer bootstrap class for this extension.
 *
 * When multiple {@see TGraphQLService} instances share the same security or
 * performance policy, configure them once here and point each service at this
 * module via its {@see TGraphQLService::setConfigID ConfigID} property.
 *
 * Example application.xml usage:
 * ```xml
 * <modules>
 *     <!-- id is the Composer package name; class is resolved automatically
 *          from installed.json and must NOT be repeated here. -->
 *     <module id="belisoful/prado-graphql"
 *             EnableIntrospection="false"
 *             MaxQueryDepth="10"
 *             MaxQueryComplexity="500"
 *             EnableBatchedQueries="false" />
 * </modules>
 * <services>
 *     <service id="graphql"
 *              class="Prado\Web\Services\TGraphQLService"
 *              SchemaBuilderClass="MyApp\GraphQL\AppSchemaBuilder"
 *              ConfigID="belisoful/prado-graphql" />
 * </services>
 * ```
 *
 * Properties defined here act as **defaults**. Any same-named property set
 * directly on a {@see TGraphQLService} instance will override the shared
 * value for that service only.
 *
 * **Composer bootstrap**
 *
 * This class is declared in `composer.json` under `extra.bootstrap` as the
 * extension entry point.  PRADO's extension loader requires the bootstrap to
 * be a {@see \Prado\TModule} subclass; services (which extend {@see \Prado\TService})
 * cannot fulfil this role.  When the package is installed as a Composer
 * dependency PRADO registers this module automatically under the package name
 * `belisoful/prado-graphql` — no `class` attribute in `application.xml` is
 * needed (and specifying one would throw).  The module does not participate in
 * the application lifecycle unless properties are configured in `<modules>`.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
class TGraphQLServiceConfig extends \Prado\TModule
{
	/**
	 * @var bool whether GraphQL introspection queries are enabled. Defaults to true.
	 */
	private bool $_enableIntrospection = true;

	/**
	 * @var int maximum allowed query depth. 0 means no limit. Defaults to 0.
	 */
	private int $_maxQueryDepth = 0;

	/**
	 * @var int maximum allowed query complexity score. 0 means no limit. Defaults to 0.
	 */
	private int $_maxQueryComplexity = 0;

	/**
	 * @var bool whether batched query arrays are accepted. Defaults to false.
	 */
	private bool $_enableBatchedQueries = false;

	/**
	 * @var int DebugFlag bitmask passed to ExecutionResult::toArray(). Defaults to 0 (no debug output).
	 */
	private int $_debugFlag = 0;

	/**
	 * Initialises the module.
	 *
	 * @param mixed $config the XML configuration element (may be null).
	 */
	public function init($config): void
	{
		parent::init($config);
	}

	// =========================================================================
	// EnableIntrospection
	// =========================================================================

	/**
	 * @return bool whether GraphQL introspection is enabled. Defaults to true.
	 */
	public function getEnableIntrospection(): bool
	{
		return $this->_enableIntrospection;
	}

	/**
	 * Enables or disables GraphQL introspection.
	 *
	 * Set to false in production to prevent schema enumeration.
	 * When false, a {@see \GraphQL\Validator\Rules\DisableIntrospection} validation
	 * rule is added automatically.
	 *
	 * @param mixed $value true to allow introspection, false to block it.
	 */
	public function setEnableIntrospection($value): void
	{
		$this->_enableIntrospection = TPropertyValue::ensureBoolean($value);
	}

	// =========================================================================
	// MaxQueryDepth
	// =========================================================================

	/**
	 * @return int the maximum allowed query nesting depth. 0 means no limit.
	 */
	public function getMaxQueryDepth(): int
	{
		return $this->_maxQueryDepth;
	}

	/**
	 * Sets the maximum allowed query nesting depth.
	 *
	 * When greater than 0, a {@see \GraphQL\Validator\Rules\QueryDepth} rule is
	 * added to the execution validation pass. Deeply nested queries that exceed
	 * this value are rejected before execution.
	 *
	 * @param mixed $value the depth limit; 0 disables the check.
	 */
	public function setMaxQueryDepth($value): void
	{
		$this->_maxQueryDepth = max(0, TPropertyValue::ensureInteger($value));
	}

	// =========================================================================
	// MaxQueryComplexity
	// =========================================================================

	/**
	 * @return int the maximum allowed query complexity score. 0 means no limit.
	 */
	public function getMaxQueryComplexity(): int
	{
		return $this->_maxQueryComplexity;
	}

	/**
	 * Sets the maximum allowed query complexity score.
	 *
	 * When greater than 0, a {@see \GraphQL\Validator\Rules\QueryComplexity} rule
	 * is added to the execution validation pass. Each field in the query
	 * contributes a cost; the sum must not exceed this value.
	 *
	 * @param mixed $value the complexity limit; 0 disables the check.
	 */
	public function setMaxQueryComplexity($value): void
	{
		$this->_maxQueryComplexity = max(0, TPropertyValue::ensureInteger($value));
	}

	// =========================================================================
	// EnableBatchedQueries
	// =========================================================================

	/**
	 * @return bool whether batched query arrays are accepted. Defaults to false.
	 */
	public function getEnableBatchedQueries(): bool
	{
		return $this->_enableBatchedQueries;
	}

	/**
	 * Enables or disables support for batched queries.
	 *
	 * When true, the service accepts a JSON array of operation objects in
	 * addition to a single operation object, executing each in sequence and
	 * returning an array of results. Disable in environments where N+1 batching
	 * is a denial-of-service concern.
	 *
	 * @param mixed $value true to accept batched requests.
	 */
	public function setEnableBatchedQueries($value): void
	{
		$this->_enableBatchedQueries = TPropertyValue::ensureBoolean($value);
	}

	// =========================================================================
	// DebugFlag
	// =========================================================================

	/**
	 * Returns the {@see \GraphQL\Error\DebugFlag} bitmask passed to
	 * {@see ExecutionResult::toArray()}.
	 *
	 * @return int the debug flag bitmask. 0 means no debug output (production default).
	 */
	public function getDebugFlag(): int
	{
		return $this->_debugFlag;
	}

	/**
	 * Sets the {@see \GraphQL\Error\DebugFlag} bitmask.
	 *
	 * Compose the value from {@see \GraphQL\Error\DebugFlag} constants:
	 * - `DebugFlag::INCLUDE_DEBUG_MESSAGE` (1) — includes internal error messages.
	 * - `DebugFlag::INCLUDE_TRACE`         (2) — includes stack traces.
	 * - `DebugFlag::RETHROW_INTERNAL_EXCEPTIONS` (4) — rethrows non-GraphQL exceptions.
	 *
	 * Set to 0 in production. Never expose traces to untrusted clients.
	 *
	 * @param mixed $value a {@see \GraphQL\Error\DebugFlag} bitmask.
	 */
	public function setDebugFlag($value): void
	{
		$this->_debugFlag = TPropertyValue::ensureInteger($value);
	}
}
