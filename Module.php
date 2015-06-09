<?php

namespace netis\fsm;

class Module extends \yii\base\Module implements \yii\base\BootstrapInterface
{

    public function bootstrap($app)
    {
        $app->i18n->translations['netis/fsm/*'] = [
            'class'          => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath'       => '@netis/yii2-fsm/messages',
            'fileMap'        => [
                'netis/fsm/app' => 'app.php',
                'netis/fsm/yii' => 'yii.php',
            ],
        ];
    }

}
