<?php
namespace Zinc\NeosSearch\Service;

use Neos\Flow\Annotations as Flow;
use Flowpack\JobQueue\Common\Annotations as Job;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Utility\Arrays;
use Zinc\NeosSearch\Indexer\ZincIndexer;
use Zinc\NeosSearch\Traits\ConsoleLogTrait;
use Zinc\NeosSearch\Traits\ExecTrait;
use Zinc\NeosSearch\Traits\IndexNameTrait;

/**
 * @Flow\Scope("singleton")
 */
class ZincService
{
    use CreateContentContextTrait;
    use ConsoleLogTrait;
    use IndexNameTrait;
    use ExecTrait;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\InjectConfiguration(path="defaultNodeProperties")
     * @var array
     */
    protected $defaultNodeProperties = [];

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\Inject
     * @var ZincIndexer
     */
    protected $zincIndexer;

    /**
     * @param string $nodeIdentifier
     * @return void
     */
    public function deleteNode(string $nodeIdentifier)
    {
        $allCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        foreach( $allCombinations as $combination ) {
            $indexName = $this->getIndexName($combination);

            $payload = [
                'size' => 999999,
                'query' => [
                    'bool' => [
                        'must' => [
                            'term' => [
                                'identifier' => $nodeIdentifier,
                            ],
                        ],
                    ],
                ],
            ];
            $searchResult = $this->execute($indexName, $payload);

            if (!$searchResult['hits']['hits']) {
                continue;
            }

            foreach ($searchResult['hits']['hits'] as $item) {
                $this->exec(sprintf('%s/_doc/%s', $indexName, $item['_id']), 'DELETE');
            }
        }
    }

    /**
     * @Job\Defer(queueName="zincBatchIndexer")
     */
    public function queueUpdateNode(TraversableNodeInterface $node) {
        $this->updateNode($node, true);
    }

    /**
     * @param TraversableNodeInterface $node
     * @param $queue
     * @return void
     * @throws \Neos\Eel\Exception
     */
    public function updateNode(TraversableNodeInterface $node, $queue = false)
    {
        $this->deleteNode($node->getIdentifier());

        if (!$node->getNodeType()->isOfType('Neos.Neos:Document')) {
            $q = new FlowQuery([$node]);
            $node = $q->closest('[instanceof Neos.Neos:Document]')->get(0);
        }

        $indexingNodeTypeProperties = [];

        /** @var NodeType $nodeType */
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $nodeType) {
            $properties = $nodeType->getConfiguration('properties');
            if (!$properties) {
                continue;
            }

            $properties = array_filter($properties, function ($property) {
                return Arrays::getValueByPath($property, 'search.zinc') ? true : false;
            });

            if ($properties) {
                $indexingNodeTypeProperties[$nodeType->getName()] =array_merge($this->defaultNodeProperties, $properties);
            }
        }

        $allCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        foreach( $allCombinations as $combination ) {
            $combinationHash = $this->getDimensionHash($combination);

            if (!$queue) {
                $this->zincIndexer->indexNode($node, $indexingNodeTypeProperties, $combinationHash);
            } else {
                $this->zincIndexer->queueIndexNode($node, $indexingNodeTypeProperties, $combinationHash);
            }
        }
    }

    /**
     * Index nodes to zinc
     *
     * @param bool $queue
     * @return void
     */
    public function indexNodes($queue = false)
    {
        if (!$queue) {
            $this->zincIndexer->setConsole($this->console);
            $this->zincIndexer->setLogHook($this->logHook);
        }

        $indexingNodeTypeProperties = [];
        $ignoredIndexingNodeTypes = [];

        /** @var NodeType $nodeType */
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $nodeType) {
            $properties = $nodeType->getConfiguration('properties');
            if (!$properties) {
                continue;
            }

            $properties = array_filter($properties, function ($property) {
                return Arrays::getValueByPath($property, 'search.zinc') ? true : false;
            });

            if ($properties) {
                $indexingNodeTypeProperties[$nodeType->getName()] = array_merge($this->defaultNodeProperties, $properties);
            } else {
                $ignoredIndexingNodeTypes[] = $nodeType->getName();
            }
        }

        $allCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        $indexTimestamp = (new \DateTime())->getTimestamp();

        $this->activeIndexCache->set('indexTimestamp', $indexTimestamp);

        foreach( $allCombinations as $combination ) {
            $combinationHash = $this->getDimensionHash($combination);

            $context = $this->createContentContext('live', $combination);
            $nodes = (new FlowQuery([$context->getCurrentSiteNode()]))->find('[instanceof Neos.Neos:Document]')->get();
            $data = [];
            $collectChildDocumentNode = function (&$nodes, $node, $level) use (&$collectChildDocumentNode, &$data, &$combinationHash, &$traverseNodes, &$indexNode, &$indexingNodeTypeProperties, &$queue) {
                $this->log(':');
                if (!$queue) {
                    $this->zincIndexer->indexNode($node, $indexingNodeTypeProperties, $combinationHash);
                } else {
                    $this->zincIndexer->queueIndexNode($node, $indexingNodeTypeProperties, $combinationHash);
                }
                $level++;
                foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
                    $collectChildDocumentNode($nodes, $childNode, $level);
                }
                $this->log('!');
                $node = null;
                unset($node);
            };
            $collectChildDocumentNode($nodes, $context->getCurrentSiteNode(), 0);

            $nodes = [];
            unset($nodes);
            gc_collect_cycles();
        }

        $this->log(' ');
        $this->log('Finished all.');

    }

    /**
     * Purge indexes
     *
     * @param string $indexPrefix Default is 'test'
     * @return false|void
     */
    public function purge($indexPrefix = '')
    {
        if (!$indexPrefix) {
            $indexPrefix = $this->indexPrefix;
        }

        $resultBody = $this->exec('index');
        $indexes = json_decode($resultBody, true);

        if (!$indexes) {
            $this->log('no result');
            return false;
        }

        foreach ($indexes as $index) {
            $indexName = $index['name'];
            if (strpos($indexName, $indexPrefix . '-') !== false) {
                $resultBody = $this->exec('index/' . $indexName, 'DELETE');
                $result = json_decode($resultBody, true);
                $this->log('removed ' . $result['index']);
            }
        }
    }

    /**
     * List indexes
     *
     * @param string $indexPrefix Default is 'test'
     * @return void
     */
    public function list($indexPrefix = '')
    {
        if (!$indexPrefix) {
            $indexPrefix = $this->indexPrefix;
        }

        $resultBody = $this->exec('index');
        $indexes = \json_decode($resultBody, true);

        $activeIndexTimestamp = null;
        if ($this->activeIndexCache->has('indexTimestamp')) {
            $activeIndexTimestamp = $this->activeIndexCache->get('indexTimestamp');
        }

        $indexNames = [];
        foreach ($indexes as $index) {
            if (strpos($index['name'], $indexPrefix . '-') !== false) {
                $indexNames[] = [
                    $index['name'],
                    $activeIndexTimestamp && strpos($index['name'], $this->getIndexName(null, $activeIndexTimestamp)) === 0 ? 'active' : '',
                ];
            }
        }

        $this->log('', ['table' => ['rows' => $indexNames]]);
    }

    /**
     * @param string $indexName
     * @param array $payload
     * @return array|\ArrayAccess|false|mixed
     */
    public function execute(string $indexName, array $payload)
    {
        $size = 10;
        if (array_key_exists('size', $payload)) {
            $payload['size'] = (int) $payload['size'];
            $size = $payload['size'];
        } else {
            $payload['size'] = $size;
        }

        $from = 0;
        if (array_key_exists('from', $payload)) {
            $payload['from'] = (int) $payload['from'];
            $from = $payload['from'];
        } else {
            $payload['from'] = $from;
        }

        $page = $from === 0 ? 1 : ceil($from / $size) + 1;

        $resultBody = $this->exec($indexName . '/_search', 'POST', $payload, 'es');

        $results = json_decode($resultBody, true);

        if (!$results) {
            $this->log('Error: No result.');
            return false;
        }

        if ($results['error']) {
            $this->log($results['error']);
            return false;
        }

        if (Arrays::getValueByPath($results, 'hits.total.value')) {
            $results = Arrays::setValueByPath(
                $results,
                'hits.total.pages',
                ceil(Arrays::getValueByPath($results, 'hits.total.value') / $size)
            );

            $results = Arrays::setValueByPath(
                $results,
                'hits.current',
                [
                    'page' => $page,
                    'value' => count(Arrays::getValueByPath($results, 'hits.hits')),
                ]
            );
        } else {
            $this->log('none');
        }

        return $results;

    }

}
