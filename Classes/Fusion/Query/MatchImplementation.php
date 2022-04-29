<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractFusionObject;

class MatchImplementation extends AbstractFusionObject
{
    public function getField() {
        if (!$field = $this->fusionValue( 'field')) {
            if ($field = $this->getProperty()) {
                $field = 'properties_' . $field;
            }
        }
        return $field;
    }

    public function getProperty() {
        return $this->fusionValue( 'property');
    }

    public function getQuery() {
        return $this->fusionValue('query');
    }

    public function getFuzziness() {
        return $this->fusionValue('fuzziness');
    }

    public function getBoost() {
        return $this->fusionValue('boost');
    }

    public function evaluate()
    {
        $payload = [
            'match' => [
                $this->getField() => [
                    'query' => $this->getQuery(),
                ],
            ],
        ];
        if ($fuzziness = $this->getFuzziness()) {
            $payload['match'][$this->getField()]['fuzziness'] = $fuzziness;
        }
        if ($boost = $this->getBoost()) {
            $payload['match'][$this->getField()]['boost'] = (int) $boost;
        }
        return $payload;
    }
}
