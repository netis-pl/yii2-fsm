<?php

use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $model netis\utils\crud\ActiveRecord */
/* @var mixed $sourceState */
/* @var mixed $targetState */
/* @var array $states */
/* @var $controller netis\utils\crud\ActiveController */

$controller = $this->context;
$this->title = $model->getCrudLabel('update').': '.$model->__toString();
$this->params['breadcrumbs'] = $controller->getBreadcrumbs($controller->action, $model);
$this->params['menu'] = $controller->getMenu($controller->action, $model);

$format = $model->getAttributeFormat($model->getStateAttributeName());
$confirmUrl = Url::toRoute([
    $action->id,
    'id' => \netis\utils\crud\Action::exportKey($model->getPrimaryKey(true)),
    'targetState' => $targetState,
    'confirmed' => 1,
]);
$cancelUrl = Url::toRoute([
    (isset($_GET['return'])) ? $_GET['return'] : $controller->action->viewAction,
    'id' => \netis\utils\crud\Action::exportKey($model->getPrimaryKey(true)),
])
?>

<?= Yii::t('netis/fsm/app', 'Change status from {source} to {target}', [
    'source' => '<span class="badge badge-default">' . Yii::$app->formatter->format($sourceState, $format) . '</span>',
    'target' => '<span class="badge badge-primary">' . Yii::$app->formatter->format($targetState, $format) . '</span>',
]); ?>

<?= netis\utils\web\Alerts::widget() ?>

<div class="form">
    <?php echo Html::label(Yii::t('netis/fsm/app', 'Reason'), 'reason'); ?>
    <div class="row">
        <div class="span4">
            <?php echo Html::textArea('reason', '', ['cols' => 80, 'rows' => 5, 'style' => 'width: 25em;']); ?>
        </div>
    </div>
    <?= Html::a('<i class="fa fa-save"></i>' . Yii::t("netis/fsm/app", "Confirm"), Url::toRoute([
        $action->id,
        'id' => $model->primaryKey,
        'targetState' => $targetState,
        'confirmed' => 1,
    ]), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Yii::t('netis/fsm/app', 'Cancel'), Url::toRoute([
        (isset($_GET['return'])) ? $_GET['return'] : 'view',
        'id' => $model->primaryKey,
    ])); ?>
</div>


