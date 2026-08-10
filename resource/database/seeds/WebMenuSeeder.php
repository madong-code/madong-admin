<?php

declare(strict_types=1);
namespace resource\database\seeds;

use app\model\web\Menu;
use core\io\uuid\Snowflake;
use Illuminate\Database\Seeder;

class WebMenuSeeder extends Seeder
{
    public function run(): void
    {
        $menus = include base_path('resource/data/menu/web.php');

        Menu::truncate();

        foreach ($menus as $menu) {
            $this->insertMenu($menu, 0);
        }
    }

    private function insertMenu(array $menu, int|string $pid): void
    {
        $menuModel = new Menu();
        $menuModel->id = Snowflake::generate();
        $menuModel->pid = $pid;
        $menuModel->app = $menu['app'] ?? 'web';
        $menuModel->category = $menu['category'] ?? 1;
        $menuModel->source = $menu['source'] ?? 'system';
        $menuModel->code = $menu['code'] ?? '';
        $menuModel->is_public = $menu['is_public'] ?? 0;
        $menuModel->name = $menu['name'] ?? '';
        $menuModel->url = $menu['url'] ?? '';
        $menuModel->icon = $menu['icon'] ?? '';
        $menuModel->level = $menu['level'] ?? 1;
        $menuModel->type = $menu['type'] ?? 1;
        $menuModel->sort = $menu['sort'] ?? 0;
        $menuModel->target = $menu['target'] ?? 1;
        $menuModel->is_show = $menu['is_show'] ?? 1;
        $menuModel->enabled = $menu['enabled'] ?? 1;
        $menuModel->created_at = $menu['created_at'] ?? time();
        $menuModel->updated_at = $menu['updated_at'] ?? time();
        $menuModel->deleted_at = $menu['deleted_at'] ?? null;
        $menuModel->save();

        if (!empty($menu['children'])) {
            foreach ($menu['children'] as $child) {
                $this->insertMenu($child, $menuModel->id);
            }
        }
    }
}