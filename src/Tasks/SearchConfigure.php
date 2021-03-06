<?php

namespace SilverStripe\SearchService\Tasks;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\SearchService\Exception\IndexingServiceException;
use SilverStripe\SearchService\Interfaces\IndexingInterface;
use SilverStripe\SearchService\Service\Traits\ServiceAware;

/**
 * Syncs index settings to a search service.
 *
 * Note this runs on dev/build automatically but is provided seperately for
 * uses where dev/build is slow (e.g 100,000+ record tables)
 */
class SearchConfigure extends BuildTask
{
    use ServiceAware;

    protected $title = 'Search Service Configure';

    protected $description = 'Sync search index configuration';

    private static $segment = 'SearchConfigure';


    public function __construct(IndexingInterface $searchService)
    {
        parent::__construct();
        $this->setIndexService($searchService);
    }

    /**
     * @param HTTPRequest $request
     * @throws IndexingServiceException
     */
    public function run($request)
    {
        $this->getIndexService()->configure();
        echo 'Done.';
    }
}
