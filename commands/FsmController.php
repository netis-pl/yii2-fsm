<?php

namespace netis\fsm\commands;

use yii\console\Controller;
use yii\helpers\Inflector;

/**
 * 
 */
class FsmController extends Controller
{

    /**
     * @var string the directory that stores the migrations. This must be specified
     * in terms of a path alias, and the corresponding directory must exist.
     * Defaults to 'application.migrations' (meaning 'protected/migrations').
     */
    public $migrationPath = '@app/migrations';

    /**
     * @var string the path of the template file for generating new migrations. This
     * must be specified in terms of a path alias (e.g. application.migrations.template).
     * If not set, an internal template will be used.
     */
    public $templateFile  = '@netis/yii2-fsm/migrations/template';

    /**
     * @var boolean whether to execute the migration in an interactive mode. Defaults to true.
     * Set this to false when performing migration in a cron job or background process.
     */
    public $interactive   = true;

    public function beforeAction($action)
    {
        $path = Yii::getAlias($this->migrationPath);
        if ($path === false || !is_dir($path)) {
            echo 'Error: The migration directory does not exist: ' . $this->migrationPath . "\n";
            exit(1);
        }
        $this->migrationPath = $path;

        return parent::beforeAction($action);
    }

    /**
     * Changes:
     * - load an AR model and use constant name
     * @param $modelClass namespace
     * @param $tableSuffix string new tables suffix, most often the model's table name in singular form with _changes appended, ex. order_status_changes
     * @param $relation string name of relation from model pointing to the main model, ex. orders
     * @param $schema
     */
    public function actionCreate($modelClass, $tableSuffix, $relation, $schema = '')
    {
        $model         = new $modelClass;
        $modelRelation = $model->getRelation($relation);
        $mainModel     = new $modelRelation->modelClass;
        $name          = 'm' . gmdate('ymd_His') . '_install_fsm';
        $content       = strtr($this->getTemplate(), [
            '{ClassName}'      => $name,
            '{TableName}'      => $model->tableName(),
            '{TableSuffix}'    => $tableSuffix,
            '{Schema}'         => !empty($schema) ? $schema . '.' : null,
            '{PrimaryKey}'     => $model::primaryKey()[0],
            '{ForeignKey}'     => Inflector::singularize($mainModel::tableName()) . '_id',
            '{MainTableName}'  => $mainModel->tableName(),
            '{MainPrimaryKey}' => $mainModel::primaryKey()[0],
        ]);
        $file          = $this->migrationPath . DIRECTORY_SEPARATOR . $name . '.php';
        if ($this->confirm("Create new migration '$file'?")) {
            file_put_contents($file, $content);
            echo "New migration created successfully.\n";
        }
    }

    public function confirm($message, $default = false)
    {
        if (!$this->interactive)
            return true;
        return parent::confirm($message, $default);
    }

    protected function getTemplate()
    {
        return file_get_contents(Yii::getAlias($this->templateFile) . '.php');
    }

}
