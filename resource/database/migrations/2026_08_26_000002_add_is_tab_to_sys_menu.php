<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIsTabToSysMenu extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('sys_menu');

        if (!$table->hasColumn('is_tab')) {
            $table->addColumn('is_tab', 'integer', [
                'signed' => false,
                'default' => 1,
                'comment' => '是否显示在tags标签: 0否 1是',
                'after' => 'is_show',
            ]);
        }

        $table->update();
    }
}