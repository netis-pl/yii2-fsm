<?php

namespace netis\fsm\components;

use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\HttpException;
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
 * @author Jan Was <jwas@neti.pl>
 */
trait StateActionTrait
{
    /**
     * @var string the scenario to be assigned to the existing model before its sate is changed.
     */
    public $stateScenario = IStateful::SCENARIO;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->controller instanceof \netis\utils\crud\ActiveController) {
            throw new yii\base\InvalidConfigException('StateAction can only be used in a controller extending \netis\utils\crud\ActiveController.');
        }
        parent::init();
    }

    /**
     * Runs the action.
     */
    public function run($id = null)
    {
        $targetState = Yii::$app->request->getQueryParam('targetState');
        $confirmed = Yii::$app->request->getQueryParam('confirmed', false);
        $model = $this->initModel($id);
        list($stateChange, $sourceState, $format) = $this->prepare($model, $targetState);
        if (($response = $this->checkTransition($model, $stateChange, $sourceState, $targetState)) !== true) {
            return $response;
        }

        if (isset($stateChange['state']->auth_item_name) && $this->checkAccess) {
            call_user_func($this->checkAccess, $stateChange['state']->auth_item_name, $model);
        }

        if (!isset($stateChange['targets'][$targetState])) {
            $message = Yii::t(
                'netis/fsm/app',
                'You cannot change state from {from} to {to} because such state transition is undefined.',
                [
                    'from' => Yii::$app->formatter->format($sourceState, $format),
                    'to'   => Yii::$app->formatter->format($targetState, $format),
                ]
            );
            throw new yii\web\BadRequestHttpException($message);
        }
        $model->setTransitionRules($targetState);
        if ($this->performTransition($model, $stateChange, $sourceState, $targetState, $confirmed)) {
            $this->afterTransition($model);
        }

        return array_merge($this->getResponse($model), [
            'stateChange' => $stateChange,
            'sourceState' => $sourceState,
            'targetState' => $targetState,
            'states'      => null,
        ]);
    }

    /**
     * Loads the model and performs authorization check.
     * @param string $id
     * @return \netis\utils\crud\ActiveRecord|IStateful
     * @throws yii\base\InvalidConfigException
     * @throws yii\web\NotFoundHttpException
     */
    protected function initModel($id)
    {
        $model = parent::initModel($id);
        $model->scenario = $this->stateScenario;

        if (!$model instanceof IStateful) {
            throw new yii\base\InvalidConfigException(
                Yii::t('netis/fsm/app', 'Model {model} needs to implement the IStateful interface.', [
                    'model' => $this->modelClass
                ])
            );
        }
        return $model;
    }

    /**
     * Loads the model specified by $id and prepares some data structures.
     * @param \netis\utils\crud\ActiveRecord|IStateful $model
     * @param string $targetState
     * @return array contains values, in order: $stateChange(array), $sourceState(mixed), $format(string|array)
     * @throws HttpException
     */
    public function prepare($model, $targetState)
    {
        $stateAttribute = $model->stateAttributeName;
        $stateChanges   = $model->getTransitionsGroupedBySource();

        $format      = $model->getAttributeFormat($stateAttribute);
        $sourceState = $model->$stateAttribute;
        if (!isset($stateChanges[$sourceState])) {
            $stateChange = ['state' => null, 'targets' => []];
        } else {
            $stateChange = $stateChanges[$sourceState];
            if (isset($stateChange['targets'][$targetState])) {
                $stateChange['state'] = $stateChange['targets'][$targetState];
            }
        }
        return [$stateChange, $sourceState, $format];
    }

    /**
     * May render extra views for special cases and checks permissions.
     * @param \netis\utils\crud\ActiveRecord $model
     * @param array $stateChange
     * @param mixed $sourceState
     * @param string $targetState
     * @return array|bool
     */
    public function checkTransition($model, $stateChange, $sourceState, $targetState)
    {
        if ($targetState !== null) {
            return true;
        }
        // display all possible state transitions to select from
        return [
            'model'       => $model,
            'stateChange' => $stateChange,
            'sourceState' => $sourceState,
            'targetState' => null,
            'states'      => $this->prepareStates($model),
        ];
    }

    /**
     * Perform last checks and the actual state transition.
     * @param \yii\db\ActiveRecord|IStateful $model
     * @param array $stateChange
     * @param mixed $sourceState
     * @param string $targetState
     * @param boolean $confirmed
     * @return boolean true if state transition has been performed
     */
    public function performTransition($model, $stateChange, $sourceState, $targetState, $confirmed)
    {
        if ($targetState === $sourceState) {
            $message = Yii::t('netis/fsm/app', 'Status has already been changed') . ', '
                . Html::a(Yii::t('netis/fsm/app', 'return to'), Url::toRoute(['view', 'id' => $model->primaryKey]));
            $this->setFlash('error', $message);
            return false;
        }
        if (!$confirmed || !$model->isTransitionAllowed($targetState)) {
            return false;
        }

        // explicitly assign the new state value to avoid forcing the state attribute to be safe
        $model->setAttribute($model->getStateAttributeName(), $targetState);

        $this->beforeTransition($model);
        if ($model->performTransition() === false) {
            $this->setFlash('error', Yii::t('netis/fsm/app', 'Failed to save changes.'));
            return false;
        }
        /**
         * Target state can be changed in {@link beforeTransition()} or {@link IStateful::performTransition()}
         */
        $targetState = $model->getAttribute($model->getStateAttributeName());
        if (isset($stateChange['targets'][$targetState])) {
            // $stateChange['targets'][$targetState] may not be set when user is admin
            $this->setFlash('success', $stateChange['targets'][$targetState]->post_label);
        }
        return true;
    }

    /**
     * Called before executing performTransition.
     * @param \yii\db\ActiveRecord $model
     */
    public function beforeTransition($model)
    {

    }

    /**
     * Called after successful {@link performTransition()} execution.
     * @param \yii\db\ActiveRecord $model
     */
    public function afterTransition($model)
    {
        $id = $this->exportKey($model->getPrimaryKey(true));
        $response = Yii::$app->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()->set('Location', Url::toRoute([$this->viewAction, 'id' => $id], true));
    }

    /**
     * Creates url params for a route to specific state transition.
     * @param StateTransition $state
     * @param \yii\db\ActiveRecord $model
     * @param string $targetState
     * @param string $id
     * @param boolean $contextMenu the url going to be used in a context menu
     * @return array url params to be used with a route
     */
    public static function getUrlParams($state, $model, $targetState, $id, $contextMenu = false)
    {
        $urlParams = ['id' => $model->primaryKey, 'targetState' => $targetState];
        if (!$state->confirmation_required) {
            $urlParams['confirmed'] = true;
        } else {
            $urlParams['return'] = $id;
        }
        return $urlParams;
    }

    /**
     * Builds an array containing all possible status changes and result of validating every transition.
     * @param \netis\utils\crud\ActiveRecord|IStateful $model
     * @return array
     */
    public function prepareStates($model)
    {
        $checkedAccess = [];
        $result        = [];
        $attribute   = $model->stateAttributeName;
        $sourceState = $model->getAttribute($attribute);
        foreach ($model->getTransitionsGroupedByTarget() as $targetState => $target) {
            $state   = $target['state'];
            $sources = $target['sources'];

            if (!isset($sources[$sourceState])) {
                continue;
            }

            $enabled           = null;
            $sourceStateObject = $sources[$sourceState];
            $authItem          = $sourceStateObject->auth_item_name;
            if (trim($authItem) === '') {
                $checkedAccess[$authItem] = true;
            } elseif (!is_callable($this->checkAccess)) {
                $checkedAccess[$authItem] = false;
            } elseif (!isset($checkedAccess[$authItem])) {
                $checkedAccess[$authItem] = call_user_func($this->checkAccess, $authItem, $model);
            }
            $enabled = ($enabled === null || $enabled) && $checkedAccess[$authItem];

            $valid = !$enabled || $model->isTransitionAllowed($targetState);
            $entry = [
                'post'    => $state->post_label,
                'label'   => $sources[$sourceState]->label,
                'icon'    => $state->icon,
                'class'   => $state->css_class,
                'target'  => $targetState,
                'enabled' => $enabled && $valid,
                'valid'   => $valid,
                'url'     => Url::toRoute([$this->id, static::getUrlParams($state, $model, $targetState, $this->id)]),
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
}
