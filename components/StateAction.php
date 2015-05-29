<?php

namespace netis\fsm\components;

use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\HttpException;
use netis\utils\crud\Action;

/**
 * StateAction displays a list of possible state transitions and raises an AR model event.
 *
 * It requires to be used by a NetController class.
 *
 * Installation steps:
 * * optionally, call the fsm console command to generate migrations, run them and create two models
 * * implement the IStateful interface in the main model
 * * include a new 'transition' scenario in validation rules, most often it would make a 'notes' attribute safe or even required
 * * optionally, add an inline validator that would depend on the source state
 * * copy the views into the controller's viewPath
 * * call StateAction::getContextMenuItem when building the context menu in CRUD controller
 *
 * @author Jan Was <jwas@nets.com.pl>
 * @property $stateAuthItemTemplate string
 * @property $updateAuthItemTemplate string
 */
class StateAction extends Action
{
    /**
     * @var string Prefix of the auth item used to check access. Controller's $authModelClass is appended to it.
     */
    protected $_stateAuthItemTemplate = '{modelClass}.update';
    /**
     * @var string Auth item used to check access to update the main model. If null, the update button won't be available.
     */
    protected $_updateAuthItemTemplate;
    /**
     * @var callable a closure to check if current user is a superuser and authorization should be skipped
     */
    public $isAdminCallback;

    /**
     * @param string $controller
     * @param string $id
     */
    public function __construct($controller, $id)
    {
        parent::__construct($id, $controller);
        if (!$controller instanceof NetController && !$controller instanceof ActiveController) {
            throw new HttpException('Invalid configuration - BulkAction can only be used in a NetController.');
        }
    }

    public function setStateAuthItemTemplate($authTemplate)
    {
        if (is_string($authTemplate)) {
            $this->_stateAuthItemTemplate = strtr($authTemplate, [
                '{modelClass}' => $this->controller->authModelClass,
            ]);
        }
    }

    public function getStateAuthItemTemplate()
    {
        return $this->_stateAuthItemTemplate;
    }

    public function setUpdateAuthItemTemplate($authTemplate)
    {
        if (is_string($authTemplate)) {
            $this->_updateAuthItemTemplate = strtr($authTemplate, [
                '{modelClass}' => $this->controller->authModelClass,
            ]);
        }
    }

    public function getUpdateAuthItemTemplate()
    {
        return $this->_updateAuthItemTemplate;
    }

    /**
     * Runs the action.
     */
    public function run($id, $targetState = null, $confirmed = false)
    {
        $model = $this->findModel($id);
        if ($this->controller->checkAccessInActions && (!$this->checkAccess || !call_user_func($this->checkAccess, $this->id, $model))) {
            throw new HttpException(403, Yii::t('app','You are not authorized to perform this action on this object.'));
        }
        $model->scenario = IStateful::SCENARIO;
        list($stateChange, $sourceState, $format) = $this->prepare($model);
        $this->checkTransition($model, $stateChange, $sourceState, $targetState);

        if (isset($stateChange['state']->auth_item_name) && (!$this->checkAccess || !call_user_func($this->checkAccess, $stateChange['state']->auth_item_name, $model))) {
            throw new HttpException(400, Yii::t('app', 'You don\'t have necessary permissions to move the application from {from} to {to}.', array(
                '{from}' => Yii::$app->formatter->format($sourceState, $model->getAttributeFormat($model->stateAttributeName)),
                '{to}' => Yii::$app->formatter->format($targetState, $model->getAttributeFormat($model->stateAttributeName)),
            )));
        }

        if (!isset($stateChange['targets'][$targetState])) {
            throw new HttpException(400, Yii::t('app', 'You cannot change state from {from} to {to} because such state transition is undefined.', [
                '{from}' => Yii::$app->formatter->format($sourceState, $model->getAttributeFormat($model->stateAttributeName)),
                '{to}' => Yii::$app->formatter->format($targetState, $model->getAttributeFormat($model->stateAttributeName)),
            ]));
        }
        $model->setTransitionRules($targetState);
        $this->controller->initForm($model);
        if ($this->performTransition($model, $stateChange, $sourceState, $targetState, $confirmed)) {
            $this->afterTransition($model);
        }

        $this->render([
            'model'         => $model,
            'sourceState'   => $sourceState,
            'targetState'   => $targetState,
            'transition'    => $stateChange['targets'][$targetState],
            'format'        => $format,
            'stateActionUrl'=> Url::toRoute($this->id),
        ]);
    }

    /**
     * Loads the model specified by $id and prepares some data structures.
     * @param CActiveRecord $model
     * @return array contains values, in order: $stateChange(array), $sourceState(mixed), $uiType(string)
     */
    public function prepare($model)
    {
        if (!$model instanceof IStateful) {
            throw new HttpException(500, Yii::t('app', 'Model {model} needs to implement the IStateful interface.', [
                '{model}'=>$this->controller->modelClass
            ]));
        }
        $stateAttribute = $model->stateAttributeName;
        $stateChanges = $model->getTransitionsGroupedBySource();

        $format = $model->getAttributeFormat($stateAttribute);
        $sourceState = $model->$stateAttribute;
        if (!isset($stateChanges[$sourceState])) {
            $stateChange = ['state' => null, 'targets' => []];
        } else {
            $stateChange = $stateChanges[$sourceState];
        }
        return [$stateChange, $sourceState, $format];
    }

    /**
     * May render extra views for special cases and checks permissions.
     * @param CActiveRecord $model
     * @param array $stateChange
     * @param mixed $sourceState
     * @param string $targetState
     */
    public function checkTransition($model, $stateChange, $sourceState, $targetState)
    {
        if ($targetState === null) {
            // display all possible state transitions to select from
            Yii::$app->end(
                $this->controller->render('fsm_state', [
                    'model'       => $model,
                    'targetState' => null,
                    'states'      => $this->prepareStates($model),
                ])
            );
        } else if ((!is_callable($this->isAdminCallback) || !call_user_func($this->isAdminCallback)) && !isset($stateChange['targets'][$targetState])) {
            throw new HttpException(400, Yii::t('app', 'Changing status from {from} to {to} is not allowed.', [
                '{from}' => Yii::$app->formatter->format($sourceState, $model->getAttributeFormat($model->stateAttributeName)),
                '{to}' => Yii::$app->formatter->format($targetState, $model->getAttributeFormat($model->stateAttributeName)),
            ]));
        }
    }

    /**
     * Perform last checks and the actual state transition.
     * @param CActiveRecord $model
     * @param array $stateChange
     * @param mixed $sourceState
     * @param string $targetState
     * @param boolean $confirmed
     * @return boolean true if state transition has been performed
     */
    public function performTransition($model, $stateChange, $sourceState, $targetState, $confirmed)
    {
        if ($targetState === $sourceState) {
            $message = Yii::t('app', 'Status has already been changed').', '. Html::a(Yii::t('app','return to'), Url::toRoute(['view', 'id'=>$model->primaryKey]));
            $this->setFlash('error', $message);
            return false;
        }
        if (!$confirmed || !$model->isTransitionAllowed($targetState)) {
            return false;
        }

        $oldAttributes = $model->getAttributes();
        $data = $this->controller->processForm($model);
        // explicitly assign the new state value to avoid forcing the state attribute to be safe
        $model->{$model->getStateAttributeName()} = $targetState;

        if ($model->performTransition($oldAttributes, $data) === false) {
            $this->setFlash('error', Yii::t('app', 'Failed to save changes.'));
            return false;
        }
        if (isset($stateChange['targets'][$targetState])) {
            // $stateChange['targets'][$targetState] may not be set when user is admin
            $this->setFlash('success', $stateChange['targets'][$targetState]->post_label);
        }
        return true;
    }

    /**
     * Called after successfuly executing performTransition.
     * @param CActiveRecord $model
     */
    public function afterTransition($model)
    {
        $this->controller->redirect(['view', 'id'=>$model->id]);
        Yii::app()->end();
    }

    /**
     * Renders the default confirmation view.
     * @param array $params
     */
    public function render($params)
    {
        $this->controller->render('fsm_confirm', $params);
    }

    /**
     * Creates url params for a route to specific state transition.
     * @param StateTransition $state
     * @param CActiveRecord $model
     * @param string $targetState
     * @param boolean is the url going to be used in a context menu
     * @return array url params to be used with a route
     */
    public function getUrlParams($state, $model, $targetState, $contextMenu = false)
    {
        $urlParams = ['id' => $model->primaryKey, 'targetState' => $targetState];
        if (!$state->confirmation_required) {
            $urlParams['confirmed'] = true;
        } else {
            $urlParams['return'] = $this->id;
        }
        return $urlParams;
    }

    /**
     * Builds an array containing all possible status changes and result of validating every transition.
     * @params mixed $model
     * @return array
     */
    public function prepareStates($model)
    {
        $checkedAccess = [];
        $result = [];
        if ($this->updateAuthItemTemplate !== null) {
            $authItem = $this->updateAuthItemTemplate;
            $checkedAccess[$authItem] = ($this->checkAccess && call_user_func($this->checkAccess, $authItem, $model));
            $result[] = [
                'label'   => Yii::t('app', 'Update item'),
                'icon'    => 'pencil',
                'url'     => Url::toRoute(['update', 'id' => $model->getPrimaryKey()]),
                'enabled' => $checkedAccess[$authItem],
                'class'   => 'btn btn-success',
            ];
        }
        $valid = true;
        $attribute = $model->stateAttributeName;
        $sourceState = $model->$attribute;
        foreach($model->getTransitionsGroupedByTarget() as $targetState => $target) {
            $state = $target['state'];
            $sources = $target['sources'];

            if (!isset($sources[$sourceState])) continue;

            $enabled = null;
            $sourceStateObject = $sources[$sourceState];
            //foreach($sources[$sourceState] as $sourceStateObject) {
            $authItem = $sourceStateObject->auth_item_name;
            if (isset($checkedAccess[$authItem])) {
                $status = $checkedAccess[$authItem];
            } else {
                $status = $checkedAccess[$authItem] = ($this->checkAccess && call_user_func($this->checkAccess, $authItem, $model));
            }
            $enabled = ($enabled === null || $enabled) && $status;
            //}

            $valid = !$enabled || $model->isTransitionAllowed($targetState);

            $entry = [
                'post'      => $state->post_label,
                'label'     => $sources[$sourceState]->label,
                'icon'      => $state->icon,
                'class'     => $state->css_class,
                'target'    => $targetState,
                'enabled'   => $enabled && $valid,
                'valid'     => $valid,
                'url'       => Url::toRoute([$this->id, $this->getUrlParams($state, $model, $targetState)]),
            ];
            if ($state->display_order) {
                $result[$state->display_order] = $entry;
            } else {
                $result[] = $entry;
            }
        }
        ksort($result);
        return $result;
    }

    /**
     * Builds a menu item used in the context menu.
     * @param StateAction $action target action
     * @param array $transitions obtained by getGroupedByTarget()
     * @param mixed $model target model
     * @param mixed $sourceState current value of the state attribute
     * @param boolean $isAdmin if true, won't check access and all transitions will be displayed
     */
    public static function getContextMenuItem($action, $transitions, $model, $sourceState, $isAdmin=false)
    {
        $statusMenu = [
            'label' => Yii::t('app', 'Status changes'),
            'icon'  => 'share',
            'url'   => '#',
            'items' => [],
        ];
        foreach($transitions as $targetState => $target) {
            $state = $target['state'];
            $sources = $target['sources'];

            if (!$isAdmin && !isset($sources[$sourceState])) continue;

            $enabled = $isAdmin ? true : null;
            if (isset($sources[$sourceState])) {
                $sourceStateObject = $sources[$sourceState];
                $authItem = $sourceStateObject->auth_item_name;
                if (trim($authItem) === '') {
                    $checkedAccess[$authItem] = true;
                }

                if (isset($checkedAccess[$authItem])) {
                    $status = $checkedAccess[$authItem];
                } else {
                    $status = $checkedAccess[$authItem] = ($this->checkAccess && call_user_func($this->checkAccess, $authItem, $model));
                }
                $enabled = ($enabled === null || $enabled) && $status;
            }

            $url = array_merge([$action->id], $action->getUrlParams($state, $model, $targetState, true));
            $statusMenu['items'][] = [
                'label' => $state->label,
                'icon'  => $state->icon,
                'url'   => $enabled ? $url : null,
            ];
        }
        $statusMenu['disabled'] = $model->primaryKey === null || empty($statusMenu['items']);
        return $statusMenu;
    }
}

