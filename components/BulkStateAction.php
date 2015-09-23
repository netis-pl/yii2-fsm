<?php

namespace netis\fsm\components;

use netis\utils\crud\BaseBulkAction;
use netis\utils\widgets\FormBuilder;
use yii;
use yii\helpers\Url;

/**
 * BulkStateAction works like StateAction, only on a group of records.
 *
 * @see    StateAction
 *
 * @author jwas
 */
class BulkStateAction extends BaseBulkAction
{
    use StateActionTrait {
        prepare as traitPrepare;
    }

    /**
     * @var boolean Is the job run in a single query.
     */
    public $singleQuery = false;

    /**
     * @var boolean Is the job run in a single transaction.
     * WARNING! Implies a single batch which may run out of execution time.
     * Enable this if there can be a SQL error that would interrupt the whole batch.
     */
    public $singleTransaction = false;

    /**
     * @var array|callable A route to redirect to after finishing the job.
     * It should display flash messages.
     */
    public $postRoute = ['index'];

    /**
     * @var string Key for flash message set after finishing the job.
     */
    public $postFlashKey = 'success';

    /**
     * @inheritdoc
     */
    public $viewName = 'state';

    /**
     * @var string the name of the view action. This property is need to create the URL
     * when the model is successfully created.
     */
    public $viewAction = 'view';

    /**
     * This need to be overwritten because {@link BaseBulkAction} and {@link StateTraitAction} both has run method
     * defined. We want to call run from {@link BaseBulkAction} but PHP takes from trait first.
     *
     * @param $step
     *
     * @return bool|void
     * @throws yii\base\InvalidConfigException
     */
    public function run($step)
    {
        return parent::run($step);
    }

    /**
     * @param IStateful $model
     *
     * @return mixed
     * @throws yii\web\BadRequestHttpException
     */
    protected function getSourceState($model)
    {
        $sourceStates = $this->getQuery()->select($model->getStateAttributeName())->distinct()->column();

        if (count($sourceStates) > 1) {
            throw new yii\web\BadRequestHttpException(Yii::t(
                'netis/fsm/app',
                'All selected models must have same source state.'
            ));
        }

        return reset($sourceStates);
    }

    /**
     * Initializes base model and performs all necessary checks.
     *
     * @return IStateful|\netis\utils\crud\ActiveRecord
     * @throws yii\base\InvalidConfigException
     * @throws yii\web\BadRequestHttpException
     */
    protected function initModel()
    {
        $targetState = Yii::$app->request->getQueryParam('targetState');
        if (trim($targetState) === '') {
            throw new yii\web\BadRequestHttpException('Target state cannot be empty');
        }

        /** @var IStateful|\netis\utils\crud\ActiveRecord $model */
        $model = new $this->controller->modelClass;
        if (!$model instanceof IStateful) {
            throw new yii\base\InvalidConfigException(
                Yii::t('netis/fsm/app', 'Model {model} needs to implement the IStateful interface.', [
                    'model' => $this->modelClass,
                ])
            );
        }

        $model->scenario                     = IStateful::SCENARIO;
        $model->{$model->stateAttributeName} = $this->getSourceState($model);
        list($stateChange, $sourceState, $format) = $this->traitPrepare($model, $targetState);

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

        $this->checkTransition($model, $stateChange, $sourceState, $targetState);

        if (isset($stateChange['state']->auth_item_name) && $this->checkAccess) {
            call_user_func($this->checkAccess, $stateChange['state']->auth_item_name, $model);
        }

        $model->setTransitionRules($targetState);

        return $model;
    }

    /**
     * Renders a form and/or confirmation.
     */
    public function prepare()
    {
        $targetState = Yii::$app->request->getQueryParam('targetState');

        $model = $this->initModel();

        list ($stateChange) = $this->traitPrepare($model, $targetState);
        $stateAttribute = $model->stateAttributeName;

        return array_merge($this->getResponse($model), [
            'stateChange' => $stateChange,
            'sourceState' => $model->$stateAttribute,
            'targetState' => $targetState,
            'states'      => null,
        ]);
    }

    /**
     * Performs state changes.
     */
    public function execute()
    {
        $targetState = Yii::$app->request->getQueryParam('targetState');

        $baseModel = $this->initModel();
        list ($stateChange) = $this->traitPrepare($baseModel, $targetState);

        $trx = null;
        if ($this->singleTransaction) {
            $trx = $baseModel->getDb()->getTransaction() === null ? $baseModel->getDb()->beginTransaction() : null;
        }

        if ($this->singleQuery) {
            throw new yii\base\InvalidConfigException('Not implemented - the singleQuery option has not been implemented yet.');
        }

        $stateAttribute = $baseModel->getStateAttributeName();
        $dataProvider   = $this->getDataProvider($baseModel, $this->getQuery());
        $skippedKeys    = [];
        $failedKeys     = [];

        foreach ($dataProvider->getModels() as $model) {
            /** @var IStateful|\netis\utils\crud\ActiveRecord $model */
            if (isset($stateChange['state']->auth_item_name)
                && !Yii::$app->user->can($stateChange['state']->auth_item_name, ['model' => $model])
            ) {
                $skippedKeys[] = $model->primaryKey;
                continue;
            }

            $model->scenario = IStateful::SCENARIO;
            $model->setTransitionRules($targetState);

            if (!$this->performTransition($model, $stateChange, $baseModel->$stateAttribute, $targetState, true)) {
                //! @todo errors should be gathered and displayed somewhere, maybe add a postSummary action in this class
                $failedKeys[] = $model->primaryKey;
            }
        }

        if ($trx !== null) {
            $trx->commit();
        }

        $message = Yii::t('netis/fsm/app', '{number} out of {total} {model} has been successfully updated.', [
            'number' => $dataProvider->getTotalCount() - count($failedKeys) - count($skippedKeys),
            'total'  => $dataProvider->getTotalCount(),
            'model'  => $baseModel->getCrudLabel(),
        ]);
        $this->setFlash($this->postFlashKey, $message);

        $route = is_callable($this->postRoute) ? call_user_func($this->postRoute, $baseModel) : $this->postRoute;

        $response = Yii::$app->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()->set('Location', Url::toRoute($route, true));

        return $dataProvider;
    }

    /**
     * Prepares response params, like fields and relations.
     *
     * @param \netis\utils\crud\ActiveRecord $model
     *
     * @return array
     */
    protected function getResponse($model)
    {
        $hiddenAttributes = array_filter(explode(',', Yii::$app->getRequest()->getQueryParam('hide', '')));
        $fields           = FormBuilder::getFormFields($model, $this->getFields($model, 'form'), false,
            $hiddenAttributes);

        return [
            'model'     => $model,
            'fields'    => empty($fields) ? [] : [$fields],
            'relations' => $this->getModelRelations($model, $this->getExtraFields($model)),
        ];
    }
}
