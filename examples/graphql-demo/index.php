<?php

/**
 * Bookshop GraphQL Demo — application entry point.
 *
 * Start the built-in PHP server from the examples/graphql-demo/ directory:
 *
 *     php -S 127.0.0.1:8037 -t ./
 *
 * Then query the main endpoint:
 *
 *     curl -X POST http://127.0.0.1:8037/index.php?graphql \
 *          -H 'Content-Type: application/json' \
 *          -d '{"query":"{ hello }"}'
 *
 * Available service endpoints (pass as a query-string key):
 *   ?graphql     — full-featured (introspection on, no limits)
 *   ?restricted  — hardened  (introspection off, depth ≤ 3, complexity ≤ 5)
 *   ?apq         — APQ-enabled (persisted queries, batching on)
 */

// ---- Autoloader ----------------------------------------------------------------
// Resolve the project-root vendor/ directory regardless of how deep we are.
require_once __DIR__ . '/../../vendor/autoload.php';

// ---- PRADO application ---------------------------------------------------------
$app = new \Prado\TApplication(__DIR__ . '/protected');
$app->run();
