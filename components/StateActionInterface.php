<?php

namespace netis\fsm\components;

/**
 * Description here...
 *
 * @author Michał Motyczko <michal@motyczko.pl>
 */
interface StateActionInterface
{
    /**
     * Return target state
     *
     * @return integer
     */
    public function getTargetState();

    /**
     * Loads the model specified by $id and prepares some data structures.
     *
     * @param \netis\crud\db\ActiveRecord|IStateful $model
     *
     * @return array contains values, in order: $stateChange(array), $sourceState(mixed), $format(string|array)
     * @internal param string $targetState
     */
    public function getTransition($model);

    /**
     * May render extra views for special cases and checks permissions.
     *
     * @fixme    consider some way of disable auth check because this method is used by BulkStateAction which passes dummy model as $model parameter
     *
     * @param \netis\crud\db\ActiveRecord $model
     * @param array                       $stateChange
     * @param mixed                       $sourceState
     * @param boolean                     $confirmed
     *
     * @return array|bool
     * @internal param string $targetState
     */
    public function checkTransition($model, $stateChange, $sourceState, $confirmed = true);

    /**
     * Perform last checks and the actual state transition.
     *
     * @param \yii\db\ActiveRecord|IStateful $model
     * @param array                          $stateChange
     * @param mixed                          $sourceState
     * @param boolean                        $confirmed
     *
     * @return bool true if state transition has been performed
     * @internal param string $targetState
     */
    public function performTransition($model, $stateChange, $sourceState, $confirmed = true);

    /**
     * Called before executing performTransition.
     * @param \yii\db\ActiveRecord $model
     * @return bool
     */
    public function beforeTransition($model);

    /**
     * Called after successful {@link performTransition()} execution.
     * @param \yii\db\ActiveRecord $model
     */
    public function afterTransition($model);

    /**
     * @param \yii\db\ActiveRecord|IStateful $model
     * @param array $stateChange
     * @param string $sourceState
     * @param string $targetState
     */
    public function setSuccessFlash($model, $stateChange, $sourceState, $targetState);

    /**
     * Creates url params for a route to specific state transition.
     * @param StateTransition $state
     * @param \yii\db\ActiveRecord $model
     * @param string $targetState
     * @param string $id
     * @param boolean $contextMenu the url going to be used in a context menu
     * @return array url params to be used with a route
     */
    public function getUrlParams($state, $model, $targetState, $id, $contextMenu = false);

    /**
     * Builds an array containing all possible status changes and result of validating every transition.
     * @param \netis\crud\db\ActiveRecord|IStateful $model
     * @return array
     */
    public function prepareStates($model);

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
    public function getContextMenuItem($actionId, $transitions, $model, $sourceState, $checkAccess, $useDropDownMenu = true);
}