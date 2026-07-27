<?php

declare(strict_types=1);

namespace App\Provider;

use App\Cms\MythSeeder;
use App\Cms\ArticleFields;
use App\Cms\ArticleRepository;
use App\Cms\ArticleSeeder;
use App\Entity\MythEntry;
use App\Entity\SourceLink;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\Listing\Filter;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Sort;

/**
 * The managed-CMS foundation for rhtcircle (consolidation pass 3).
 *
 * Registers the site languages (English default, plus Anishinaabemowin) and the
 * first managed content types (myth_entry and its source_link citations), as
 * translatable entities on the framework's sql-blob storage. The Anishinaabemowin
 * community varieties (oj-x-<code>) fall back to oj then en via the alpha.249
 * BCP 47 fallback chain, so partial translations render safely.
 *
 * Entity-type registration is app-local: no package is modified, mirroring the
 * pattern minoo uses for its own content entities.
 */
final class CmsContentServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, HasListingsInterface
{
    public function register(): void
    {
        // Site languages: English (default) plus Anishinaabemowin. Per-nation
        // varieties (oj-x-<code>, see the language-API registry) are added as real
        // translations arrive; each falls back to oj then en.
        $this->singleton(LanguageManagerInterface::class, static fn (): LanguageManagerInterface => new LanguageManager([
            new Language('en', 'English', isDefault: true),
            new Language('oj', 'Anishinaabemowin'),
        ]));

        // The pilot managed content types, translatable, on sql-blob storage.
        $this->entityType(EntityType::fromClass(MythEntry::class, translatable: true, group: 'content'));
        $this->entityType(EntityType::fromClass(SourceLink::class, translatable: true, group: 'content'));

        // node/article is revisionable through the framework's NodeType
        // discipline. Register its bundle fields before Listing validation so
        // the three declarative article listings fail fast if their schema
        // contract ever drifts.
        $registry = $this->resolve(FieldDefinitionRegistryInterface::class);
        if (!$registry instanceof FieldDefinitionRegistryInterface) {
            throw new \RuntimeException('Managed articles require the framework field registry.');
        }
        $registry->registerBundleFields('node', ArticleFields::BUNDLE, ArticleFields::definitions());
    }

    /**
     * Waaseyaa Listing definitions, equivalent to the site's Drupal Views.
     *
     * @return list<ListingDefinition>
     */
    public function listings(): array
    {
        $published = [Filter::eq('status', true)];
        $sorts = [Sort::desc('sticky'), Sort::desc('created')];

        return [
            new ListingDefinition(
                id: ArticleRepository::LISTING_ALL,
                entityType: 'node',
                bundle: ArticleFields::BUNDLE,
                filters: $published,
                sorts: $sorts,
                pageSize: 20,
            ),
            new ListingDefinition(
                id: ArticleRepository::LISTING_PROMOTED,
                entityType: 'node',
                bundle: ArticleFields::BUNDLE,
                filters: [...$published, Filter::eq('promote', true)],
                sorts: $sorts,
                pageSize: 3,
            ),
            new ListingDefinition(
                id: ArticleRepository::LISTING_SAGAMOK,
                entityType: 'node',
                bundle: ArticleFields::BUNDLE,
                filters: [...$published, Filter::eq('community_slug', 'sagamok')],
                sorts: $sorts,
                pageSize: 20,
            ),
        ];
    }

    /**
     * @return iterable<HandlerCommand>
     */
    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'app:cms-seed-myths',
            description: 'Migrate App\\Content\\MythEntries into the managed myth_entry + source_link content types. Idempotent.',
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('app:cms-seed-myths requires a booted kernel (EntityTypeManager).');

                    return 1;
                }

                $result = new MythSeeder($etm->getRepository('myth_entry'), $etm->getRepository('source_link'))->seed();
                $io->writeln(sprintf('myth_entry: %d upserted; source_link: %d written.', $result['myths'], $result['sources']));

                return 0;
            },
        );

        yield new HandlerCommand(
            name: 'app:cms-migrate-articles',
            description: 'Create the revisionable article bundle and migrate missing hand-authored investigations. Existing CMS articles are never overwritten.',
            handler: function (SymfonyCommandIO $io): int {
                $etm = $this->entityTypeManager();
                if ($etm === null) {
                    $io->error('app:cms-migrate-articles requires a booted kernel (EntityTypeManager).');

                    return 1;
                }

                $result = new ArticleSeeder(
                    $etm->getRepository('node'),
                    $etm->getRepository('node_type'),
                    $this->projectRoot,
                )->seed();
                $io->writeln(sprintf(
                    'article: %d created; %d existing CMS records left unchanged; %d unpublished.',
                    $result['created'],
                    $result['skipped'],
                    $result['unpublished'],
                ));

                return 0;
            },
        );
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
}
