<?php

namespace Flute\Modules\BansComms\Driver\Items;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Table\TableBuilder;
use Flute\Core\Table\TableColumn;
use Flute\Core\Table\TablePreparation;
use Flute\Modules\BansComms\Contracts\DriverInterface;
use Spiral\Database\Exception\StatementException;
use Spiral\Database\Injection\Fragment;
use Spiral\Database\Injection\Parameter;

class PisexDriver implements DriverInterface
{
    private $sid;

    public function __construct(array $config = [])
    {
        $this->sid = isset($config['sid']) ? $config['sid'] : 1;
    }

    public function getCommsColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('punish_type', __('banscomms.table.type')))
            ->setRender("{{PUNISH_TYPE}}", $this->typeFormatRender()));

        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created', __('banscomms.table.created')))->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->dateFormatRender()),
            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),
            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)->setOrderable(false)
                ->setRender('{{KEY}}', $this->timeFormatRender()),
        ]);
    }

    public function getBansColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created', __('banscomms.table.created')))->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->dateFormatRender()),
            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),
            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)->setOrderable(false)
                ->setRender('{{KEY}}', $this->timeFormatRender()),
        ]);
    }

    private function dateFormatRender() : string
    {
        return '
            function(data, type, full) {
                if (type === "display") {
                    let date = new Date(data * 1000);
                    return  ("0" + date.getDate()).slice(-2) + "." +
                            ("0" + (date.getMonth() + 1)).slice(-2) + "." +
                           date.getFullYear() + " " +
                           ("0" + date.getHours()).slice(-2) + ":" +
                           ("0" + date.getMinutes()).slice(-2);
                }
                return data;
            }
        ';
    }

    private function typeFormatRender() : string
    {
        return '
            function(data, type) {
                if (type === "display") {
                    switch(parseInt(data)) {
                        case 1:
                            return `<i class="type-icon ph-bold ph-microphone-slash"></i>`;
                        case 2:
                            return `<i class="type-icon ph-bold ph-chat-circle-dots"></i>`;
                        default:
                            return ``;
                    }
                }
                return data;
            }
        ';
    }

    private function timeFormatRender() : string
    {
        return "
            function(data, type, full) {
                let created = full[4];
                let expires = full[10];

                if (expires == 0) {
                    return '<div class=\"ban-chip bans-forever\">'+ t(\"banscomms.table.forever\") +'</div>';
                } else if (Date.now() / 1000 >= expires) {
                    return '<div class=\"ban-chip bans-end\">' + secondsToReadable(expires) + '</div>';
                } else {
                    return '<div class=\"ban-chip\">' + secondsToReadable(expires) + '</div>';
                }
            }
        ";
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
            ->where('punishments.steamid', $steam->value);

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
                    'created',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires',
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
            ->where('punishments.steamid', $steam->value);

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
                    'punish_type',
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires',
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
                    'punish_type',
                    'user_url',
                    'avatar',
                    'name',
                    '',
                    'created',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires',
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
                    'created',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires',
                    'duration',
                    ''
                ],
                $result
            )
        ];
    }

    private function prepareSelectQuery(Server $server, string $dbname, array $columns, array $search, array $order, string $type = 'bans')
    {
        $punishTypes = ($type === 'comms') ? [1, 2] : [0];

        return $this->buildSelectQuery($dbname, $punishTypes, $columns, $search, $order);
    }

    private function buildSelectQuery(string $dbname, array $punishTypes, array $columns, array $search, array $order)
    {
        $db = dbal()->database($dbname);

        $select = $db->select()->from('punishments')->columns([
            'punishments.*',
            'admins.name as admin_name',
            'admins.steamid as admin_steamid'
        ])->leftJoin('admins')->on('admins.id', '=', 'punishments.admin_id');

        $select->where('punishments.punish_type', 'IN', new Parameter($punishTypes));

        if ($this->sid != -1) {
            $select->andWhere(function ($query) {
                $query->where('punishments.server_id', $this->sid)
                    ->orWhere('punishments.server_id', -1);
            });
        }

        // Applying global search
        if (isset($search['value']) && !empty($search['value'])) {
            $select->andWhere(function ($select) use ($search) {
                $select->where('punishments.name', 'like', '%' . $search['value'] . '%')
                    ->orWhere('punishments.steamid', 'like', '%' . $search['value'] . '%')
                    ->orWhere('admins.steamid', 'like', '%' . $search['value'] . '%')
                    ->orWhere('admins.name', 'like', '%' . $search['value'] . '%')
                    ->orWhere('punishments.reason', 'like', '%' . $search['value'] . '%');
            });
        }

        // Applying ordering
        foreach ($order as $orderItem) {
            $columnIndex = $orderItem['column'];
            $columnName = $columns[$columnIndex]['name'];
            $direction = strtolower($orderItem['dir']) === 'asc' ? 'ASC' : 'DESC';

            if ($columns[$columnIndex]['orderable'] === 'true') {
                $select->orderBy($columnName, $direction);
            }
        }

        return $select;
    }

    public function getCounts(string $dbname, array &$excludeAdmins = [], bool $wasAll = false) : array
    {
        $db = dbal()->database($dbname);

        $bansCountQuery = $db->select()->from('punishments')
            ->where('punish_type', 0);

        $commsCountQuery = $db->select()->from('punishments')
            ->where('punish_type', 'IN', new Parameter([1]));

        $gagsCountQuery = $db->select()->from('punishments')
            ->where('punish_type', 'IN', new Parameter([2]));

        if ($this->sid != -1) {
            $bansCountQuery->andWhere(function ($query) {
                $query->where('punishments.server_id', $this->sid)
                    ->orWhere('punishments.server_id', -1);
            });

            $commsCountQuery->andWhere(function ($query) {
                $query->where('punishments.server_id', $this->sid)
                    ->orWhere('punishments.server_id', -1);
            });

            $gagsCountQuery->andWhere(function ($query) {
                $query->where('punishments.server_id', $this->sid)
                    ->orWhere('punishments.server_id', -1);
            });
        }

        $bansCount = $bansCountQuery->count();
        $commsCount = $commsCountQuery->count();
        $gagsCount = $gagsCountQuery->count();

        $adminSteamidsQuery = $db->select('steamid')->from('admins')->distinct();

        if ($this->sid != -1) {
            $adminSteamidsQuery->innerJoin('admins_servers')->on('admins_servers.admin_id', '=', 'admins.id')
                ->where('admins_servers.server_id', $this->sid);
        }

        $adminSteamids = $adminSteamidsQuery->fetchAll();

        $newAdmins = [];
        foreach ($adminSteamids as $admin) {
            $adminSteamid = $admin['steamid'];
            if (!in_array($adminSteamid, $excludeAdmins)) {
                $excludeAdmins[] = $adminSteamid;
                $newAdmins[] = $adminSteamid;
            }
        }

        return [
            'bans' => $bansCount,
            'mutes' => $commsCount,
            'gags' => $gagsCount,
            'admins' => count($newAdmins)
        ];
    }

    private function getSteamIds64(array $results) : array
    {
        $steamIds64 = [];

        foreach ($results as $result) {
            if (!empty($result['steamid']) && !isset($steamIds64[$result['steamid']])) {
                $steamIds64[$result['steamid']] = $result['steamid'];
            }

            if (!empty($result['admin_steamid']) && !isset($steamIds64[$result['admin_steamid']])) {
                $steamIds64[$result['admin_steamid']] = $result['admin_steamid'];
            }
        }

        return $steamIds64;
    }

    private function mapUsersDataToResult(array $results, array $usersData) : array
    {
        $mappedResults = [];

        foreach ($results as $result) {
            $steamId64 = $result['steamid'];

            if (isset($usersData[$steamId64])) {
                $user = $usersData[$steamId64];
                $result['avatar'] = $user->avatar;
                $result['name'] = $user->personaname;
            } else {
                $result['avatar'] = url('assets/img/no_avatar.webp')->get();
            }

            $result['user_url'] = url('profile/search/' . $result['steamid'])->addParams([
                "else-redirect" => "https://steamcommunity.com/profiles/" . $result['steamid']
            ])->get();

            if (!empty($result['admin_steamid'])) {
                $adminSteamId64 = $result['admin_steamid'];

                if (isset($usersData[$adminSteamId64])) {
                    $adminUser = $usersData[$adminSteamId64];
                    $result['admin_avatar'] = $adminUser->avatar;
                    $result['admin_name'] = $adminUser->personaname;
                } else {
                    $result['admin_avatar'] = url('assets/img/no_avatar.webp')->get();
                    $result['admin_name'] = $result['admin_name'] ?? 'Console';
                }

                $result['admin_url'] = url('profile/search/' . $result['admin_steamid'])->addParams([
                    "else-redirect" => "https://steamcommunity.com/profiles/" . $result['admin_steamid']
                ])->get();
            } else {
                $result['admin_avatar'] = url('assets/img/no_avatar.webp')->get();
                $result['admin_name'] = 'Console';
                $result['admin_url'] = '';
            }

            $mappedResults[] = $result;
        }

        return $mappedResults;
    }

    public function getName() : string
    {
        return "PisexAdmin";
    }
}
