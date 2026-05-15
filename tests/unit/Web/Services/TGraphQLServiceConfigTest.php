<?php

use GraphQL\Error\DebugFlag;
use Prado\Web\Services\TGraphQLServiceConfig;

/**
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado.Web.Services
 */
class TGraphQLServiceConfigTest extends PHPUnit\Framework\TestCase
{
	private TGraphQLServiceConfig $_config;

	protected function setUp(): void
	{
		$this->_config = new TGraphQLServiceConfig();
	}

	// -----------------------------------------------------------------------
	// EnableIntrospection
	// -----------------------------------------------------------------------

	public function test_enable_introspection_defaults_to_true()
	{
		$this->assertTrue($this->_config->getEnableIntrospection());
	}

	public function test_set_enable_introspection_false()
	{
		$this->_config->setEnableIntrospection(false);
		$this->assertFalse($this->_config->getEnableIntrospection());
	}

	public function test_set_enable_introspection_from_string_false()
	{
		$this->_config->setEnableIntrospection('false');
		$this->assertFalse($this->_config->getEnableIntrospection());
	}

	public function test_set_enable_introspection_from_string_true()
	{
		$this->_config->setEnableIntrospection('false');
		$this->_config->setEnableIntrospection('true');
		$this->assertTrue($this->_config->getEnableIntrospection());
	}

	// -----------------------------------------------------------------------
	// MaxQueryDepth
	// -----------------------------------------------------------------------

	public function test_max_query_depth_defaults_to_zero()
	{
		$this->assertSame(0, $this->_config->getMaxQueryDepth());
	}

	public function test_set_max_query_depth()
	{
		$this->_config->setMaxQueryDepth(10);
		$this->assertSame(10, $this->_config->getMaxQueryDepth());
	}

	public function test_set_max_query_depth_clamps_negative_to_zero()
	{
		$this->_config->setMaxQueryDepth(-5);
		$this->assertSame(0, $this->_config->getMaxQueryDepth());
	}

	public function test_set_max_query_depth_zero_disables()
	{
		$this->_config->setMaxQueryDepth(10);
		$this->_config->setMaxQueryDepth(0);
		$this->assertSame(0, $this->_config->getMaxQueryDepth());
	}

	// -----------------------------------------------------------------------
	// MaxQueryComplexity
	// -----------------------------------------------------------------------

	public function test_max_query_complexity_defaults_to_zero()
	{
		$this->assertSame(0, $this->_config->getMaxQueryComplexity());
	}

	public function test_set_max_query_complexity()
	{
		$this->_config->setMaxQueryComplexity(500);
		$this->assertSame(500, $this->_config->getMaxQueryComplexity());
	}

	public function test_set_max_query_complexity_clamps_negative_to_zero()
	{
		$this->_config->setMaxQueryComplexity(-1);
		$this->assertSame(0, $this->_config->getMaxQueryComplexity());
	}

	// -----------------------------------------------------------------------
	// EnableBatchedQueries
	// -----------------------------------------------------------------------

	public function test_enable_batched_queries_defaults_to_false()
	{
		$this->assertFalse($this->_config->getEnableBatchedQueries());
	}

	public function test_set_enable_batched_queries_true()
	{
		$this->_config->setEnableBatchedQueries(true);
		$this->assertTrue($this->_config->getEnableBatchedQueries());
	}

	public function test_set_enable_batched_queries_from_string()
	{
		$this->_config->setEnableBatchedQueries('true');
		$this->assertTrue($this->_config->getEnableBatchedQueries());
	}

	public function test_set_enable_batched_queries_false_from_string()
	{
		$this->_config->setEnableBatchedQueries('true');
		$this->_config->setEnableBatchedQueries('false');
		$this->assertFalse($this->_config->getEnableBatchedQueries());
	}

	// -----------------------------------------------------------------------
	// DebugFlag
	// -----------------------------------------------------------------------

	public function test_debug_flag_defaults_to_none()
	{
		$this->assertSame(DebugFlag::NONE, $this->_config->getDebugFlag());
	}

	public function test_set_debug_flag()
	{
		$this->_config->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE);
		$this->assertSame(
			DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE,
			$this->_config->getDebugFlag()
		);
	}

	public function test_set_debug_flag_to_zero_clears()
	{
		$this->_config->setDebugFlag(DebugFlag::INCLUDE_DEBUG_MESSAGE);
		$this->_config->setDebugFlag(0);
		$this->assertSame(0, $this->_config->getDebugFlag());
	}

	// -----------------------------------------------------------------------
	// Class contract
	// -----------------------------------------------------------------------

	public function test_is_tmodule()
	{
		$this->assertInstanceOf(\Prado\TModule::class, $this->_config);
	}

	public function test_is_tcomponent()
	{
		$this->assertInstanceOf(\Prado\TComponent::class, $this->_config);
	}

}

