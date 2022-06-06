<?php
namespace Zinc\NeosSearch\Eel;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Zinc\NeosSearch\Service\ZincService;

class SearchHelper implements ProtectedContextAwareInterface
{
    /**
     * @var ZincService
     * @Flow\Inject
     */
    protected $zincService;

    /**
     * @param NodeInterface $node
     * @param array $payload
     * @return array|\ArrayAccess|false|mixed
     */
    public function raw(NodeInterface $node, array $payload)
    {
        $indexName = $this->zincService->getIndexName($node->getContext()->getDimensions());
        return $this->zincService->execute($indexName, $payload);
    }

    /**
     * @param NodeInterface $node
     * @param array $payload
     * @return array
     */
    public function nodes(NodeInterface $node, array $payload)
    {
        if (array_key_exists('hits', $payload)) {
            $rawResult = $payload;
        } else {
            $rawResult = $this->raw($node, $payload);
        }

        $nodes = [];
        foreach ($rawResult['hits']['hits'] as $hit) {
            $nodes[] = $node->getContext()->getNodeByIdentifier($hit['_source']['documentIdentifier']);
        }
        return $nodes;
    }

    /**
     * @param string $methodName
     * @return bool
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
