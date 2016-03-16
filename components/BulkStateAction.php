<?php

namespace netis\fsm\components;

use netis\crud\db\ActiveRecord;
use netis\crud\crud\BaseBulkAction;
use netis\crud\widgets\FormBuilder;
use yii;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * BulkStateAction works like StateAction, only on a group of records.
 *
 * @see    StateAction
 *
 * @author jwas
 */
class BulkStateAction extends BaseBulkAction implements StateActionInterface
{
    use StateActionTrait {
        setSuccessFlash as setSuccessFlashTrait;
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
     * @param IStateful|yii\db\ActiveRecord $model
     *
     * @return mixed
     * @throws yii\web\BadRequestHttpException
     */
    protected function getSourceState($model)
    {
        $query = clone $this->getQuery($model);
        $sourceStates = $query
            ->select($model->getStateAttributeName())
            ->distinct()
            ->column();

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
     * @return IStateful|\netis\crud\db\ActiveRecord
     * @throws yii\base\InvalidConfigException
     * @throws yii\web\BadRequestHttpException
     */
    protected function initModel()
    {
        if (trim($this->targetState) === '') {
            throw new yii\web\BadRequestHttpException('Target state cannot be empty');
        }

        /** @var IStateful|\netis\crud\db\ActiveRecord $model */
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

        return $model;
    }

    /**
     * Renders a form and/or confirmation.
     */
    public function prepare()
    {
        $this->targetState = (int) Yii::$app->request->getQueryParam('targetState');

        $model = $this->initModel();

        list ($stateChange, $sourceState) = $this->getTransition($model);
        $response = $this->checkTransition($model, $stateChange, $sourceState, true);
        if (!is_bool($response)) {
            return $response;
        }

        return array_merge($this->getResponse($model), [
            'stateChange' => $stateChange,
            'sourceState' => $sourceState,
            'targetState' => $this->targetState,
            'states'      => null,
        ]);
    }

    /**
     * Performs state changes.
     */
    public function execute()
    {
        $this->targetState = Yii::$app->request->getQueryParam('targetState');

        $baseModel = $this->initModel();
        list ($stateChange, $sourceState) = $this->getTransition($baseModel);
        $response = $this->checkTransition($baseModel, $stateChange, $sourceState, true);
        $stateAuthItem = isset($stateChange['state']->auth_item_name) ? $stateChange['state']->auth_item_name : null;
        $transaction = $this->beforeExecute($baseModel);

        if ($this->singleQuery) {
            throw new yii\base\InvalidConfigException('Not implemented - the singleQuery option has not been implemented yet.');
        }

        $dataProvider   = $this->getDataProvider($baseModel, $this->getQuery($baseModel));
        $skippedModels    = [];
        $failedModels     = [];
        $successModels    = [];

        if ($response !== true) {
            return $dataProvider;
        }

        foreach ($dataProvider->getModels() as $model) {
            /** @var IStateful|\netis\crud\db\ActiveRecord $model */
            if (!$this->controller->hasAccess('update', $model)
                || ($stateAuthItem !== null && !Yii::$app->user->can($stateAuthItem, ['model' => $model]))
            ) {
                $skippedModels[] = $model;
                continue;
            }

            $model->scenario = IStateful::SCENARIO;
            $model->setTransitionRules($this->targetState);

            if (!$this->performTransition($model, $stateChange, $sourceState, true)) {
                //! @todo errors should be gathered and displayed somewhere, maybe add a postSummary action in this class
                $failedModels[$model->__toString()] = \yii\helpers\Html::errorSummary($model, ['header' => '']);
            } else {
                $successModels[] = $model;
            }
        }

        $this->afterExecute($baseModel, $transaction);
        $this->setSuccessMessage($baseModel, $skippedModels, $failedModels, $successModels);

        $route = is_callable($this->postRoute) ? call_user_func($this->postRoute, $baseModel) : $this->postRoute;

        $response = Yii::$app->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()->set('Location', Url::toRoute($route, true));

        return $dataProvider;
    }

    /**
     * @param \netis\crud\db\ActiveRecord $model
     *
     * @return null|yii\db\Transaction
     */
    protected function beforeExecute($model)
    {
        $trx = null;

        if ($this->singleTransaction) {
            $trx = $model->getDb()->getTransaction() === null ? $model->getDb()->beginTransaction() : null;
        }

        if ($model->getBehavior('trackable') !== null) {
            $model->beginChangeset();
        }

        return $trx;
    }

    /**
     * @param \netis\crud\db\ActiveRecord $model
     * @param null|yii\db\Transaction $transaction
     */
    protected function afterExecute($model, $transaction)
    {
        if ($model->getBehavior('trackable') !== null) {
            $model->endChangeset();
        }

        if ($transaction !== null) {
            $transaction->commit();
        }
    }

    public function setSuccessMessage(ActiveRecord $model, $skippedModels, $failedModels, $successModels)
    {
        $message = Yii::t('netis/fsm/app', '{number} out of {total} {model} has been successfully updated.', [
            'number' => count($successModels),
            'total'  => count($successModels) + count($failedModels) + count($skippedModels),
            'model'  => $model->getCrudLabel('relation'),
        ]);
        $this->setFlash($this->postFlashKey, $message);

        if (count($failedModels) === 0) {
            return;
        }

        $errorMessage = '';
        foreach ($failedModels as $label => $errors) {
            $errorMessage .= Html::tag('li', $label . ':&nbsp;' . $errors);
        }

        $errorMessage = Html::tag('ul', $errorMessage);
        $this->setFlash('error', Yii::t('netis/fsm/app', 'Failed to change status for following orders: ') . $errorMessage);
    }

    /**
     * Prepares response params, like fields and relations.
     *
     * @param \netis\crud\db\ActiveRecord $model
     *
     * @return array
     */
    protected function getResponse($model)
    {
        $hiddenAttributes = array_filter(explode(',', Yii::$app->getRequest()->getQueryParam('hide', '')));
        $fields = FormBuilder::getFormFields($model, $this->getFields($model, 'form'), false, $hiddenAttributes);

        return [
            'model'     => $model,
            'fields'    => empty($fields) ? [] : [$fields],
            'relations' => $this->getModelRelations($model, $this->getExtraFields($model)),
        ];
    }
}
