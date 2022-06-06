<?php
namespace Zinc\NeosSearch\Traits;

use Neos\Flow\Annotations as Flow;

trait ConsoleLogTrait
{

    protected $logHook;

    protected $console;

    /**
     * @param mixed $logHook
     */
    public function setLogHook($logHook): void
    {
        $this->logHook = $logHook;
    }

    /**
     * @param mixed $console
     */
    public function setConsole($console): void
    {
        $this->console = $console;
    }

    private function log($text, $data = [])
    {
        if ($this->logHook) {
            ($this->logHook)($text, $data);
        }
    }

}
