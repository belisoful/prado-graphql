<?php

/**
 * BookshopSchemaBuilder — comprehensive GraphQL schema for the Bookshop demo.
 *
 * Covers every TGraphQLService feature:
 *  - Simple scalar queries (hello, ping, echo with variables)
 *  - Queries with arguments and enum filters (books, book, author, search)
 *  - Nested object types with bidirectional relations (Book ↔ Author ↔ Review)
 *  - Union type (SearchResult = Book | Author)
 *  - Input object type (BookInput for createBook mutation)
 *  - Mutations (createBook, addReview, storeQuery)
 *  - Context access: requestType reads the HTTP method from TGraphQLContext
 *  - APQ support: storeQuery mutation calls TGraphQLService::persistQuery()
 *  - Error scenarios: failWithError throws a GraphQL field error
 *  - Depth-test hierarchy: nested { level1 { level2 { level3 { deep { value } } } } }
 *
 * Static data persists across requests when the built-in PHP server runs in a
 * single process, which makes mutations observable across sequential HTTP calls.
 *
 */

namespace DemoApp;

use GraphQL\Error\Error as GraphQLError;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use Prado\Web\Services\IGraphQLSchemaBuilder;
use Prado\Web\Services\TGraphQLContext;
use Prado\Web\Services\TGraphQLService;

class BookshopSchemaBuilder implements IGraphQLSchemaBuilder
{
	// =========================================================================
	// In-memory data — static so it survives across requests in a single process
	// =========================================================================

	/** @var array<int, array<string, mixed>> Pre-seeded authors. */
	private static array $authors = [
		['id' => '1', 'name' => 'Ursula K. Le Guin', 'biography' => 'American author of speculative fiction.'],
		['id' => '2', 'name' => 'Frank Herbert',      'biography' => 'Author of the Dune series.'],
		['id' => '3', 'name' => 'Isaac Asimov',       'biography' => 'Prolific American writer of science fiction.'],
	];

	/** @var array<int, array<string, mixed>> Pre-seeded books. */
	private static array $books = [
		['id' => '1', 'title' => 'The Left Hand of Darkness', 'isbn' => '978-0441478125', 'year' => 1969, 'authorId' => '1', 'genre' => 'SCIENCE_FICTION'],
		['id' => '2', 'title' => 'Dune',                      'isbn' => '978-0441013593', 'year' => 1965, 'authorId' => '2', 'genre' => 'SCIENCE_FICTION'],
		['id' => '3', 'title' => 'Foundation',                 'isbn' => '978-0553293357', 'year' => 1951, 'authorId' => '3', 'genre' => 'SCIENCE_FICTION'],
		['id' => '4', 'title' => 'The Dispossessed',           'isbn' => '978-0061054884', 'year' => 1974, 'authorId' => '1', 'genre' => 'SCIENCE_FICTION'],
	];

	/** @var array<int, array<string, mixed>> Reviews created at runtime. */
	private static array $reviews = [];

	/** @var int Auto-increment counter for new books. */
	private static int $nextBookId = 5;

	/** @var int Auto-increment counter for new reviews. */
	private static int $nextReviewId = 1;

	// =========================================================================
	// IGraphQLSchemaBuilder
	// =========================================================================

	public function buildSchema(TGraphQLService $service): Schema
	{
		// ---- Enum ---------------------------------------------------------------
		$genreType = new EnumType([
			'name' => 'Genre',
			'description' => 'Literary genre of a book.',
			'values' => [
				'SCIENCE_FICTION' => ['value' => 'SCIENCE_FICTION', 'description' => 'Science fiction'],
				'FANTASY' => ['value' => 'FANTASY',         'description' => 'Fantasy'],
				'HISTORY' => ['value' => 'HISTORY',         'description' => 'History'],
				'NON_FICTION' => ['value' => 'NON_FICTION',     'description' => 'Non-fiction'],
			],
		]);

		// ---- Forward declarations (needed for circular Book ↔ Author ↔ Review) --
		/** @var null|ObjectType $bookType */
		$bookType = null;
		/** @var null|ObjectType $authorType */
		$authorType = null;
		/** @var null|ObjectType $reviewType */
		$reviewType = null;

		// ---- Review (references Book via lazy closure) ---------------------------
		$reviewType = new ObjectType([
			'name' => 'Review',
			'description' => 'A reader review for a book.',
			'fields' => function () use (&$bookType): array {
				return [
					'id' => ['type' => Type::nonNull(Type::id()),  'description' => 'Review ID.'],
					'body' => ['type' => Type::string(),             'description' => 'Review text.'],
					'rating' => ['type' => Type::nonNull(Type::int()), 'description' => 'Rating 1–5.'],
					'book' => [
						'type' => Type::nonNull($bookType),
						'description' => 'The reviewed book.',
						'resolve' => function (array $r): ?array {
							return self::findBook((string) $r['bookId']);
						},
					],
				];
			},
		]);

		// ---- Author (references Book via lazy closure) ---------------------------
		$authorType = new ObjectType([
			'name' => 'Author',
			'description' => 'A book author.',
			'fields' => function () use (&$bookType): array {
				return [
					'id' => ['type' => Type::nonNull(Type::id()),     'description' => 'Author ID.'],
					'name' => ['type' => Type::nonNull(Type::string()), 'description' => 'Full name.'],
					'biography' => ['type' => Type::string(),                'description' => 'Short bio.'],
					'books' => [
						'type' => Type::nonNull(Type::listOf(Type::nonNull($bookType))),
						'description' => 'All books by this author.',
						'resolve' => function (array $a): array {
							return array_values(
								array_filter(self::$books, fn ($b) => $b['authorId'] === $a['id'])
							);
						},
					],
				];
			},
		]);

		// ---- Book (references Author and Review via lazy closure) ----------------
		$bookType = new ObjectType([
			'name' => 'Book',
			'description' => 'A book in the shop catalogue.',
			'fields' => function () use ($genreType, &$authorType, &$reviewType): array {
				return [
					'id' => ['type' => Type::nonNull(Type::id()),     'description' => 'Book ID.'],
					'title' => ['type' => Type::nonNull(Type::string()), 'description' => 'Book title.'],
					'isbn' => ['type' => Type::string(),                'description' => 'ISBN-13.'],
					'year' => ['type' => Type::int(),                   'description' => 'Publication year.'],
					'genre' => ['type' => Type::nonNull($genreType),     'description' => 'Literary genre.'],
					'author' => [
						'type' => Type::nonNull($authorType),
						'description' => 'The author of this book.',
						'resolve' => function (array $b): ?array {
							return self::findAuthor((string) $b['authorId']);
						},
					],
					'reviews' => [
						'type' => Type::nonNull(Type::listOf(Type::nonNull($reviewType))),
						'description' => 'Reader reviews.',
						'resolve' => function (array $b): array {
							return array_values(
								array_filter(self::$reviews, fn ($r) => $r['bookId'] === $b['id'])
							);
						},
					],
				];
			},
		]);

		// ---- Union ---------------------------------------------------------------
		$searchResultType = new UnionType([
			'name' => 'SearchResult',
			'description' => 'A Book or Author matching a search term.',
			'types' => [$bookType, $authorType],
			'resolveType' => function ($value) use ($bookType, $authorType): ObjectType {
				// Books have an 'isbn' key; authors don't.
				return isset($value['isbn']) ? $bookType : $authorType;
			},
		]);

		// ---- Depth-test object hierarchy -----------------------------------------
		// Nesting: Query(0) → nested(0) → level1(1) → level2(2) → level3(3) → deep(4)
		// With MaxQueryDepth=3, selecting deep (non-leaf at depth 4) is rejected.
		$deepType = new ObjectType([
			'name' => 'Deep',
			'fields' => [
				'value' => ['type' => Type::string(), 'resolve' => fn () => 'deep'],
			],
		]);
		$level3Type = new ObjectType([
			'name' => 'Level3',
			'fields' => [
				'value' => ['type' => Type::string(), 'resolve' => fn () => 'level3'],
				'deep' => ['type' => $deepType,      'resolve' => fn () => []],
			],
		]);
		$level2Type = new ObjectType([
			'name' => 'Level2',
			'fields' => [
				'value' => ['type' => Type::string(),  'resolve' => fn () => 'level2'],
				'level3' => ['type' => $level3Type,     'resolve' => fn () => []],
			],
		]);
		$level1Type = new ObjectType([
			'name' => 'Level1',
			'fields' => [
				'value' => ['type' => Type::string(),  'resolve' => fn () => 'level1'],
				'level2' => ['type' => $level2Type,     'resolve' => fn () => []],
			],
		]);
		$nestedType = new ObjectType([
			'name' => 'Nested',
			'fields' => [
				'level1' => ['type' => $level1Type, 'resolve' => fn () => []],
			],
		]);

		// ---- Input type for createBook -------------------------------------------
		$bookInputType = new InputObjectType([
			'name' => 'BookInput',
			'fields' => [
				'title' => ['type' => Type::nonNull(Type::string()), 'description' => 'Book title.'],
				'isbn' => ['type' => Type::string(),                'description' => 'ISBN-13 (optional).'],
				'year' => ['type' => Type::int(),                   'description' => 'Publication year.'],
				'authorId' => ['type' => Type::nonNull(Type::id()),     'description' => 'ID of an existing author.'],
				'genre' => ['type' => Type::nonNull($genreType),     'description' => 'Literary genre.'],
			],
		]);

		// ---- Query type ----------------------------------------------------------
		$queryType = new ObjectType([
			'name' => 'Query',
			'fields' => [

				// ----- Basic scalars -----
				'hello' => [
					'type' => Type::string(),
					'description' => 'Returns a greeting string.',
					'resolve' => fn () => 'Hello from Bookshop!',
				],

				'ping' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'Health-check — always returns "pong".',
					'resolve' => fn () => 'pong',
				],

				'echo' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'Returns the message argument unchanged (variable testing).',
					'args' => ['message' => ['type' => Type::nonNull(Type::string())]],
					'resolve' => fn ($_, array $args) => $args['message'],
				],

				// ----- Book catalogue -----
				'books' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull($bookType))),
					'description' => 'Lists all books, optionally filtered by genre and/or truncated.',
					'args' => [
						'limit' => ['type' => Type::int(),   'description' => 'Maximum number of books to return.'],
						'genre' => ['type' => $genreType,    'description' => 'Filter by genre.'],
					],
					'resolve' => function ($_, array $args): array {
						$books = self::$books;
						if (isset($args['genre'])) {
							$books = array_values(
								array_filter($books, fn ($b) => $b['genre'] === $args['genre'])
							);
						}
						if (isset($args['limit']) && $args['limit'] > 0) {
							$books = array_slice($books, 0, $args['limit']);
						}
						return $books;
					},
				],

				'book' => [
					'type' => $bookType,
					'description' => 'Looks up a single book by ID. Returns null if not found.',
					'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
					'resolve' => fn ($_, array $args) => self::findBook((string) $args['id']),
				],

				// ----- Author catalogue -----
				'authors' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull($authorType))),
					'description' => 'Returns all authors.',
					'resolve' => fn () => self::$authors,
				],

				'author' => [
					'type' => $authorType,
					'description' => 'Looks up a single author by ID. Returns null if not found.',
					'args' => ['id' => ['type' => Type::nonNull(Type::id())]],
					'resolve' => fn ($_, array $args) => self::findAuthor((string) $args['id']),
				],

				// ----- Search (union type) -----
				'search' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull($searchResultType))),
					'description' => 'Full-text search over book titles and author names.',
					'args' => ['term' => ['type' => Type::nonNull(Type::string())]],
					'resolve' => function ($_, array $args): array {
						$term = strtolower($args['term']);
						$results = [];
						foreach (self::$books as $b) {
							if (str_contains(strtolower($b['title']), $term)) {
								$results[] = $b;
							}
						}
						foreach (self::$authors as $a) {
							if (str_contains(strtolower($a['name']), $term)) {
								$results[] = $a;
							}
						}
						return $results;
					},
				],

				// ----- Depth-test hierarchy -----
				'nested' => [
					'type' => $nestedType,
					'description' => 'Entry point for depth-limit testing.',
					'resolve' => fn () => [],
				],

				// ----- Error / edge cases -----
				'failWithError' => [
					'type' => Type::string(),
					'description' => 'Always throws a GraphQL field error with the supplied message.',
					'args' => ['message' => ['type' => Type::nonNull(Type::string())]],
					'resolve' => function ($_, array $args): never {
						throw new GraphQLError($args['message']);
					},
				],

				// ----- Context access -----
				'requestType' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'Returns the HTTP method of the current request (GET, POST, …).',
					'resolve' => function ($_, array $args, TGraphQLContext $ctx): string {
						return strtoupper((string) $ctx->getRequest()->getRequestType());
					},
				],
			],
		]);

		// ---- Mutation type -------------------------------------------------------
		$mutationType = new ObjectType([
			'name' => 'Mutation',
			'fields' => [

				'createBook' => [
					'type' => Type::nonNull($bookType),
					'description' => 'Adds a new book to the catalogue.',
					'args' => ['input' => ['type' => Type::nonNull($bookInputType)]],
					'resolve' => function ($_, array $args): array {
						$input = $args['input'];
						$book = [
							'id' => (string) self::$nextBookId++,
							'title' => (string) $input['title'],
							'isbn' => isset($input['isbn']) ? (string) $input['isbn'] : null,
							'year' => isset($input['year']) ? (int) $input['year'] : null,
							'authorId' => (string) $input['authorId'],
							'genre' => (string) $input['genre'],
						];
						self::$books[] = $book;
						return $book;
					},
				],

				'addReview' => [
					'type' => Type::nonNull($reviewType),
					'description' => 'Adds a reader review to a book.',
					'args' => [
						'bookId' => ['type' => Type::nonNull(Type::id())],
						'rating' => ['type' => Type::nonNull(Type::int())],
						'body' => ['type' => Type::string()],
					],
					'resolve' => function ($_, array $args): array {
						$review = [
							'id' => (string) self::$nextReviewId++,
							'bookId' => (string) $args['bookId'],
							'rating' => (int) $args['rating'],
							'body' => isset($args['body']) ? (string) $args['body'] : null,
						];
						self::$reviews[] = $review;
						return $review;
					},
				],

				/**
				 * Store a query in the APQ cache and return its SHA-256 hash.
				 *
				 * Functional tests use this mutation to pre-populate the APQ cache,
				 * then re-send the hash alone to verify retrieval.
				 */
				'storeQuery' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'Persists a GraphQL query document in the APQ cache and returns its SHA-256 hash.',
					'args' => ['query' => ['type' => Type::nonNull(Type::string())]],
					'resolve' => function ($_, array $args, TGraphQLContext $ctx): string {
						$query = (string) $args['query'];
						$svc = $ctx->getApplication()->getService();
						if ($svc instanceof TGraphQLService) {
							$svc->persistQuery($query);
						}
						return hash('sha256', $query);
					},
				],
			],
		]);

		// ---- Schema --------------------------------------------------------------
		return new Schema([
			'query' => $queryType,
			'mutation' => $mutationType,
		]);
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	private static function findBook(string $id): ?array
	{
		foreach (self::$books as $book) {
			if ($book['id'] === $id) {
				return $book;
			}
		}
		return null;
	}

	private static function findAuthor(string $id): ?array
	{
		foreach (self::$authors as $author) {
			if ($author['id'] === $id) {
				return $author;
			}
		}
		return null;
	}
}
