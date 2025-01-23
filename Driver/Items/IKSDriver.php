<?php

namespace Flute\Modules\BansComms\Driver\Items;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Table\TableBuilder;
use Flute\Core\Table\TableColumn;
use Flute\Core\Table\TablePreparation;
use Flute\Modules\BansComms\Contracts\DriverInterface;
use Spiral\Database\Exception\StatementException;
use Spiral\Database\Injection\Parameter;

class IksDriver implements DriverInterface
{
    private $sid;

    public function __construct(array $config = [])
    {
        $this->sid = isset($config['sid']) ? $config['sid'] : 1;
    }

    public function getCommsColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('mute_type', __('banscomms.table.type')))
            ->setRender("{{ICON_TYPE}}", $this->iconType()));

        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created_at', __('banscomms.table.created')))->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->timeFormatter()),
            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('end_at', __('banscomms.table.end_date')))->setType('text')
                ->setRender("{{ENDS}}", $this->timeFormatter()),
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),
            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)->setOrderable(false)
                ->setRender('{{KEY}}', $this->lengthFormatter()),
        ]);
    }

    public function getBansColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created_at', __('banscomms.table.created')))->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->timeFormatter()),
            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('end_at', __('banscomms.table.end_date')))->setType('text')
                ->setRender("{{ENDS}}", $this->timeFormatter()),
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),
            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)->setOrderable(false)
                ->setRender('{{KEY}}', $this->lengthFormatter(true)),
        ]);
    }

    protected function lengthFormatter($isBans = false) : string
    {
        return "
            function(data, type, full) {
                " . ($isBans ? "
                let duration = full[11];
                let ends = full[10];
                " : "
                let duration = full[12];
                let ends = full[11];
                ") . "

                if (duration == 0) {
                    return '<div class=\"ban-chip bans-forever\">'+ t(\"banscomms.table.forever\") +'</div>';
                } else if (Date.now() / 1000 >= ends) {
                    return '<div class=\"ban-chip bans-end\">' + secondsToReadable(duration) + '</div>';
                } else {
                    return '<div class=\"ban-chip\">' + secondsToReadable(duration) + '</div>';
                }
            }
        ";
    }

    protected function iconType() : string
    {
        return '
            function(data, type) {
                if (type === "display") {
                    return data == 0 ? `<i class="type-icon ph-bold ph-microphone-slash"></i>` : `<i class="type-icon ph-bold ph-chat-circle-dots"></i>`;
                }
                return data;
            }
        ';
    }

    protected function timeFormatter() : string
    {
        return '
            function(data, type) {
                if (type === "display") {
                    if(data == 0) {
                        return t("banscomms.table.forever");
                    }

                    let date = new Date(data * 1000);
                    return ("0" + (date.getMonth() + 1)).slice(-2) + "-" +
                        ("0" + date.getDate()).slice(-2) + "-" +
                        date.getFullYear() + " " +
                        ("0" + date.getHours()).slice(-2) + ":" +
                        ("0" + date.getMinutes()).slice(-2);
                }
                return data;
            }
        ';
    }

    public function getUserBans(
        User $user,
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ) : array {
        $steam = $user->getSocialNetwork('Steam') ?? $user->getSocialNetwork('HttpsSteam');

        if (!$steam)
            return [];

        $select = $this->prepareSelectQuery($server, $dbname, $columns, $search, $order, 'bans')
            ->where('bans.steam_id', (int) $steam->value);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($select);

        $result = $select->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $result = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'end_at',
                    'duration',
                    ''
                ],
                $result
            )
        ];
    }

    public function getUserComms(
        User $user,
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ) : array {
        $steam = $user->getSocialNetwork('Steam') ?? $user->getSocialNetwork('HttpsSteam');

        if (!$steam)
            return [];

        $select = $this->prepareSelectQuery($server, $dbname, $columns, $search, $order, 'comms')
            ->where('comms.steam_id', (int) $steam->value);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($select);

        $result = $select->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $result = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'mute_type',
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'end_at',
                    'duration',
                    ''
                ],
                $result
            )
        ];
    }

    public function getComms(
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ) : array {
        $select = $this->prepareSelectQuery($server, $dbname, $columns, $search, $order, 'comms');

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($select);

        $result = $select->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $result = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'mute_type',
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'end_at',
                    'duration',
                    ''
                ],
                $result
            )
        ];
    }

    public function getBans(
        Server $server,
        string $dbname,
        int $page,
        int $perPage,
        int $draw,
        array $columns = [],
        array $search = [],
        array $order = []
    ) : array {
        $select = $this->prepareSelectQuery($server, $dbname, $columns, $search, $order, 'bans');

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($select);

        $result = $select->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $result = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'end_at',
                    'duration',
                    ''
                ],
                $result
            )
        ];
    }

    private function prepareSelectQuery(Server $server, string $dbname, array $columns, array $search, array $order, string $type = 'bans') : \Spiral\Database\Query\SelectQuery
    {
        $table = $type;
        $select = dbal()->database($dbname)->table($table)->select()->columns([
            "$table.*",
            'admins.name as admin_name',
            'admins.steam_id as admin_steam_id'
        ]);

        foreach ($columns as $column) {
            if ($column['searchable'] == 'true' && $column['search']['value'] != '') {
                $select->where($column['name'], 'like', "%" . $column['search']['value'] . "%");
            }
        }

        if (isset($search['value']) && !empty($search['value'])) {
            $select->where(function ($q) use ($search, $table) {
                $q->where("$table.name", 'like', "%" . $search['value'] . "%")
                    ->orWhere("$table.reason", 'like', "%" . $search['value'] . "%")
                    ->orWhere("$table.steam_id", 'like', "%" . $search['value'] . "%")
                    ->orWhere("admins.name", 'like', "%" . $search['value'] . "%")
                    ->orWhere('admins.steam_id', 'like', "%" . $search['value'] . "%");
            });
        }

        foreach ($order as $v) {
            $columnIndex = $v['column'];
            $columnName = $columns[$columnIndex]['name'];
            $direction = $v['dir'] === 'asc' ? 'ASC' : 'DESC';

            if ($columns[$columnIndex]['orderable'] == 'true') {
                $select->orderBy($columnName, $direction);
            }
        }

        $select->innerJoin('admins as admins')->on(["$table.admin_id" => 'admins.id']);

        if ($this->sid) {
            $adminIdsSubquery = dbal()->database($dbname)
                ->table('admin_to_server')
                ->select('admin_id')
                ->where('server_id', $this->sid);

            $select->where(function($q) use ($table, $adminIdsSubquery) {
                $q->where("$table.server_id", null)
                  ->orWhere("$table.server_id", $this->sid)
                  ->andWhere('admins.id', 'IN', $adminIdsSubquery);
            });
        }

        return $select;
    }

    public function getCounts(string $dbname, array &$excludeAdmins = [], bool $wasAll = false) : array
    {
        $db = dbal()->database($dbname);

        $bansCount = $db->table('bans')->select()->innerJoin('admins')->on(["bans.admin_id" => 'admins.id']);
        $mutesCount = $db->table('comms')->select()->innerJoin('admins')->on(["comms.admin_id" => 'admins.id'])->where('mute_type', 0);
        $gagsCount = $db->table('comms')->select()->innerJoin('admins')->on(["comms.admin_id" => 'admins.id'])->where('mute_type', 1);

        if (!empty($excludeAdmins)) {
            $bansCount->andWhere([
                'admins.steam_id' => [
                    'NOT IN' => new Parameter($excludeAdmins)
                ]
            ]);
            $mutesCount->andWhere([
                'admins.steam_id' => [
                    'NOT IN' => new Parameter($excludeAdmins)
                ]
            ]);
            $gagsCount->andWhere([
                'admins.steam_id' => [
                    'NOT IN' => new Parameter($excludeAdmins)
                ]
            ]);
        }

        try {
            $uniqueAdmins = $db->table('admins')->select()->distinct()->columns('steam_id');

            $newAdmins = [];
            foreach ($uniqueAdmins->fetchAll() as $admin) {
                if ($admin['steam_id'] === 'CONSOLE')
                    continue;

                if (!in_array($admin['steam_id'], $excludeAdmins)) {
                    $excludeAdmins[] = $admin['steam_id'];
                    $newAdmins[] = $admin['steam_id'];
                }
            }

            return [
                'bans' => $bansCount->count(),
                'mutes' => $mutesCount->count(),
                'gags' => $gagsCount->count(),
                'admins' => sizeof($newAdmins)
            ];
        } catch (StatementException $e) {
            logs()->error($e);

            return [
                'bans' => 0,
                'mutes' => 0,
                'gags' => 0,
                'admins' => 0
            ];
        }
    }

    private function getSteamIds64(array $results) : array
    {
        $steamIds64 = [];

        foreach ($results as $result) {
            if (!empty($result['steam_id']) && !isset($steamIds64[$result['steam_id']])) {
                $steamIds64[$result['steam_id']] = $result['steam_id'];
            }

            if (!empty($result['admin_steam_id']) && !isset($steamIds64[$result['admin_steam_id']])) {
                $steamIds64[$result['admin_steam_id']] = $result['admin_steam_id'];
            }
        }

        return $steamIds64;
    }

    private function mapUsersDataToResult(array $results, array $usersData) : array
    {
        $mappedResults = [];

        foreach ($results as $result) {
            $steamId = $result['steam_id'];

            if (isset($usersData[$steamId])) {
                $user = $usersData[$steamId];
                $result['avatar'] = $user->avatar;
            } else {
                $result['avatar'] = url('assets/img/no_avatar.webp')->get();
            }

            $result['user_url'] = url('profile/search/' . $steamId)->addParams([
                "else-redirect" => "https://steamcommunity.com/profiles/" . $steamId
            ])->get();

            $adminSteam = $result['admin_steam_id'];

            if ($adminSteam !== 'CONSOLE' && isset($usersData[$adminSteam])) {
                $user = $usersData[$adminSteam];
                $result['admin_avatar'] = $user->avatar;
                $result['admin_name'] = $user->personaname;

                $result['admin_url'] = url('profile/search/' . $adminSteam)->addParams([
                    "else-redirect" => "https://steamcommunity.com/profiles/" . $adminSteam
                ])->get();
            } else {
                $result['admin_avatar'] = url('assets/img/no_avatar.webp')->get();
                $result['admin_name'] = 'CONSOLE';
                $result['admin_url'] = '';
            }

            $mappedResults[] = $result;
        }

        return $mappedResults;
    }

    public function getName() : string
    {
        return "IKSAdmin";
    }
}