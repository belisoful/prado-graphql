<?php

/**
 * IGraphQLSchemaBuilder interface file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado-graphql
 * @license https://github.com/pradosoft/prado-graphql/blob/master/LICENSE
 */

namespace Prado\Web\Services;

/**
 * IGraphQLSchemaBuilder defines the contract for building a GraphQL schema.
 *
 * Implement this interface and set the fully-qualified class name on
 * {@see TGraphQLService::setSchemaBuilderClass SchemaBuilderClass} to supply
 * the schema that your GraphQL endpoint exposes.
 *
 * The builder is instantiated once per request (lazily, on the first query)
 * and its {@see buildSchema} method is called exactly once. The resulting
 * {@see \GraphQL\Type\Schema} is cached on the service instance for the
 * duration of the request.
 *
 * Example implementation:
 * ```php
 * namespace MyApp\GraphQL;
 *
 * use GraphQL\Type\Schema;
 * use GraphQL\Type\Definition\ObjectType;
 * use GraphQL\Type\Definition\Type;
 * use Prado\Web\Services\IGraphQLSchemaBuilder;
 * use Prado\Web\Services\TGraphQLService;
 *
 * class AppSchemaBuilder implements IGraphQLSchemaBuilder
 * {
 *     public function buildSchema(TGraphQLService $service): Schema
 *     {
 *         return new Schema([
 *             'query' => new ObjectType([
 *                 'name' => 'Query',
 *                 'fields' => [
 *                     'hello' => [
 *                         'type' => Type::string(),
 *                         'resolve' => fn() => 'world',
 *                     ],
 *                 ],
 *             ]),
 *         ]);
 *     }
 * }
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 1.0.0
 */
interface IGraphQLSchemaBuilder
{
	/**
	 * Builds and returns the GraphQL schema for this endpoint.
	 *
	 * The $service parameter gives the builder access to the PRADO application,
	 * cache module, and any service-level configuration it may need.
	 *
	 * @param TGraphQLService $service the GraphQL service requesting the schema.
	 * @return \GraphQL\Type\Schema the fully constructed schema.
	 */
	public function buildSchema(TGraphQLService $service): \GraphQL\Type\Schema;
}
