<?php

declare(strict_types=1);

use Anokii\CoIntelligence\ChatQueryLogSchema;
use App\Content\SiteSchemaInitializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $database = new DBALDatabase($schema->getConnection());
        // Prepare the consumed alpha.14 module using its own schema definition
        // before ordinary provider boot. Current Anokii main owns this through
        // its newer distribution migrations; this app uses the maintenance API.
        new ChatQueryLogSchema($database)->ensure();
        new SiteSchemaInitializer($database)->initialize();
    }
};
