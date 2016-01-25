<?php

namespace netis\fsm\components;

use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
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
     * @var int
     */
    public $targetState = null;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->controller instanceof \netis\crud\crud\ActiveController) {
            throw new yii\base\InvalidConfigException('StateAction can only be used in a controller extending \netis\crud\crud\ActiveController.');
        }
        parent::init();
    }

    /**
     * Runs the action.
     */
    public function run($id = null)
    {
        $this->targetState = (int) Yii::$app->request->getQueryParam('targetState', $this->targetState);
        $confirmed = Yii::$app->request->getQueryParam('confirmed', false);
        $model = $this->initModel($id);
        list($stateChange, $sourceState) = $this->getTransition($model);

        $response = $this->checkTransition($model, $stateChange, $sourceState, $confirmed);
        if (!is_bool($response)) {
            return $response;
        }
        if ($response === true) {
            $trx = $model->getDb()->beginTransaction();
            /** @var \nineinchnick\audit\behaviors\TrackableBehavior $trackable */
            if (($trackable = $model->getBehavior('trackable')) !== null) {
                $model->beginChangeset();
            }
            $result = $this->performTransition($model, $stateChange, $sourceState, $confirmed);
            if ($trackable !== null) {
                $model->endChangeset();
            }
            if ($result) {
                $trx->commit();
                /**
                 * Target state can be changed in {@link beforeTransition()} or {@link IStateful::performTransition()}
                 */
                $this->targetState = $model->getAttribute($model->getStateAttributeName());
                $this->setSuccessFlash($model, $stateChange, $sourceState, $this->targetState);
            } else {
                $trx->rollBack();
                $this->setFlash('error', Yii::t('netis/fsm/app', 'Failed to save changes.'));
            }

        }

        return array_merge($this->getResponse($model), [
            'stateChange' => $stateChange,
            'sourceState' => $sourceState,
            'targetState' => $this->targetState,
            'states'      => null,
        ]);
    }

    /**
     * Loads the model and performs authorization check.
     * @param string $id
     * @return \netis\crud\db\ActiveRecord|IStateful
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
     * @param \netis\crud\db\ActiveRecord|IStateful $model
     * @param string $targetState
     * @return array contains values, in order: $stateChange(array), $sourceState(mixed), $format(string|array)
     * @throws HttpException
     */
    public function getTransition($model)
    {
        $stateAttribute = $model->stateAttributeName;
        $stateChanges   = $model->getTransitionsGroupedBySource();

        $sourceState = $model->$stateAttribute;
        if (!isset($stateChanges[$sourceState])) {
            $stateChange = ['state' => null, 'targets' => []];
        } else {
            $stateChange = $stateChanges[$sourceState];
            if (isset($stateChange['targets'][$this->targetState])) {
                $stateChange['state'] = $stateChange['targets'][$this->targetState];
            }
        }
        return [$stateChange, $sourceState];
    }

    /**
     * May render extra views for special cases and checks permissions.
     * @fixme consider some way of disable auth check because this method is used by BulkStateAction which passes dummy model as $model parameter
     *
     * @param \netis\crud\db\ActiveRecord $model
     * @param array $stateChange
     * @param mixed $sourceState
     * @param string $targetState
     * @param boolean $confirmed
     * @return array|bool
     * @throws yii\web\BadRequestHttpException
     */
    public function checkTransition($model, $stateChange, $sourceState, $confirmed = true)
    {
        if ($this->targetState === null) {
            // display all possible state transitions to select from
            return [
                'model'       => $model,
                'stateChange' => $stateChange,
                'sourceState' => $sourceState,
                'targetState' => null,
                'states'      => $this->prepareStates($model),
            ];
        }

        if (isset($stateChange['state']->auth_item_name)
            && !Yii::$app->user->can($stateChange['state']->auth_item_name, ['model' => $model])
        ) {
            throw new ForbiddenHttpException(Yii::t('app', 'Access denied.'));
        }

        if (!isset($stateChange['targets'][$this->targetState])) {
            $format  = $model->getAttributeFormat($model->stateAttributeName);
            $message = Yii::t(
                'netis/fsm/app',
                'You cannot change state from {from} to {to} because such state transition is undefined.',
                [
                    'from' => Yii::$app->formatter->format($sourceState, $format),
                    'to'   => Yii::$app->formatter->format($this->targetState, $format),
                ]
            );
            throw new yii\web\BadRequestHttpException($message);
        }
        $model->setTransitionRules($this->targetState);

        if ($this->targetState === $sourceState) {
            $message = Yii::t('netis/fsm/app', 'Status has already been changed') . ', '
                . Html::a(Yii::t('netis/fsm/app', 'return to'), Url::toRoute(['view', 'id' => $model->primaryKey]));
            $this->setFlash('error', $message);
            return false;
        }

        if (!$model->isTransitionAllowed($this->targetState)) {
            $format  = $model->getAttributeFormat($model->stateAttributeName);
            $message = Yii::t(
                'netis/fsm/app',
                'You cannot change state from {from} to {to} because such state transition is not allowed.',
                [
                    'from' => Yii::$app->formatter->format($sourceState, $format),
                    'to'   => Yii::$app->formatter->format($this->targetState, $format),
                ]
            );
            throw new yii\web\BadRequestHttpException($message);
        }

        return (bool)$confirmed;
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
    public function performTransition($model, $stateChange, $sourceState, $confirmed = true)
    {
        // explicitly assign the new state value to avoid forcing the state attribute to be safe
        $model->setAttribute($model->getStateAttributeName(), $this->targetState);

        if (!$this->beforeTransition($model) || $model->performTransition() === false) {
            return false;
        }
        $this->afterTransition($model);
        return true;
    }

    /**
     * Called before executing performTransition.
     * @param \yii\db\ActiveRecord $model
     * @return bool
     */
    public function beforeTransition($model)
    {
        return true;
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
     * @param \yii\db\ActiveRecord|IStateful $model
     * @param array $stateChange
     * @param string $sourceState
     * @param string $targetState
     */
    public function setSuccessFlash($model, $stateChange, $sourceState, $targetState)
    {
        if (isset($stateChange['targets'][$targetState])) {
            // $stateChange['targets'][$targetState] may not be set when user is admin
            $this->setFlash('success', $stateChange['targets'][$targetState]->post_label);
        }
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
    public function getUrlParams($state, $model, $targetState, $id, $contextMenu = false)
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
     * @param \netis\crud\db\ActiveRecord|IStateful $model
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
            } elseif (!isset($checkedAccess[$authItem])) {
                $checkedAccess[$authItem] = Yii::$app->user->can($authItem, ['model' => $model]);
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
                'url'     => Url::toRoute([$this->id, $this->getUrlParams($state, $model, $targetState, $this->id)]),
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
    public function getContextMenuItem($actionId, $transitions, $model, $sourceState, $checkAccess, $useDropDownMenu = true)
    {
        $menu = [];

        foreach ($transitions as $targetState => $target) {
            $state   = $target['state'];
            $sources = $target['sources'];
            if (!isset($sources[$sourceState])) {
                continue;
            }

            if (!$model->isTransitionAllowed($targetState)) {
                continue;
            }

            $enabled = null;
            if (isset($sources[$sourceState])) {
                $sourceStateObject = $sources[$sourceState];
                $authItem          = $sourceStateObject->auth_item_name;
                if (trim($authItem) === '') {
                    $checkedAccess[$authItem] = true;
                } elseif (!isset($checkedAccess[$authItem])) {
                    $checkedAccess[$authItem] = Yii::$app->user->can($authItem, ['model' => $model]);
                }

                $enabled = ($enabled === null || $enabled) && $checkedAccess[$authItem];
            }
            if (!$enabled) {
                continue;
            }

            $url = array_merge(
                [$actionId],
                $this->getUrlParams($state, $model, $targetState, Yii::$app->controller->action->id, true)
            );

            $menu[$actionId . '-' . $targetState] = [
                'label' => $state->label,
                'icon'  => $state->icon,
                'url'   => $url,
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
