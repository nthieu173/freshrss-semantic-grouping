<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Models/SemanticDatabase.php';

$database = new SemanticGrouping_SemanticDatabase();
$pdo = $database->open(migrate: true);

fwrite(STDOUT, 'Migrated semantic database to schema version ' . $database::SCHEMA_VERSION . ".\n");
