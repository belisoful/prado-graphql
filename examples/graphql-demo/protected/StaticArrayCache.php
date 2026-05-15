<?php

/**
 * StaticArrayCache — a TCache backed by a temp file.
 *
 * PHP's built-in development server (`php -S`) resets static class properties
 * between requests just like any other SAPI.  To make APQ (Automatic Persisted
 * Query) functional tests reliable — where query strings are stored in one HTTP
 * request and retrieved in another — this implementation persists its store to a
 * JSON file in the system temp directory.
 *
 * The file is keyed by a hash of the document-root path so concurrent test runs
 * in different directories don't collide.
 *
 * Do NOT use this in production; it has no TTL enforcement and serialises data
 * as JSON which loses type fidelity for binary values.
 *
 */

namespace DemoApp;

use Prado\Caching\TCache;

class StaticArrayCache extends TCache
{
	/** Resolved path to the persistence file, lazily set on first access. */
	private static ?string $filePath = null;

	// -------------------------------------------------------------------------
	// File helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns (and caches) the path of the JSON persistence file.
	 */
	private static function getFilePath(): string
	{
		if (self::$filePath === null) {
			$tag = substr(md5((string) ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)), 0, 8);
			self::$filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'prado_graphql_apq_' . $tag . '.json';
		}
		return self::$filePath;
	}

	/**
	 * Reads the entire store from disk.
	 *
	 * @return array<string, string>
	 */
	private static function readStore(): array
	{
		$path = self::getFilePath();
		if (!file_exists($path)) {
			return [];
		}
		$raw = file_get_contents($path);
		if ($raw === false || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Writes the entire store to disk (atomic rename).
	 *
	 * @param array<string, string> $store
	 */
	private static function writeStore(array $store): void
	{
		$path = self::getFilePath();
		$tmp = $path . '.tmp.' . getmypid();
		file_put_contents($tmp, json_encode($store));
		rename($tmp, $path);
	}

	// -------------------------------------------------------------------------
	// TCache abstract method implementations
	// -------------------------------------------------------------------------

	/**
	 * Retrieves a raw value from the persistent store.
	 *
	 * TCache::get() expects getValue() to return the exact array [$value, $dependency]
	 * that TCache::set() passed to setValue(). We serialize() before storing and
	 * unserialize() on retrieval so the array structure survives the JSON file round-trip.
	 *
	 * @param string $key the hashed cache key produced by TCache.
	 * @return mixed the stored array [$value, $dependency], or false if absent.
	 */
	protected function getValue($key): mixed
	{
		$store = self::readStore();
		if (!array_key_exists($key, $store)) {
			return false;
		}
		// base64 keeps serialized bytes safe inside JSON; unserialize restores the
		// original [$value, $dependency] array that TCache::get() expects.
		return unserialize(base64_decode($store[$key]));
	}

	/**
	 * Stores a raw value in the persistent store.
	 *
	 * TCache::set() passes an array [$value, $dependency] as $value. We serialize()
	 * it so complex types survive the JSON round-trip, then base64-encode the result
	 * to keep the serialized bytes JSON-safe.
	 *
	 * @param string $key    the hashed cache key produced by TCache.
	 * @param mixed  $value  the [$value, $dependency] array produced by TCache.
	 * @param int    $expire TTL in seconds — ignored; no expiry is enforced.
	 * @return bool always true.
	 */
	protected function setValue($key, $value, $expire): bool
	{
		$store = self::readStore();
		$store[$key] = base64_encode(serialize($value));
		self::writeStore($store);
		return true;
	}

	/**
	 * Stores a raw value only if the key is not already present.
	 *
	 * @param string $key    the hashed cache key produced by TCache.
	 * @param mixed  $value  the [$value, $dependency] array produced by TCache.
	 * @param int    $expire TTL in seconds — ignored.
	 * @return bool true if the key was absent and the value was stored.
	 */
	protected function addValue($key, $value, $expire): bool
	{
		$store = self::readStore();
		if (array_key_exists($key, $store)) {
			return false;
		}
		$store[$key] = base64_encode(serialize($value));
		self::writeStore($store);
		return true;
	}

	/**
	 * Removes a key from the persistent store.
	 *
	 * @param string $key the hashed cache key produced by TCache.
	 * @return bool always true.
	 */
	protected function deleteValue($key): bool
	{
		$store = self::readStore();
		unset($store[$key]);
		self::writeStore($store);
		return true;
	}

	/**
	 * Clears all entries from the persistent store.
	 *
	 * @return bool always true.
	 */
	public function flush(): bool
	{
		self::writeStore([]);
		return true;
	}
}
