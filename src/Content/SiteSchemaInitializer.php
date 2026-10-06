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

/** App-owned schema setup. Invoked by app:initialize, never by page rendering. */
final class SiteSchemaInitializer
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function initialize(): void
    {
        new AnalyticsSchema($this->database)->ensure();
        new ContactSchema($this->database)->ensure();
        new LexiconCacheSchema($this->database)->ensure();
        new PetitionSchema($this->database)->ensure();
        new PollSchema($this->database)->ensure();
        new SignupSchema($this->database)->ensure();
    }
}
