<?php
/* @var $this NetController */
/* @var $model NetActiveRecord */
/* @var $targetState mixed */
/* @var $states array */

use yii\helpers\Html;

$this->params['menu'] = Yii::$app->controller->getMenu(Yii::$app->controller->action, $model);
?>

<?= netis\utils\web\Alerts::widget() ?>

<div>
    <?php foreach ($states as $status): ?>
        <?php if (!$status['enabled']) continue; ?>
        <?= Html::a("<i class='fa fa-{$status["icon"]}'></i>{$status['label']}", $status['url'], ['style' => 'margin-left: 2em', 'class' => "btn {$status['class']}"]); ?>
    <?php endforeach; ?>
</div>
