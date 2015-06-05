<?php

use yii\helpers\Html;
use yii\helpers\Url;

$action = Yii::$app->controller->action;
//$this->buildNavigation($action, $model);
//$title = Yii::t('app', 'Status').' '.Html::encode($model->label()).' '.Html::encode($model);
//$this->setPageTitle($title);
?>
<?= netis\utils\web\Alerts::widget() ?>

<?php echo Yii::t('app', 'Change status from {source} to {target}', array(
    '{source}' => '<span class="badge badge-default">'.Yii::$app->formatter->format($sourceState, $format).'</span>',
    '{target}' => '<span class="badge badge-primary">'.Yii::$app->formatter->format($targetState, $format).'</span>',
)); ?>

<div class="form">
<?php echo Html::label(Yii::t('app', 'Reason'), 'reason'); ?>
    <div class="row">
        <div class="span4">
            <?php echo Html::textArea('reason', '', array('cols'=>80, 'rows'=>5,'style'=>'width: 25em;')); ?>
        </div>
    </div>
    <?= Html::a('<i class="fa fa-save"></i>'.Yii::t("app", "Confirm"), Url::toRoute([$action->id, 'id'=>$model->primaryKey, 'targetState'=>$targetState, 'confirmed'=>1]), ['class' => 'btn btn-success']) ?>
    <?= Html::a(Yii::t('app', 'Cancel'), Url::toRoute([(isset($_GET['return'])) ? $_GET['return'] : 'view', 'id'=>$model->primaryKey])); ?>
</div>


