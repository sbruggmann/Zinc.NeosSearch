<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

class TermsImplementation extends AbstractFusionObject
{
    public function getField() {
        return $this->fusionValue( 'field');
    }

    public function getTerms() {
        return $this->fusionValue('terms');
    }

    public function evaluate()
    {
        return [
            'terms' => [
                $this->getField() => $this->getTerms(),
            ],
        ];
    }
}
