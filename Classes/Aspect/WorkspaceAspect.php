<?php
namespace Zinc\NeosSearch\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Zinc\NeosSearch\Service\ZincService;

/**
 * Capture output from command controllers for Behat tests
 *
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class WorkspaceAspect
{

    /**
     * @Flow\Inject
     * @var ZincService
     */
    protected $zincService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(path="realtimeIndexing.queue")
     * @var bool
     */
    protected $queueEnabled = false;

    /**
     * @Flow\Around("method(Neos\ContentRepository\Domain\Model\Workspace->publishNodes()) && setting(Zinc.NeosSearch.realtimeIndexing.enabled)")
     * @param JoinPointInterface $joinPoint
     * @return void
     */
    public function publishNodes(JoinPointInterface $joinPoint)
    {
        $nodes = $joinPoint->getMethodArgument('nodes');
        $targetWorkspace = $joinPoint->getMethodArgument('targetWorkspace');

        $indexNodes = [];

        /** @var NodeInterface $node */
        foreach ($nodes as $node) {
            $joinPoint->getProxy()->publishNode($node, $targetWorkspace);

            if($node->getNodeType()->isOfType('Neos.Neos:Document') && !array_key_exists($node->getIdentifier(), $indexNodes)) {
                $indexNodes[$node->getIdentifier()] = $node;

            } elseif ($node->getNodeType()->isOfType('Neos.Neos:Content')) {
                $q = new FlowQuery([$node]);

                /** @var NodeInterface $documentNode */
                $documentNode = $q->closest('[instanceof Neos.Neos:Document]')->get(0);
                if (!array_key_exists($documentNode->getIdentifier(), $indexNodes)) {
                    $indexNodes[$documentNode->getIdentifier()] = $documentNode;
                }
            }
        }

        if (!$this->queueEnabled) {
            $this->persistenceManager->persistAll();
        }

        /** @var TraversableNodeInterface $indexNode */
        foreach ($indexNodes as $indexNode) {
            if ($this->queueEnabled) {
                $this->zincService->queueUpdateNode($indexNode);
            } else {
                $this->zincService->updateNode($indexNode);
            }
        }

    }

}
