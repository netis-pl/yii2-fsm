<?php

class {ClassName} extends CDbMigration
{
	public function safeUp()
	{
        $this->createTable('{Schema}possible_{TableSuffix}', array(
            'id' => 'pk',
            'source_status_id' => 'integer NOT NULL REFERENCES {TableName} ({PrimaryKey}) ON UPDATE CASCADE ON DELETE CASCADE',
            'target_status_id' => 'integer NOT NULL REFERENCES {TableName} ({PrimaryKey}) ON UPDATE CASCADE ON DELETE CASCADE',
            'label' => 'varchar NOT NULL',
            'post_label' => 'varchar NOT NULL',
            'icon' => 'varchar NOT NULL',
            'css_class' => 'varchar NOT NULL',
            'auth_item_name' => 'character varying',
            'confirmation_required' => 'boolean NOT NULL DEFAULT TRUE',
            'display_order' => 'integer',
            'disabled' => 'boolean NOT NULL DEFAULT FALSE',
            'author_id' => 'integer NOT NULL REFERENCES public.{{users}} (id) ON DELETE RESTRICT ON UPDATE CASCADE',
            'editor_id' => 'integer NOT NULL REFERENCES public.{{users}} (id) ON DELETE RESTRICT ON UPDATE CASCADE',
            'updated_on' => 'timestamp',
            'created_on' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ));
        $this->createTable('{Schema}performed_{TableSuffix}', array(
            'id' => 'pk',
            'possible_change_id' => 'integer NOT NULL REFERENCES {Schema}possible_{TableSuffix} (id) ON UPDATE CASCADE ON DELETE RESTRICT',
            'source_status_id' => 'integer NOT NULL REFERENCES {TableName} ({PrimaryKey}) ON UPDATE CASCADE ON DELETE CASCADE',
            'target_status_id' => 'integer NOT NULL REFERENCES {TableName} ({PrimaryKey}) ON UPDATE CASCADE ON DELETE CASCADE',
            '{ForeignKey}' => 'integer NOT NULL REFERENCES {MainTableName} ({MainPrimaryKey}) ON UPDATE CASCADE ON DELETE CASCADE',
            'user_id' => 'integer NOT NULL REFERENCES public.{{users}} (id) ON UPDATE CASCADE ON DELETE CASCADE',
            'performed_on' => 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ));
	}

	public function safeDown()
	{
        $this->dropTable('{Schema}performed_{TableSuffix}');
        $this->dropTable('{Schema}possible_{TableSuffix}');
	}
}

