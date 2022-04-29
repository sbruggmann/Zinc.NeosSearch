<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;
use Neos\Fusion\FusionObjects\AbstractArrayFusionObject;

class ShouldImplementation extends AbstractArrayFusionObject
{
    public function getContent() {
        return $this->fusionValue('content');
    }

    public function evaluate()
    {
        $itemNames = [];
        foreach ($this->properties['content'] as $itemName => $item) {
            if (strpos($itemName, 'item_') === 0) {
                $itemNames[] = $itemName;
            }
        }

        $items = array_map(function ($itemName) {
            return $this->runtime->evaluate($this->path . '/content/' .$itemName);
        }, $itemNames);

        $items = array_filter($items, function($item) {
            return !!$item;
        });

        $items = array_filter($items, function($item) {
            if (array_key_exists('bool', $item)) {
                if (array_key_exists('should', $item['bool']) && empty($item['bool']['should'])) {
                    return false;
                } else if (array_key_exists('must', $item['bool']) && empty($item['bool']['must'])) {
                    return false;
                } else if (array_key_exists('must_not', $item['bool']) && empty($item['bool']['must_not'])) {
                    return false;
                }
            }
            return true;
        });

        $items = array_values($items);

        return [
            'bool' => [
                'should' => $items,
            ]
        ];
    }
}
