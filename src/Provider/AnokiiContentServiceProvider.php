<?php

declare(strict_types=1);

namespace App\Provider;

use App\Command\IngestCommand;
use App\Command\SeedGraphCommand;
use App\Petition\PetitionRepository;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the RHT graph content commands that fill the Anokii Co-Intelligence
 * corpus from the hub's own content:
 *
 *   - app:ingest      render the hub pages into doc_chunk (the retrieval corpus)
 *   - app:seed-graph  seed the relational graph and link the page chunks
 *   - app:reindex     ingest then seed-graph (the convenience re-index)
 *
 * The engine, entities, and public chat surface come from the waaseyaa/anokii
 * package; this provider only supplies rhtcircle's content seed and ingest.
 * Dependencies resolve lazily inside each handler so registration stays cheap.
 */
final class AnokiiContentServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    /** Graph entity type ids the seeder upserts. */
    private const TYPES = ['topic', 'place', 'community', 'organization', 'service', 'project', 'doc_chunk'];

    public function register(): void {}

    /**
     * @return iterable<HandlerCommand>
     */
    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'app:ingest',
            description: 'Render the hub pages into doc_chunk (the Co-Intelligence retrieval corpus). Idempotent.',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Preview extracted chunks without writing.'),
                new HandlerOption(name: 'prune', mode: HandlerOptionMode::Negatable, description: 'Delete page chunks no longer present (use --no-prune to keep).', default: true),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('app:ingest requires a booted kernel (EntityTypeManager).');

                    return 1;
                }

                return new IngestCommand($etm->getRepository('doc_chunk'), $this->petitionRepository())->run($io);
            },
        );

        yield new HandlerCommand(
            name: 'app:seed-graph',
            description: 'Seed the RHT relational graph (communities, places, topics, organizations, services, projects, curated chunks) and link page chunks. Idempotent.',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Report what would be seeded without writing.'),
            ],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('app:seed-graph requires a booted kernel (EntityTypeManager).');

                    return 1;
                }

                return new SeedGraphCommand($this->repos($etm))->run($io);
            },
        );

        yield new HandlerCommand(
            name: 'app:reindex',
            description: 'Re-index: run app:ingest then app:seed-graph so the chat reflects the current pages and graph. Idempotent.',
            options: [],
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('app:reindex requires a booted kernel (EntityTypeManager).');

                    return 1;
                }
                $ingest = new IngestCommand($etm->getRepository('doc_chunk'), $this->petitionRepository())->run($io);
                if ($ingest !== 0) {
                    return $ingest;
                }

                return new SeedGraphCommand($this->repos($etm))->run($io);
            },
        );
    }

    /**
     * @return array<string, \Waaseyaa\Entity\Repository\EntityRepositoryInterface>
     */
    private function repos(EntityTypeManager $etm): array
    {
        $repos = [];
        foreach (self::TYPES as $type) {
            $repos[$type] = $etm->getRepository($type);
        }

        return $repos;
    }

    private function entityTypeManager(): ?EntityTypeManager
    {
        try {
            $resolved = $this->resolve(EntityTypeManager::class);

            return $resolved instanceof EntityTypeManager ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Built the same way as AppServiceProvider::petitionRepository(): a
     * separate instance (this provider does not share AppServiceProvider's
     * state), but the same DB file and secret resolution, so app:ingest reads
     * the exact signature figures the live site does.
     */
    private function petitionRepository(): PetitionRepository
    {
        $root = \dirname(__DIR__, 2);
        $configured = getenv('WAASEYAA_DB') ?: '';
        $isAbsolute = str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1;
        $path = $configured === ''
            ? $root . '/storage/waaseyaa.sqlite'
            : ($isAbsolute ? $configured : $root . '/' . ltrim($configured, './'));

        return new PetitionRepository(
            DBALDatabase::createSqlite($path),
            getenv('WAASEYAA_PETITION_SECRET') ?: (getenv('WAASEYAA_JWT_SECRET') ?: 'rhtcircle-petition'),
        );
    }
}
