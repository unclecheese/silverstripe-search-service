<?php

namespace SilverStripe\SearchService\Jobs;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SearchService\Interfaces\DocumentFetcherInterface;
use SilverStripe\SearchService\Service\Traits\ConfigurationAware;
use SilverStripe\SearchService\Service\DocumentFetchCreatorRegistry;
use SilverStripe\SearchService\Service\IndexConfiguration;
use SilverStripe\SearchService\Service\Indexer;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use InvalidArgumentException;

/**
 * @property DocumentFetcherInterface[] $fetchers
 * @property int $fetchIndex
 * @property int $fetchOffset
 * @property int $batchSize
 * @property array $onlyClasses
 */
class ReindexJob extends AbstractQueuedJob implements QueuedJob
{
    use Injectable;
    use ConfigurationAware;

    /**
     * @var array
     */
    private static $dependencies = [
        'Registry' => '%$' . DocumentFetchCreatorRegistry::class,
        'Configuration' => '%$' . IndexConfiguration::class,
    ];

    /**
     * @var DocumentFetchCreatorRegistry
     */
    private $registry;

    /**
     * @param array $onlyClasses
     * @param int $batchSize
     */
    public function __construct(?array $onlyClasses = [], ?int $batchSize = null)
    {
        parent::__construct();
        $this->onlyClasses = $onlyClasses;
        $this->batchSize = $batchSize ?: IndexConfiguration::singleton()->getBatchSize();
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }
    }

    /**
     * Defines the title of the job.
     *
     * @return string
     */
    public function getTitle()
    {
        $title = 'Search service reindex all documents';
        if (!empty($this->onlyClasses)) {
            $title .= ' of class ' . implode(',', $this->onlyClasses);
        }

        return $title;
    }

    /**
     * @return int
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function setup()
    {
        Versioned::set_stage(Versioned::LIVE);
        $until = strtotime('-' . $this->getConfiguration()->getSyncInterval());
        $classes = count($this->onlyClasses) ?
            $this->onlyClasses :
            $this->getConfiguration()->getSearchableBaseClasses();

        /* @var DocumentFetcherInterface[] $fetchers */
        $fetchers = [];
        foreach ($classes as $class) {
            $fetcher = $this->getRegistry()->getFetcher($class, $until);
            if ($fetcher) {
                $fetchers[$class] = $fetcher;
            }
        }

        $steps = array_reduce($fetchers, function ($total, $fetcher) {
            /* @var DocumentFetcherInterface $fetcher */
            return $total + ceil($fetcher->getTotalDocuments() / $this->batchSize);
        }, 0);

        $this->totalSteps = $steps;
        $this->isComplete = $steps === 0;
        $this->currentStep = 0;
        $this->fetchers = array_values($fetchers);
        $this->fetchIndex = 0;
        $this->fetchOffset = 0;
    }

    /**
     * Lets process a single node
     */
    public function process()
    {
        /* @var DocumentFetcherInterface $fetcher */
        $fetcher = $this->fetchers[$this->fetchIndex] ?? null;
        if (!$fetcher) {
            $this->isComplete = true;
            return;
        }

        $documents = $fetcher->fetch($this->batchSize, $this->fetchOffset);

        $indexer = Indexer::create($documents, Indexer::METHOD_ADD, $this->batchSize);
        $indexer->setProcessDependencies(false);
        while (!$indexer->finished()) {
            $indexer->processNode();
        }

        $nextOffset = $this->fetchOffset + $this->batchSize;
        if ($nextOffset >= $fetcher->getTotalDocuments()) {
            $this->fetchIndex++;
            $this->fetchOffset = 0;
        } else {
            $this->fetchOffset = $nextOffset;
        }
        $this->currentStep++;
    }

    /**
     * @param int $batchSize
     * @return ReindexJob
     */
    public function setBatchSize(int $batchSize): ReindexJob
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    /**
     * @return DocumentFetchCreatorRegistry
     */
    public function getRegistry(): DocumentFetchCreatorRegistry
    {
        return $this->registry;
    }

    /**
     * @param DocumentFetchCreatorRegistry $registry
     * @return ReindexJob
     */
    public function setRegistry(DocumentFetchCreatorRegistry $registry): ReindexJob
    {
        $this->registry = $registry;
        return $this;
    }
}
