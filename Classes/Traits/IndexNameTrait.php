<?php
namespace Zinc\NeosSearch\Traits;

use Neos\Flow\Annotations as Flow;
use Neos\Cache\Frontend\VariableFrontend;

trait IndexNameTrait
{

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $activeIndexCache;

    /**
     * @Flow\InjectConfiguration(path="indexPrefix")
     * @var string
     */
    protected $indexPrefix = 'default';

    /**
     * @param string $indexPrefix
     */
    public function setIndexPrefix(string $indexPrefix): void
    {
        $this->indexPrefix = $indexPrefix;
    }

    /**
     * @param array $dimensionCombination
     * @return string
     */
    private function getDimensionHash(array $dimensionCombination)
    {
        $dimensionHashes = [];
        foreach ($dimensionCombination as $dimension => $values) {
            $dimensionHashes[] = $dimension . '-' . join('-', $values);
        }
        return join('-', $dimensionHashes);
    }

    /**
     * @param mixed $dimensionCombinationOrHash
     * @param string|null $indexTimestamp
     * @return string
     */
    public function getIndexName($dimensionCombinationOrHash = null, string $indexTimestamp = null)
    {
        if (!$indexTimestamp) {
            $indexTimestamp = $this->activeIndexCache->get('indexTimestamp');
        }

        $indexName = [
            $this->indexPrefix,
            $indexTimestamp,
        ];

        if ($dimensionCombinationOrHash) {
            $indexName[] = is_array($dimensionCombinationOrHash) ? $this->getDimensionHash($dimensionCombinationOrHash) : $dimensionCombinationOrHash;
        }

        return join('-', $indexName);
    }

}
