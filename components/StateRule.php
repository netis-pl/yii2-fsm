<?php

namespace netis\fsm\components;

use Yii;
use yii\rbac\Rule;

/**
 * Checks if model is in required state.
 * Supported options set through the data property:
 * * allowedStates array, required
 * * modelParamName string, optional, defaults to 'model'
 * * relationName string, optional, should be name of a hasOne relation to fetch a different model to check
 *
 * Checked model should implement the IStateful interface.
 */
class StateRule extends Rule
{
    public $name = 'state';

    /**
     * @param string|integer $user the user ID.
     * @param \yii\rbac\Item $item the role or permission that this rule is associated with
     * @param array $params parameters passed to ManagerInterface::checkAccess().
     * @return boolean a value indicating whether the rule permits the role or permission it is associated with.
     */
    public function execute($user, $item, $params)
    {
        if (Yii::$app->user->isGuest) {
            return false;
        }

        $modelParamName = isset($item->data['modelParamName']) ? $item->data['modelParamName'] : 'model';
        $relationName = isset($item->data['relationName']) ? $item->data['relationName'] : null;

        if (!isset($params[$modelParamName])) {
            return true;
        }

        /** @var IStateful $model */
        $model = $relationName === null ? $params[$modelParamName] : $params[$modelParamName]->$relationName;
        $stateAttribute = $model->getStateAttributeName();

        return in_array($model->$stateAttribute, $item->data['allowedStates']);
    }
}
