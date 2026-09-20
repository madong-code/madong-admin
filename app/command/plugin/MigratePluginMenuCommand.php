<?php
declare(strict_types=1);

/**
 *+------------------
 * madong
 *+------------------
 * Copyright (c) https://gitee.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Official Website: http://www.madong.tech
 */

namespace app\command\plugin;

use app\command\BaseCommand;
use app\model\system\menu\Menu;
use core\io\uuid\Snowflake;
use Illuminate\Database\Capsule\Manager as Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 插件菜单同步
 *
 * 将 plugin/{name}/resource/data/menu/{admin,web}.php 与数据库对齐。
 * （菜单目录取自 config/info.php 的 resource.menu，缺省 data/menu）
 *
 * 三种模式：
 *   - 增量（默认）：仅补插文件中存在但数据库中缺失的节点，已存在节点跳过（安全、幂等）
 *   - --sync：智能同步 —— 新增缺失节点 + 更新已有节点字段 + 删除文件中不存在的节点
 *   - --full：清空该插件所有菜单后全量重导（仅清空该插件的菜单，不影响其他插件）
 *
 * 使用方法：
 *   php webman madong:migrate-plugin-menu workflow                        # 增量补插
 *   php webman madong:migrate-plugin-menu workflow --sync                 # 智能同步
 *   php webman madong:migrate-plugin-menu workflow --full                 # 清空重导（仅清空该插件）
 *   php webman madong:migrate-plugin-menu workflow --all                  # 同步所有插件
 *   php webman madong:migrate-plugin-menu workflow --sync --no-interaction # CI/CD 免确认
 *
 * @author Mr.April
 * @since 1.0.0
 */
#[AsCommand(
    name: 'madong:migrate-plugin-menu',
    description: '同步插件菜单到 sys_menu/web_menu（增量/--sync 智能同步/--full 清空重导）',
    hidden: false
)]
class MigratePluginMenuCommand extends BaseCommand
{
    /**
     * sys_menu 可更新字段
     */
    private const ADMIN_UPDATABLE_FIELDS = [
        'title', 'level', 'type', 'sort', 'path', 'component', 'redirect',
        'icon', 'is_show', 'is_tab', 'is_link', 'link_url', 'enabled', 'open_type',
        'is_cache', 'is_sync', 'is_affix', 'is_global', 'variable',
        'methods', 'is_frame',
    ];

    /**
     * web_menu 可更新字段
     */
    private const WEB_UPDATABLE_FIELDS = [
        'name', 'url', 'category', 'type', 'sort', 'target',
        'icon', 'is_show', 'enabled', 'is_public', 'is_no_auth',
    ];

    /**
     * 已加载插件菜单的 code→id 映射（用于 pid_code 解析）
     */
    private array $adminCodeMap = [];
    private array $webCodeMap = [];

    protected function configure(): void
    {
        $this->addArgument(
            'plugin',
            InputArgument::REQUIRED,
            '插件名称（如 workflow），使用 --all 可同步所有插件'
        );
        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            '同步所有已安装的插件菜单'
        );
        $this->addOption(
            'sync',
            's',
            InputOption::VALUE_NONE,
            '智能同步：新增缺失 + 更新已有 + 删除文件中不存在的节点'
        );
        $this->addOption(
            'full',
            'o',
            InputOption::VALUE_NONE,
            '全量重导：清空该插件所有菜单后重新导入（仅影响此插件）'
        );
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $sync    = $input->getOption('sync');
        $full    = $input->getOption('full');
        $all     = $input->getOption('all');
        $plugin  = $input->getArgument('plugin');

        $io->title('Plugin Menu Migration');

        if ($sync && $full) {
            $io->error('--sync 和 --full 互斥，请只选择其中一种模式。');
            return Command::FAILURE;
        }

        // 收集要处理的插件列表
        $plugins = $all ? $this->discoverPlugins() : [$plugin];

        if (empty($plugins)) {
            $io->warning('没有找到可同步的插件。');
            return Command::SUCCESS;
        }

        $io->section(sprintf('将处理 %d 个插件：%s', count($plugins), implode(', ', $plugins)));

        $overallResult = Command::SUCCESS;

        foreach ($plugins as $name) {
            $io->section("插件: {$name}");

            $pluginPath = base_path("plugin/{$name}");
            if (!is_dir($pluginPath)) {
                $io->warning("插件目录不存在: {$pluginPath}，跳过。");
                continue;
            }

            $source = "plugin:{$name}";

            // 预加载现有菜单 code 映射
            $this->preloadCodeMaps($source);

            $result = match (true) {
                $full => $this->fullMigrate($io, $input, $name, $source),
                $sync => $this->syncMigrate($io, $input, $name, $source),
                default => $this->incrementalMigrate($io, $name, $source),
            };

            if ($result === Command::FAILURE) {
                $overallResult = Command::FAILURE;
            }
        }

        return $overallResult;
    }

    // ========================
    //  插件发现
    // ========================

    private function discoverPlugins(): array
    {
        $pluginDir = base_path('plugin');
        if (!is_dir($pluginDir)) {
            return [];
        }

        $plugins = [];
        $iterator = new \DirectoryIterator($pluginDir);

        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }
            $name = $item->getFilename();
            $infoPath = $pluginDir . '/' . $name . '/config/info.php';
            if (file_exists($infoPath)) {
                $plugins[] = $name;
            }
        }

        sort($plugins);
        return $plugins;
    }

    // ========================
    //  Code 映射预加载
    // ========================

    private function preloadCodeMaps(string $source): void
    {
        $this->adminCodeMap = [];
        $this->webCodeMap = [];

        // sys_menu code 映射
        $adminMenus = Menu::query()
            ->where('source', $source)
            ->whereNotNull('code')
            ->get(['id', 'code', 'pid', 'type']);

        foreach ($adminMenus as $menu) {
            if (!empty($menu->code)) {
                $this->adminCodeMap[$menu->code] = [
                    'id' => $menu->id,
                    'pid' => $menu->pid,
                    'type' => $menu->type,
                ];
            }
        }

        // web_menu code 映射
        $webMenus = Db::table('web_menu')
            ->where('source', $source)
            ->whereNotNull('code')
            ->get(['id', 'code', 'pid', 'type']);

        foreach ($webMenus as $menu) {
            if (!empty($menu->code)) {
                $this->webCodeMap[$menu->code] = [
                    'id' => $menu->id,
                    'pid' => $menu->pid,
                    'type' => $menu->type,
                ];
            }
        }
    }

    // ========================
    //  菜单文件加载
    // ========================

    /**
     * 加载插件的所有菜单文件
     *
     * @return array{admin: array, web: array}
     */
    private function loadPluginMenus(string $pluginName): array
    {
        $menuDir = $this->resolveMenuDir(base_path("plugin/{$pluginName}"));

        return [
            'admin' => $this->loadMenuFile($menuDir . '/admin.php'),
            'web'   => $this->loadMenuFile($menuDir . '/web.php'),
        ];
    }

    /**
     * 解析插件菜单目录
     * 优先读取 config/info.php 的 resource.menu，缺省 resource/data/menu
     */
    private function resolveMenuDir(string $pluginPath): string
    {
        $infoFile = $pluginPath . '/config/info.php';
        $info     = is_file($infoFile) ? include $infoFile : [];
        $menuDir  = is_array($info) ? ($info['resource']['menu'] ?? null) : null;

        return $pluginPath . '/resource/' . ($menuDir ?: 'data/menu');
    }

    private function loadMenuFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $menus = include $filePath;

        if (!is_array($menus)) {
            return [];
        }

        return $menus;
    }

    // ========================
    //  模式 1：增量（默认）
    // ========================

    private function incrementalMigrate(SymfonyStyle $io, string $pluginName, string $source): int
    {
        $menus = $this->loadPluginMenus($pluginName);
        $this->reportMenuFiles($io, $menus);

        $totalInserted = 0;
        $totalSkipped = 0;

        // admin 菜单
        if (!empty($menus['admin'])) {
            [$inserted, $skipped] = $this->syncAdminMenus($menus['admin'], $source, false);
            $totalInserted += $inserted;
            $totalSkipped += $skipped;
            $io->text(sprintf('  admin: 新增 %d 条，跳过 %d 条', $inserted, $skipped));
        }

        // web 菜单
        if (!empty($menus['web'])) {
            [$inserted, $skipped] = $this->syncWebMenus($menus['web'], $source, false);
            $totalInserted += $inserted;
            $totalSkipped += $skipped;
            $io->text(sprintf('  web:   新增 %d 条，跳过 %d 条', $inserted, $skipped));
        }

        $io->success(sprintf('增量同步完成：总计新增 %d 条，跳过 %d 条。', $totalInserted, $totalSkipped));
        return Command::SUCCESS;
    }

    // ========================
    //  模式 2：智能同步 --sync
    // ========================

    private function syncMigrate(SymfonyStyle $io, InputInterface $input, string $pluginName, string $source): int
    {
        if ($input->isInteractive()) {
            $io->caution('⚠ --sync 模式将：新增缺失 + 更新已有 + 删除文件中不存在的节点！');
            if (!$io->confirm('确认执行智能同步？', false)) {
                $io->text('已取消操作。');
                return Command::SUCCESS;
            }
        }

        $menus = $this->loadPluginMenus($pluginName);
        $this->reportMenuFiles($io, $menus);

        $totalInserted = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalDeleted = 0;

        // admin 菜单
        if (!empty($menus['admin'])) {
            [$inserted, $updated, $skipped] = $this->syncAdminMenus($menus['admin'], $source, true);
            $totalInserted += $inserted;
            $totalUpdated += $updated;
            $totalSkipped += $skipped;

            // 删除文件中不存在的节点
            $fileCodes = $this->collectCodes($menus['admin']);
            $deleted = Menu::query()
                ->where('source', $source)
                ->whereNotIn('code', $fileCodes)
                ->delete();
            $totalDeleted += $deleted;

            $io->text(sprintf('  admin: 新增 %d，更新 %d，跳过 %d，删除 %d', $inserted, $updated, $skipped, $deleted));
        }

        // web 菜单
        if (!empty($menus['web'])) {
            [$inserted, $updated, $skipped] = $this->syncWebMenus($menus['web'], $source, true);
            $totalInserted += $inserted;
            $totalUpdated += $updated;
            $totalSkipped += $skipped;

            $fileCodes = $this->collectCodes($menus['web']);
            $deleted = Db::table('web_menu')
                ->where('source', $source)
                ->whereNotIn('code', $fileCodes)
                ->delete();
            $totalDeleted += $deleted;

            $io->text(sprintf('  web:   新增 %d，更新 %d，跳过 %d，删除 %d', $inserted, $updated, $skipped, $deleted));
        }

        $io->success(sprintf(
            '智能同步完成：新增 %d 条，更新 %d 条，跳过 %d 条，删除 %d 条。',
            $totalInserted, $totalUpdated, $totalSkipped, $totalDeleted
        ));

        return Command::SUCCESS;
    }

    // ========================
    //  模式 3：全量重导 --full
    // ========================

    private function fullMigrate(SymfonyStyle $io, InputInterface $input, string $pluginName, string $source): int
    {
        if ($input->isInteractive()) {
            $io->caution(sprintf('⚠ --full 模式将清空 source="%s" 的所有菜单后重新导入！', $source));
            $io->warning('   所有该插件的菜单 ID 将重新生成（Snowflake），角色-菜单关联可能失效。');
            if (!$io->confirm('确认清空该插件菜单并重新全量导入？', false)) {
                $io->text('已取消操作。');
                return Command::SUCCESS;
            }
        }

        $menus = $this->loadPluginMenus($pluginName);

        // Step 1: 清空该插件的所有菜单
        $adminDeleted = Menu::query()->where('source', $source)->delete();
        $webDeleted = Db::table('web_menu')->where('source', $source)->delete();

        $io->text(sprintf('已清空 sys_menu: %d 条，web_menu: %d 条。', $adminDeleted, $webDeleted));

        // 重建 code 映射
        $this->adminCodeMap = [];
        $this->webCodeMap = [];

        // Step 2: 全量导入
        $totalInserted = 0;

        if (!empty($menus['admin'])) {
            [$inserted] = $this->syncAdminMenus($menus['admin'], $source, false, true);
            $totalInserted += $inserted;
        }

        if (!empty($menus['web'])) {
            [$inserted] = $this->syncWebMenus($menus['web'], $source, false, true);
            $totalInserted += $inserted;
        }

        $io->success(sprintf(
            '全量重导完成：清空 sys_menu %d 条 + web_menu %d 条 → 导入 %d 条。',
            $adminDeleted, $webDeleted, $totalInserted
        ));

        return Command::SUCCESS;
    }

    // ========================
    //  Admin 菜单同步
    // ========================

    /**
     * 同步 admin 菜单
     *
     * @return array{0: int, 1: int, 2?: int} [inserted, updated, skipped]
     */
    private function syncAdminMenus(array $menus, string $source, bool $doUpdate, bool $isFull = false): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($menus as $menu) {
            $this->processAdminNode($menu, $source, 0, $inserted, $skipped, $doUpdate, $updated, $isFull);
        }

        return $doUpdate ? [$inserted, $updated, $skipped] : [$inserted, $skipped];
    }

    /**
     * 递归处理 admin 菜单节点
     */
    private function processAdminNode(
        array $menu,
        string $source,
        int|string $pid,
        int &$inserted,
        int &$skipped,
        bool $doUpdate,
        int &$updated,
        bool $isFull = false
    ): ?string {
        $code = $menu['code'] ?? '';

        // pid_code 解析
        if ($pid === 0 && !empty($menu['pid_code'])) {
            $pid = $this->findParentIdByCode($menu['pid_code'], 'admin');
        }

        if (!$isFull && !empty($code)) {
            // 按 source+code+pid 匹配，不限定 app：历史脏数据（如 app=插件名）也能被匹配并修正
            $existing = Menu::query()
                ->where('source', $source)
                ->where('code', $code)
                ->where('pid', (string) $pid)
                ->first();

            // 未命中同 pid 时回退为仅按 source+code 匹配：
            // 使「调整菜单层级」被识别为更新（pid 由 updateAdminNodeFields 改写），
            // 而不是插入新节点、把旧节点留成不可达孤儿行
            if (!$existing) {
                $existing = Menu::query()
                    ->where('source', $source)
                    ->where('code', $code)
                    ->first();
            }

            if ($existing) {
                if ($doUpdate) {
                    $changed = $this->updateAdminNodeFields($existing, $menu, $pid, $source);
                    $changed ? $updated++ : $skipped++;
                } else {
                    $skipped++;
                }

                $currentId = $existing->id;

                if (!empty($menu['children'])) {
                    foreach ($menu['children'] as $child) {
                        $this->processAdminNode($child, $source, $currentId, $inserted, $skipped, $doUpdate, $updated, $isFull);
                    }
                }

                return $currentId;
            }
        }

        // 不存在（或 full 模式）→ 新增
        $currentId = $this->insertAdminNode($menu, $pid, $source);
        $inserted++;

        // 更新 code 映射
        if (!empty($code)) {
            $this->adminCodeMap[$code] = [
                'id' => $currentId,
                'pid' => $pid,
                'type' => $menu['type'] ?? 1,
            ];
        }

        if (!empty($menu['children'])) {
            foreach ($menu['children'] as $child) {
                $this->processAdminNode($child, $source, $currentId, $inserted, $skipped, $doUpdate, $updated, $isFull);
            }
        }

        return $currentId;
    }

    /**
     * 更新 admin 节点字段
     */
    private function updateAdminNodeFields(Menu $model, array $menu, int|string $pid, string $source): bool
    {
        $changed = false;

        if ((string) $model->pid !== (string) $pid) {
            $model->pid = $pid;
            $changed = true;
        }

        // app 缺省即 admin：文件未声明时也强制校正为默认值（插件靠 source 区分）
        $fileApp = $menu['app'] ?? 'admin';
        if ($this->normalizeVal($model->app) !== $this->normalizeVal($fileApp)) {
            $model->app = $fileApp;
            $changed = true;
        }

        foreach (self::ADMIN_UPDATABLE_FIELDS as $field) {
            if ($field === 'pid') {
                continue;
            }

            $fileValue = $menu[$field] ?? null;
            $modelValue = $model->$field;

            if ($fileValue !== null && $this->normalizeVal($modelValue) !== $this->normalizeVal($fileValue)) {
                $model->$field = $fileValue;
                $changed = true;
            }
        }

        if ($changed) {
            $model->save();
        }

        return $changed;
    }

    /**
     * 插入新的 admin 菜单节点
     */
    private function insertAdminNode(array $menu, int|string $pid, string $source): string
    {
        $model = new Menu();
        $model->id = Snowflake::generate();
        $model->pid = $pid;
        $model->app = $menu['app'] ?? 'admin';
        $model->source = $source;
        $model->title = $menu['title'] ?? '';
        $model->code = $menu['code'] ?? '';
        $model->level = $menu['level'] ?? null;
        $model->type = $menu['type'] ?? 1;
        $model->sort = $menu['sort'] ?? 0;
        $model->path = $menu['path'] ?? '';
        $model->component = $menu['component'] ?? '';
        $model->redirect = $menu['redirect'] ?? '';
        $model->icon = $menu['icon'] ?? '';
        $model->is_show = $menu['is_show'] ?? 1;
        $model->is_tab = $menu['is_tab'] ?? 1;
        $model->is_link = $menu['is_link'] ?? 0;
        $model->link_url = $menu['link_url'] ?? null;
        $model->enabled = $menu['enabled'] ?? 1;
        $model->open_type = $menu['open_type'] ?? 0;
        $model->is_cache = $menu['is_cache'] ?? 0;
        $model->is_sync = $menu['is_sync'] ?? 1;
        $model->is_affix = $menu['is_affix'] ?? 0;
        $model->is_global = $menu['is_global'] ?? 0;
        $model->variable = $menu['variable'] ?? '';
        $model->methods = $menu['methods'] ?? 'GET';
        $model->is_frame = $menu['is_frame'] ?? 0;
        $model->save();

        return $model->id;
    }

    // ========================
    //  Web 菜单同步
    // ========================

    /**
     * 同步 web 菜单
     *
     * @return array{0: int, 1: int, 2?: int} [inserted, skipped, updated]
     */
    private function syncWebMenus(array $menus, string $source, bool $doUpdate, bool $isFull = false): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($menus as $menu) {
            $this->processWebNode($menu, $source, 0, $inserted, $skipped, $doUpdate, $updated, $isFull);
        }

        return $doUpdate ? [$inserted, $updated, $skipped] : [$inserted, $skipped];
    }

    /**
     * 递归处理 web 菜单节点
     */
    private function processWebNode(
        array $menu,
        string $source,
        int|string $pid,
        int &$inserted,
        int &$skipped,
        bool $doUpdate,
        int &$updated,
        bool $isFull = false
    ): ?string {
        $code = $menu['code'] ?? '';

        // pid_code 解析
        if ($pid === 0 && !empty($menu['pid_code'])) {
            $pid = $this->findParentIdByCode($menu['pid_code'], 'web');
        }

        if (!$isFull && !empty($code)) {
            // 按 source+code+pid 匹配，不限定 app：历史脏数据（如 app=插件名）也能被匹配并修正
            $existing = Db::table('web_menu')
                ->where('source', $source)
                ->where('code', $code)
                ->where('pid', (string) $pid)
                ->first();

            // 未命中同 pid 时回退为仅按 source+code 匹配（同 admin：支持调整菜单层级）
            if (!$existing) {
                $existing = Db::table('web_menu')
                    ->where('source', $source)
                    ->where('code', $code)
                    ->first();
            }

            if ($existing) {
                if ($doUpdate) {
                    $changed = $this->updateWebNode((array) $existing, $menu, $pid);
                    if ($changed) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $skipped++;
                }

                $currentId = (string) $existing->id;

                if (!empty($menu['children'])) {
                    foreach ($menu['children'] as $child) {
                        $this->processWebNode($child, $source, $currentId, $inserted, $skipped, $doUpdate, $updated, $isFull);
                    }
                }

                return $currentId;
            }
        }

        // 新增
        $currentId = $this->insertWebNode($menu, $pid, $source);
        $inserted++;

        if (!empty($code)) {
            $this->webCodeMap[$code] = [
                'id' => $currentId,
                'pid' => $pid,
                'type' => $menu['type'] ?? 1,
            ];
        }

        if (!empty($menu['children'])) {
            foreach ($menu['children'] as $child) {
                $this->processWebNode($child, $source, $currentId, $inserted, $skipped, $doUpdate, $updated, $isFull);
            }
        }

        return $currentId;
    }

    /**
     * 更新 web 菜单节点
     */
    private function updateWebNode(array $existing, array $menu, int|string $pid): bool
    {
        $changed = false;
        $id = $existing['id'];

        if ((string) ($existing['pid'] ?? '') !== (string) $pid) {
            Db::table('web_menu')->where('id', $id)->update(['pid' => $pid]);
            $changed = true;
        }

        // app 缺省即 web：文件未声明时也强制校正为默认值（插件靠 source 区分）
        $fileApp = $menu['app'] ?? 'web';
        if ($this->normalizeVal($existing['app'] ?? '') !== $this->normalizeVal($fileApp)) {
            Db::table('web_menu')->where('id', $id)->update(['app' => $fileApp]);
            $changed = true;
        }

        foreach (self::WEB_UPDATABLE_FIELDS as $field) {
            $fileValue = $menu[$field] ?? null;
            $dbValue = $existing[$field] ?? null;

            if ($fileValue !== null && $this->normalizeVal($dbValue) !== $this->normalizeVal($fileValue)) {
                Db::table('web_menu')->where('id', $id)->update([$field => $fileValue]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * 插入新的 web 菜单节点
     */
    private function insertWebNode(array $menu, int|string $pid, string $source): string
    {
        $id = (string) Snowflake::generate();

        $data = [
            'id' => $id,
            'app' => $menu['app'] ?? 'web',
            'category' => $menu['category'] ?? '1',
            'source' => $source,
            'code' => $menu['code'] ?? '',
            'is_public' => $menu['is_public'] ?? 0,
            'is_no_auth' => $menu['is_no_auth'] ?? 0,
            'name' => $menu['name'] ?? $menu['title'] ?? '',
            'url' => $menu['url'] ?? $menu['path'] ?? '',
            'pid' => $pid,
            'level' => $menu['level'] ?? 1,
            'type' => $menu['type'] ?? 1,
            'sort' => $menu['sort'] ?? 0,
            'target' => $menu['target'] ?? 1,
            'icon' => $menu['icon'] ?? '',
            'is_show' => $menu['is_show'] ?? 1,
            'enabled' => $menu['enabled'] ?? 1,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        Db::table('web_menu')->insert($data);

        return $id;
    }

    // ========================
    //  辅助方法
    // ========================

    /**
     * 通过 code 查找父级 ID（支持系统菜单和插件菜单）
     */
    private function findParentIdByCode(string $code, string $type): int|string
    {
        // 先查已加载的插件菜单映射
        $map = $type === 'admin' ? $this->adminCodeMap : $this->webCodeMap;
        if (isset($map[$code])) {
            return $map[$code]['id'];
        }

        // 查系统菜单
        if ($type === 'admin') {
            $parent = Menu::query()
                ->where('code', $code)
                ->first();
            if ($parent) {
                return $parent->id;
            }
        } else {
            $parent = Db::table('web_menu')
                ->where('code', $code)
                ->first();
            if ($parent) {
                return (string) $parent->id;
            }
        }

        return 0;
    }

    private function collectCodes(array $menus): array
    {
        $codes = [];
        foreach ($menus as $menu) {
            if (!empty($menu['code'])) {
                $codes[] = $menu['code'];
            }
            if (!empty($menu['children'])) {
                $codes = array_merge($codes, $this->collectCodes($menu['children']));
            }
        }
        return $codes;
    }

    private function reportMenuFiles(SymfonyStyle $io, array $menus): void
    {
        $parts = [];
        if (!empty($menus['admin'])) {
            $parts[] = sprintf('admin: %d 条', count($menus['admin']));
        }
        if (!empty($menus['web'])) {
            $parts[] = sprintf('web: %d 条', count($menus['web']));
        }
        if (empty($parts)) {
            $io->text('  无菜单文件');
        } else {
            $io->text('  ' . implode('，', $parts));
        }
    }

    private function normalizeVal(mixed $value): string
    {
        return (string) ($value ?? '');
    }
}