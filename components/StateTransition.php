<?php

namespace netis\fsm\components;

use yii\base\Object;

class StateTransition extends Object
{
    public $source_state;
    public $target_state;
    public $label;
    public $post_label;
    public $icon;
    public $css_class;
    public $auth_item_name;
    public $confirmation_required = false;
    public $display_order;

    /**
     * Creates a list of StateTransition as all possible combinations between passes states.
     * States must be objects with following properties: id, pre_label, post_label, icon, css_class
     * @param $states array
     * @return array
     */
    public static function statesToTransitions($states)
    {
        $result = [];
        for($i = 0; $i < count($states); $i++) {
            for($j = 0; $j < count($states); $j++) {
                if ($i == $j) continue;
                $transition = new StateTransition;
                $transition->source_state = $states[$i]->id;
                $transition->target_state = $states[$j]->id;
                $transition->label = isset($states[$j]->pre_label) ? $states[$j]->pre_label : (string)$states[$j];
                $transition->post_label = isset($states[$j]->post_label) ? $states[$j]->post_label : (string)$states[$j];
                $transition->icon = isset($states[$j]->icon) ? $states[$j]->icon : null;
                $transition->css_class = isset($states[$j]->css_class) ? $states[$j]->css_class : null;
                $result[] = $transition;
            }
        }
        return $result;
    }

    public static function groupBySource($transitions, $source_attribute, $target_attribute)
    {
        $result = [];
        foreach($transitions as $transition) {
            if (!isset($result[$transition->$source_attribute])) {
                $result[$transition->$source_attribute] = ['state' => $transition, 'targets' => []];
            }
            $result[$transition->$source_attribute]['targets'][$transition->$target_attribute] = $transition;
        }
        return $result;
    }

    public static function groupByTarget($transitions, $source_attribute, $target_attribute)
    {
        $result = [];
        foreach($transitions as $transition) {
            if (!isset($result[$transition->$target_attribute])) {
                $result[$transition->$target_attribute] = ['state' => $transition, 'sources' => []];
            }
            $result[$transition->$target_attribute]['sources'][$transition->$source_attribute] = $transition;
        }
        return $result;
    }
}
