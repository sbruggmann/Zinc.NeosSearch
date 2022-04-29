<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

class TermImplementation extends AbstractFusionObject
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
            'term' => [
                $this->getField() => $this->getTerm(),
            ],
        ];
    }
}
