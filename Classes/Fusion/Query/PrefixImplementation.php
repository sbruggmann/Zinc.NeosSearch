<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

class PrefixImplementation extends AbstractFusionObject
{
    public function getField() {
        return $this->fusionValue( 'field');
    }

    public function getTerm() {
        return $this->fusionValue('term');
    }

    public function evaluate()
    {
        return [
            'prefix' => [
                $this->getField() => $this->getTerm(),
            ],
        ];
    }
}
