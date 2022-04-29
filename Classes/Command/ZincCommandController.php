<?php
namespace Zinc\NeosSearch\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Zinc\NeosSearch\Service\ZincService;

/**
 * @Flow\Scope("singleton")
 */
class ZincCommandController extends CommandController
{
    /**
     * @var ZincService
     * @Flow\Inject
     */
    protected $zincService;

    /**
     * Index nodes to zinc
     *
     * @param bool $queue Insert into the queue or not
     * @return void
     */
    public function indexCommand($queue = false)
    {
        $millisecondsStart = round(microtime(true) * 1000);

        $this->zincService->setLogHook($this->getLogHook());
        $this->zincService->setConsole($this->output);
        $this->zincService->indexNodes($queue);

        $millisecondsEnd = round(microtime(true) * 1000);
        $this->outputLine('Time needed: ' . ($millisecondsEnd - $millisecondsStart) . 'ms');
    }

    /**
     * Purge indexes
     *
     * @param string $indexPrefix Default is 'test'
     * @return void
     */
    public function purgeCommand($indexPrefix = '')
    {
        $this->zincService->setLogHook($this->getLogHook());
        $this->zincService->purge($indexPrefix);
    }

    /**
     * List indexes
     *
     * @param string $indexPrefix Default is 'test'
     * @return void
     */
    public function listCommand($indexPrefix = '')
    {
        $this->zincService->setLogHook($this->getLogHook());
        $this->zincService->list($indexPrefix);
    }

    private function getLogHook()
    {
        return function ($text, $data) {
            if (!empty($text)) {
                if (strlen($text) === 1) {
                    $this->output->output($text);
                } else {
                    $this->outputLine($text);
                }
            }
            if (isset($data['table'])) {
                $this->output->outputTable($data['table']['rows'], isset($data['table']['headers']) ? $data['table']['headers'] : null);
            } else if (!empty($data)) {
                \Neos\Flow\var_dump($data);
            }
        };
    }

}
