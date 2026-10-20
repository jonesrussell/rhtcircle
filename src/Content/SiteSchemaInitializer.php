<?php

declare(strict_types=1);

namespace App\Content;

use App\Analytics\AnalyticsSchema;
use App\Contact\ContactSchema;
use App\Lexicon\LexiconCacheSchema;
use App\Petition\PetitionSchema;
use App\Poll\PollSchema;
use App\Signup\SignupSchema;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/** App-owned schema setup. Invoked by app:initialize, never by page rendering. */
final class SiteSchemaInitializer
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function initialize(): void
    {
        if (!$this->database instanceof DBALDatabase) {
            throw new \LogicException('Application schema initialization requires the framework DBAL database.');
        }
        $connection = $this->database->getConnection();
        new SchemaMutationCoordinator($connection, new MigrationRepository($connection))
            ->execute(fn () => $this->initializeTables());
    }

    private function initializeTables(): void
    {
        new AnalyticsSchema($this->database)->ensure();
        new \App\Analytics\CampaignSchema($this->database)->ensure();
        new ContactSchema($this->database)->ensure();
        new LexiconCacheSchema($this->database)->ensure();
        new PetitionSchema($this->database)->ensure();
        new PollSchema($this->database)->ensure();
        new SignupSchema($this->database)->ensure();
    }
}
