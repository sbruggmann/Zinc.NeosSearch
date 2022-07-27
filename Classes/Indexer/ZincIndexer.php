<?php
namespace Zinc\NeosSearch\Indexer;

use Neos\Flow\Annotations as Flow;
use Flowpack\JobQueue\Common\Annotations as Job;
use Neos\Eel\FlowQuery\FlowQuery;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Eel\Utility as EelUtility;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Service\LinkingService;
use Neos\Flow\Mvc\Controller\Arguments as ControllerArguments;
use Neos\Utility\Arrays;
use \Neos\Eel\CompilingEvaluator;
use Zinc\NeosSearch\Service\ZincService;
use Zinc\NeosSearch\Traits\ConsoleLogTrait;
use Zinc\NeosSearch\Traits\ExecTrait;
use Zinc\NeosSearch\Traits\IndexNameTrait;
use Behat\Transliterator\Transliterator;

/**
 * @Flow\Scope("singleton")
 */
class ZincIndexer
{
    use CreateContentContextTrait;
    use ConsoleLogTrait;
    use IndexNameTrait;
    use ExecTrait;

    /**
     * @Flow\Inject
     * @var ZincService
     */
    protected $zincService;

    /**
     * @Flow\Inject(lazy=FALSE)
     * @var CompilingEvaluator
     */
    protected $eelEvaluator;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @var ControllerContext
     */
    protected $controllerContext;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @link https://gist.github.com/agitso/5037717#gistcomment-1475950
     * @param ConfigurationManager $configurationManager
     */
    public function injectControllerContext(ConfigurationManager $configurationManager) {
        $httpRequest = new ServerRequest('GET', '/');
        $routeParameters = RouteParameters::createEmpty()->withParameter('requestUriHost', $httpRequest->getUri()->getHost());
        $httpRequest = $httpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $routeParameters);
        $request  = ActionRequest::fromHttpRequest($httpRequest);

        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);
        $this->uriBuilder = $uriBuilder;

        $this->controllerContext = new ControllerContext(
            $this->uriBuilder->getRequest(),
            new ActionResponse(),
            new ControllerArguments(array()),
            $this->uriBuilder
        );
    }

    /**
     * @Job\Defer(queueName="zincBatchIndexer")
     */
    public function queueIndexNode(NodeInterface $node, $indexingNodeTypeProperties, $combinationHash) {
        $this->indexNode($node, $indexingNodeTypeProperties, $combinationHash);
    }

    public function indexNode(NodeInterface $node, $indexingNodeTypeProperties, $combinationHash) {

        $compressFulltext = function (&$text) {
            $text = preg_replace("/>/", ">\n", $text);
            $text = strip_tags($text);
            $text = preg_replace("/\n/", ' ', $text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
        };

        $normalizeFulltext = function (&$text) use ($compressFulltext) {
            $text = Transliterator::transliterate($text, ' ');
        };

        $cachedNodeTypeNamesAndSupertypesInternal = [];

        $extractNodeTypeNamesAndSupertypesInternal = function (NodeType $nodeType, array &$nodeTypeNames) use (&$extractNodeTypeNamesAndSupertypesInternal, &$cachedNodeTypeNamesAndSupertypesInternal) {
            $nodeTypeNames[$nodeType->getName()] = $nodeType->getName();
            foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
                $extractNodeTypeNamesAndSupertypesInternal($superType, $nodeTypeNames);

                unset($superType);
            }

            $cachedNodeTypeNamesAndSupertypesInternal[$nodeType->getName()] = $nodeTypeNames;
        };

        $traverseNodes = function (NodeInterface &$node, &$fulltext) use (&$traverseNodes, &$data, &$indexNode, &$combinationHash) {
            foreach ($node->getChildNodes() as $childNode) {
                if (!$childNode->getNodeType()->isOfType('Neos.Neos:ContentCollection') && !$childNode->getNodeType()->isOfType('Neos.Neos:Content')) {
                    continue;
                }

                $this->log('.');
                $traverseNodes($childNode, $fulltext);
                $indexNode($childNode, $data, $combinationHash, $fulltext);

                unset($childNode);
            }
        };

        $indexNode = function (NodeInterface &$node, &$data, &$combinationHash, &$fulltext) use(&$extractNodeTypeNamesAndSupertypesInternal, &$compressFulltext, &$normalizeFulltext, &$indexingNodeTypeProperties) {
            $nodeTypes = [];
            $extractNodeTypeNamesAndSupertypesInternal($node->getNodeType(), $nodeTypes);
            $nodeTypeNames = array_values($nodeTypes);

            if (!array_key_exists($node->getNodeType()->getName(), $indexingNodeTypeProperties)) {
                return;
            }

            $newItem = [];

            foreach ($indexingNodeTypeProperties[$node->getNodeType()->getName()] as $propertyName => $property) {
                $fieldName = Arrays::getValueByPath($property, 'search.zinc.fieldName') ?: 'properties_' . $propertyName;
                $mappingType = Arrays::getValueByPath($property, 'search.zinc.mappingType');
                $indexingValueExpression = Arrays::getValueByPath($property, 'search.zinc.indexingValue');
                $fulltextValueExpression = Arrays::getValueByPath($property, 'search.zinc.fulltextValue');

                $value = '';

                $q = new FlowQuery([$node]);
                /** @var NodeInterface $documentNode */
                $documentNode = $q->closest('[instanceof Neos.Neos:Document]')->get(0);

                switch ($propertyName) {
                    case 'identifier':
                        $value = $node->getIdentifier();
                        break;
                    case 'documentIdentifier':
                        $value = $documentNode->getIdentifier();
                        break;
                    case '_parentPath':
                        $slugs = explode('/', substr($node->getParentPath(), 1));
                        $slugs = array_reverse($slugs);
                        $parentPaths = [];
                        for ($i = count($slugs) - 1; $i >= 0; $i--) {
                            for ($j = 0; $j <= $i; $j++) {
                                if (!array_key_exists($j, $parentPaths)) {
                                    $parentPaths[$j] = '';
                                }
                                $parentPaths[$j] = $parentPaths[$j] . '/' . $slugs[$i];
                            }
                        }
                        $value = join(' ', $parentPaths);
                        break;
                    case 'nodeTypeAndSuperTypes':
                        $value = $nodeTypeNames;
                        break;
                    case 'uri':
                        $value = $this->getNodeUrl($node);
                        break;
                    case '_creationDateTime':
                        $value = $node->getNodeData()->getCreationDateTime();
                        break;
                    case '_lastModificationDateTime':
                        $value = $node->getNodeData()->getLastModificationDateTime();
                        break;
                    case '_lastPublicationDateTime':
                        $value = $node->getNodeData()->getLastPublicationDateTime();
                        break;
                    default:
                        if ($node->hasProperty($propertyName)) {
                            $propertyValue = $node->getNodeData()->getProperty($propertyName);
                            $value = is_array($propertyValue) ? implode(', ', $propertyValue) : $propertyValue;
                        }
                }

                switch ($mappingType) {
                    case 'date':
                        if (is_object($value) && get_class($value) === \DateTime::class) {
                            $value = $value->format('c');
                        }
                        break;
                }

                $contextVariables = [
                    'node' => $node,
                    'value' => $value,
                ];

                if ($indexingValueExpression) {
                    $newItem[$fieldName] = EelUtility::evaluateEelExpression($indexingValueExpression, $this->eelEvaluator, $contextVariables);
                }

                if ($fulltextValueExpression && !$node->isHidden()) {
                    $fulltext .= ' ' . EelUtility::evaluateEelExpression($fulltextValueExpression, $this->eelEvaluator, $contextVariables);
                }

                unset($propertyName);
                unset($property);
                unset($fieldName);
                unset($indexingValueExpression);
                unset($fulltextValueExpression);
                unset($value);
                unset($contextVariables);
            }

            if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
                $compressFulltext($fulltext);
                $newItem['fullbodytext'] = $fulltext;

                $normalizeFulltext($fulltext);
                $newItem['fulltext'] = $fulltext;
            }

            $data[$combinationHash][] = $newItem;

            unset($nodeTypes);
            unset($nodeTypeNames);
            unset($newItem);
        };

        $mappings = [
            'mappings' => [
                'properties' => [],
            ],
        ];
        foreach ($indexingNodeTypeProperties as $nodeTypeName => $nodeTypeProperties) {
            foreach ($nodeTypeProperties as $propertyName => $property) {
                $fieldName = Arrays::getValueByPath($property, 'search.zinc.fieldName') ?: 'properties_' . $propertyName;
                $mappingType = Arrays::getValueByPath($property, 'search.zinc.mappingType');
                $mappings['mappings']['properties'][$fieldName] = [
                    'type' => $mappingType,
//                    'store' => true,
                    'sortable' => true,
//                    'aggregatable' => true,
//                    'highlightable' => true,
                ];
            }
        }

        $dumpData = function (&$data) use (&$mappings, &$indexTimestamp) {
            //$this->log('  ');

            $indexesData = $this->exec('index');
            $indexes = json_decode($indexesData);
            $indexNames = array_map(function ($item) {
                return $item->name;
            }, $indexes);

            foreach ($data as $combinationHash => $items) {

                $indexName = $this->getIndexName($combinationHash, $indexTimestamp);

                if (!in_array($indexName, $indexNames)) {
                    $indexData = [
                        'name' => $indexName,
                        'storage_type' => 'disk',
                        'mappings' => $mappings['mappings'],
                    ];
                    $mappingResultBody = $this->exec('index', 'PUT', $indexData);
                    $mappingResult = \json_decode($mappingResultBody, true);
                    if ($mappingResult && array_key_exists('message', $mappingResult) && $mappingResult['message'] === 'index created') {
                        $this->log('  - Mapping updated');
                    } else {
                        $this->log('  - Mapping update failed', ['body' => $mappingResultBody]);
                    }
                }

                foreach ($items as $item) {
                    $this->exec($indexName . '/_doc', 'PUT', $item);
                }

                $this->log('- ' . count($items) . ' in ' . $indexName);

                unset($fileContent);
                unset($mappingResultBody);
                unset($mappingResult);
            }
        };

        $data = [];
        $fulltext = '';
        $traverseNodes($node, $fulltext);
        $indexNode($node, $data, $combinationHash, $fulltext);
        $dumpData($data);
        unset($fulltext);

    }

    /**
     * @param NodeInterface $node
     * @param string $format
     * @param boolean $absolute
     * @return string
     */
    public function getNodeUrl(NodeInterface $node, $format = 'html', $absolute = false) {

        try {
            $uri = $this->linkingService->createNodeUri(
                $this->controllerContext,
                $node,
                $node->getContext()->getRootNode(),
                $format,
                $absolute
            );
        } catch (\Exception $e) {
            $uri = '';
        }

        return $uri;
    }
}
