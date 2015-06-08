<?php

use yii\helpers\Html;
use yii\helpers\Url;

$action               = Yii::$app->controller->action;
$this->params['menu'] = Yii::$app->controller->getMenu($action, $model);
?>
<?= netis\utils\web\Alerts::widget() ?>

<?php
echo Yii::t('netis/fsm/app', 'Change status from {source} to {target}', [
    'source' => '<span class="badge badge-default">' . Yii::$app->formatter->format($sourceState, $format) . '</span>',
    'target' => '<span class="badge badge-primary">' . Yii::$app->formatter->format($targetState, $format) . '</span>',
]);
?>

<div class="form">
    <?php echo Html::label(Yii::t('netis/fsm/app', 'Reason'), 'reason'); ?>
    <div class="row">
        <div class="span4">
            <?php echo Html::textArea('reason', '', ['cols' => 80, 'rows' => 5, 'style' => 'width: 25em;']); ?>
        </div>
    </div>
    <?= Html::a('<i class="fa fa-save"></i>' . Yii::t("netis/fsm/app", "Confirm"), Url::toRoute([$action->id, 'id' => $model->primaryKey, 'targetState' => $targetState, 'confirmed' => 1]), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Yii::t('netis/fsm/app', 'Cancel'), Url::toRoute([(isset($_GET['return'])) ? $_GET['return'] : 'view', 'id' => $model->primaryKey])); ?>
</div>


