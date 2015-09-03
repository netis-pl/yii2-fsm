<?php

namespace netis\fsm\components;

use netis\utils\crud\UpdateAction;
use yii;

/**
 * StateAction displays a list of possible state transitions and raises an AR model event.
 *
 * It requires to be used by a NetController class.
 *
 * Installation steps:
 * * optionally, call the fsm console command to generate migrations, run them and create two models
 * * implement the IStateful interface in the main model
 * * include a new 'transition' scenario in validation rules, most often it would make a 'notes' attribute
 *   safe or even required
 * * optionally, add an inline validator that would depend on the source state
 * * copy the views into the controller's viewPath
 * * call StateAction::getContextMenuItem when building the context menu in CRUD controller
 *
 * @author Jan Was <jwas@nets.com.pl>
 */
class StateAction extends UpdateAction
{
    use StateActionTrait;

    /**
     * Builds a menu item used in the context menu.
     *
     * @param string   $actionId    target action id
     * @param array    $transitions obtained by getGroupedByTarget()
     * @param mixed    $model       target model
     * @param mixed    $sourceState current value of the state attribute
     * @param callable $checkAccess should have the following signature: function ($action, $model)
     * @param bool     $useDropDownMenu should this method create drop down menu or buttons
     *
     * @return array
     */
    public static function getContextMenuItem($actionId, $transitions, $model, $sourceState, $checkAccess, $useDropDownMenu = true)
    {
        $menu = [];

        foreach ($transitions as $targetState => $target) {
            $state   = $target['state'];
            $sources = $target['sources'];
            if (!isset($sources[$sourceState])) {
                continue;
            }

            $enabled = null;
            if (isset($sources[$sourceState])) {
                $sourceStateObject = $sources[$sourceState];
                $authItem          = $sourceStateObject->auth_item_name;
                if (trim($authItem) === '') {
                    $checkedAccess[$authItem] = true;
                } elseif (!is_callable($checkAccess)) {
                    $checkedAccess[$authItem] = false;
                } elseif (!isset($checkedAccess[$authItem])) {
                    $checkedAccess[$authItem] = call_user_func($checkAccess, $authItem, $model);
                }

                $enabled = ($enabled === null || $enabled) && $checkedAccess[$authItem];
            }
            $url = array_merge(
                [$actionId],
                self::getUrlParams($state, $model, $targetState, Yii::$app->controller->action->id, true)
            );

            $menu[$actionId . '-' . $targetState] = [
                'label' => $state->label,
                'icon'  => $state->icon,
                'url'   => $enabled ? $url : null,
            ];
        }

        if (!$useDropDownMenu) {
            return $menu;
        }
        return [
            'state' => [
                'label'    => Yii::t('netis/fsm/app', 'State change'),
                'disabled' => $model->primaryKey === null || empty($menu),
                'icon'     => 'share',
                'url'      => '#',
                'items'    => array_values($menu),
            ],
        ];
    }

}
