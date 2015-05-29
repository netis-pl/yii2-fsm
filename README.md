niix-fsm
====

Extensions to the Yii PHP framework providing tools for switching status of a model.

Provides:
* a controller action with a view to perform status transitions
* a model behavior to bind custom logic to status transitions
* a controller method to build a menu
* state graph configuration

Requires:
* niix

## Installation

Currently, the repository is private, so add it manually to the composer.json file:

~~~json

    "repositories": [
        {
            "type": "vcs",
            "url": "http://git.nets.com.pl/git/niix-fsm.git"
        }
    ],

~~~

Using global composer:

~~~bash

composer require nineinchnick/niix-fsm:dev-master

~~~

Define the `fsm` alias in application configuration file as `'fsm' => 'application.vendor.nineinchnick.niix-fsm'`.

## Usage

* Implement the `IStateful` interface in selected AR model. You might want to add `Yii::import('fsm.components.*')` at the top of the file.
* Adjust rules to remove attributes from the `transition` scenario. The `NetActiveRecord.filterRules()` helper method should be used for that.
* In the controller, add the `state` action and include it in the context menu, adjust according to comments:

~~~php

    public function actions()
    {
        return array(
            'state'=>array(
                'class'=>'fsm.components.StateAction',
                'updateAuthItem' => 'update Model', // adjust here, insert AR model name
                'isAdminCallback' => array('NetController', 'isAdmin'),
            ),
        );
    }

    protected function buildNavigation(CAction $action, NetActiveRecord $model, $readOnly = false, $horizontal = true)
    {
        $result = parent::buildNavigation($action, $model, $readOnly, $horizontal);
        if ($horizontal || $model->primaryKey !== null) {
            Yii::import('fsm.components.StateAction');
            $transitions = $model->getTransitionsGroupedByTarget();
            $this->menu[] = StateAction::getContextMenuItem($action, $transitions, $model, $model->status_id, self::isAdmin()); // adjust status column
        }
        return $result;
    }

~~~


## Todo

* group state transition logic into named workflows
