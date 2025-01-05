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

class ZenithBansDriver implements DriverInterface
{

    protected int $sid = -1;

    public function __construct(array $config = [])
    {

        $this->sid = $config['sid'] ?? -1;
    }

    public function getCommsColumns(TableBuilder $tableBuilder)
    {

        $tableBuilder->addColumn((new TableColumn('type', __('banscomms.table.type')))
            ->setRender("{{ICON_TYPE}}", $this->iconType()));

        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'player_name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created_at', __('banscomms.table.created')))
                ->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->dateFormatRender()),

            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('expires_at', __('banscomms.table.end_date')))
                ->setType('text')
                ->setRender("{{ENDS}}", $this->dateFormatRender()),
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),

            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)
                ->setOrderable(false)
                ->setRender('{{KEY}}', $this->timeCommsFormatRender()),
        ]);
    }

    public function getBansColumns(TableBuilder $tableBuilder)
    {
        $tableBuilder->addColumn((new TableColumn('user_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('avatar', 'player_name', __('banscomms.table.loh'), 'user_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('created_at', __('banscomms.table.created')))
                ->setDefaultOrder()
                ->setRender("{{CREATED}}", $this->dateFormatRender()),

            (new TableColumn('reason', __('banscomms.table.reason')))->setType('text'),
        ]);

        $tableBuilder->addColumn((new TableColumn('admin_url'))->setVisible(false));
        $tableBuilder->addCombinedColumn('admin_avatar', 'admin_name', __('banscomms.table.admin'), 'admin_url', true);

        $tableBuilder->addColumns([
            (new TableColumn('expires_at', __('banscomms.table.end_date')))
                ->setType('text')
                ->setRender("{{ENDS}}", $this->dateFormatRender()),
            (new TableColumn('duration', ''))->setType('text')->setVisible(false),

            (new TableColumn('', __('banscomms.table.length')))
                ->setSearchable(false)
                ->setOrderable(false)
                ->setRender('{{KEY}}', $this->timeFormatRender()),
        ]);
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
        if (!$steam) {
            return [];
        }

        $query = $this->prepareSelectQuery($dbname, 'ban', $server, $columns, $search, $order);

        $steamId64 = (int) $steam->value;
        $query->where('bans_players.steam_id', $steamId64);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($query);

        $result = $query->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $mapped = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'user_url',
                    'avatar',
                    'player_name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires_at',
                    'duration',
                    ''
                ],
                $mapped
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
        if (!$steam) {
            return [];
        }

        $steamId64 = (int) $steam->value;
        $query = $this->prepareSelectQuery($dbname, 'comms', $server, $columns, $search, $order);
        $query->where('bans_players.steam_id', $steamId64);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($query);

        $result = $query->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $mapped = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'type',
                    'user_url',
                    'avatar',
                    'player_name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires_at',
                    'duration',
                    ''
                ],
                $mapped
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
        $query = $this->prepareSelectQuery($dbname, 'comms', $server, $columns, $search, $order);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($query);

        $result = $query->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $mapped = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'type',
                    'user_url',
                    'avatar',
                    'player_name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires_at',
                    'duration',
                    ''
                ],
                $mapped
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
        $query = $this->prepareSelectQuery($dbname, 'ban', $server, $columns, $search, $order);

        $paginator = new \Spiral\Pagination\Paginator($perPage);
        $paginate = $paginator->withPage($page)->paginate($query);

        $result = $query->fetchAll();

        $steamIds = $this->getSteamIds64($result);
        $usersData = steam()->getUsers($steamIds);

        $mapped = $this->mapUsersDataToResult($result, $usersData);

        return [
            'draw' => $draw,
            'recordsTotal' => $paginate->count(),
            'recordsFiltered' => $paginate->count(),
            'data' => TablePreparation::normalize(
                [
                    'user_url',
                    'avatar',
                    'player_name',
                    '',
                    'created_at',
                    'reason',
                    'admin_url',
                    'admin_avatar',
                    'admin_name',
                    '',
                    'expires_at',
                    'duration',
                    ''
                ],
                $mapped
            )
        ];
    }

    public function getCounts(string $dbname, array &$excludeAdmins = [], bool $wasAll = false) : array
    {
        $db = dbal()->database($dbname);

        $bansCountQuery = $db->table('bans_punishments')
            ->select()
            ->where('type', 'ban');

        $mutesCountQuery = $db->table('bans_punishments')
            ->select()
            ->where('type', 'IN', new Parameter(['mute', 'silence']));

        $gagsCountQuery = $db->table('bans_punishments')
            ->select()
            ->where('type', 'gag');

        if ($this->sid !== -1) {
            $bansCountQuery->where('server_ip', $this->sid);
            $mutesCountQuery->where('server_ip', $this->sid);
            $gagsCountQuery->where('server_ip', $this->sid);
        }

        try {
            $bansCount = $bansCountQuery->count();
            $mutesCount = $mutesCountQuery->count();
            $gagsCount = $gagsCountQuery->count();

            $adminsQuery = $db->table('bans_player_ranks')
                ->select('player_id')
                ->distinct();

            if ($this->sid !== -1 && !$wasAll) {

                $adminsQuery->where('server_ip', $this->sid);
            }

            $allAdmins = $adminsQuery->fetchAll();
            $newAdmins = [];

            foreach ($allAdmins as $admin) {

                $pId = $admin['player_id'];
                if (!in_array($pId, $excludeAdmins)) {
                    $excludeAdmins[] = $pId;
                    $newAdmins[] = $pId;
                }
            }

            return [
                'bans' => $bansCount,
                'mutes' => $mutesCount,
                'gags' => $gagsCount,
                'admins' => count($newAdmins),
            ];
        } catch (StatementException $e) {
            logs()->error($e);
            return [
                'bans' => 0,
                'mutes' => 0,
                'gags' => 0,
                'admins' => 0,
            ];
        }
    }

    public function getName() : string
    {
        return "Zenith";
    }

    private function prepareSelectQuery(
        string $dbname,
        string $mode,
        ?Server $server,
        array $columns,
        array $search,
        array $order
    ) {
        $db = dbal()->database($dbname);

        $types = ($mode === 'ban')
            ? ['ban']
            : ['mute', 'gag', 'silence'];

        $query = $db->table('bans_punishments')
            ->select()
            ->columns([
                'bans_punishments.*',
                'bans_players.steam_id as player_steam_id',
                'bans_players.name as player_name',
                'admin_player.steam_id as admin_steam_id',
                'admin_player.name as admin_name',
            ])

            ->innerJoin('bans_players')
            ->on(['bans_punishments.player_id' => 'bans_players.id'])

            ->leftJoin('bans_players as admin_player')
            ->on(['bans_punishments.admin_id' => 'admin_player.id'])
            ->where('bans_punishments.type', 'IN', new Parameter($types));

        if ($server && $this->sid !== -1) {
            $query->andWhere(function ($q) {

                $q->where('bans_punishments.server_ip', $this->sid)
                    ->orWhere('bans_punishments.server_ip', 'all');
            });
        }

        if (isset($search['value']) && !empty($search['value'])) {
            $value = $search['value'];
            $query->andWhere(function ($q) use ($value) {
                $q->where('bans_players.name', 'like', "%$value%")
                    ->orWhere('admin_player.name', 'like', "%$value%")
                    ->orWhere('bans_punishments.reason', 'like', "%$value%");
            });
        }

        foreach ($order as $orderItem) {
            $columnIndex = $orderItem['column'];
            $columnName = $columns[$columnIndex]['name'] ?? null;
            $direction = strtolower($orderItem['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

            if ($columnName && ($columns[$columnIndex]['orderable'] ?? 'false') === 'true') {

                if (in_array($columnName, ['created_at', 'expires_at', 'reason', 'player_name', 'admin_name'])) {
                    $query->orderBy($columnName, $direction);
                }
            }
        }

        return $query;
    }

    private function getSteamIds64(array $results) : array
    {
        $steamIds64 = [];
        foreach ($results as $row) {

            if (!empty($row['player_steam_id']) && !isset($steamIds64[$row['player_steam_id']])) {
                $steamIds64[$row['player_steam_id']] = (string) $row['player_steam_id'];
            }

            if (!empty($row['admin_steam_id']) && !isset($steamIds64[$row['admin_steam_id']])) {
                $steamIds64[$row['admin_steam_id']] = (string) $row['admin_steam_id'];
            }
        }
        return $steamIds64;
    }

    private function mapUsersDataToResult(array $results, array $usersData) : array
    {
        $mappedResults = [];

        foreach ($results as $row) {

            $row['avatar'] = url('assets/img/no_avatar.webp')->get();
            $row['admin_avatar'] = url('assets/img/no_avatar.webp')->get();

            if (!empty($row['player_steam_id'])) {
                $pSteamId64 = (string) $row['player_steam_id'];
                if (isset($usersData[$pSteamId64])) {
                    $row['avatar'] = $usersData[$pSteamId64]->avatar ?? $row['avatar'];
                    $row['player_name'] = $usersData[$pSteamId64]->personaname ?? $row['player_name'];
                }

                $row['user_url'] = url('profile/search/' . $pSteamId64)->addParams([
                    'else-redirect' => "https://steamcommunity.com/profiles/" . $pSteamId64
                ])->get();
            }

            if (!empty($row['admin_steam_id'])) {
                $aSteamId64 = (string) $row['admin_steam_id'];
                if (isset($usersData[$aSteamId64])) {
                    $row['admin_avatar'] = $usersData[$aSteamId64]->avatar ?? $row['admin_avatar'];
                    $row['admin_name'] = $usersData[$aSteamId64]->personaname ?? $row['admin_name'];
                }

                $row['admin_url'] = url('profile/search/' . $aSteamId64)->addParams([
                    'else-redirect' => "https://steamcommunity.com/profiles/" . $aSteamId64
                ])->get();
            } else {

                $row['admin_name'] = $row['admin_name'] ?? 'Console';
                $row['admin_avatar'] = url('assets/img/no_avatar.webp')->get();
                $row['admin_url'] = '';
            }

            $row['created'] = $row['created_at'] ?? null;
            $row['ends'] = $row['expires_at'] ?? null;

            $row['ICON_TYPE'] = $row['type'] ?? '';

            $mappedResults[] = $row;
        }

        return $mappedResults;
    }

    private function dateFormatRender() : string
    {
        return '
            function(data, type) {
                if (type === "display" && data) {
                    let date = new Date(data.replace(/-/g,"/"));
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

    private function iconType() : string
    {
        return '
            function(data, type) {
                if (type === "display") {
                    switch(data) {
                        case "mute": 
                            return `<i class="type-icon ph-bold ph-microphone-slash"></i>`;
                        case "gag":
                        case "silence":
                            return `<i class="type-icon ph-bold ph-chat-circle-dots"></i>`;
                        case "ban":
                            return `<i class="type-icon ph-bold ph-hand-stop"></i>`;
                        default:
                            return data;
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
                let duration = full[11];
                let ends = full[10];

                if (!duration || duration <= 0) {
                    return '<div class=\"ban-chip bans-forever\">' + t(\"banscomms.table.forever\") + '</div>';
                }

                let endsDate = new Date(ends);
                if (endsDate.getTime() < Date.now()) {
                    return '<div class=\"ban-chip bans-end\">' + secondsToReadable(duration) + '</div>';
                }

                return '<div class=\"ban-chip\">' + secondsToReadable(duration) + '</div>';
            }
        ";
    }

    private function timeCommsFormatRender() : string
    {
        return "
            function(data, type, full) {
                let duration = full[12];
                let ends = full[11];

                if (!duration || duration <= 0) {
                    return '<div class=\"ban-chip bans-forever\">' + t(\"banscomms.table.forever\") + '</div>';
                }

                let endsDate = new Date(ends);
                if (endsDate.getTime() < Date.now()) {
                    return '<div class=\"ban-chip bans-end\">' + secondsToReadable(duration) + '</div>';
                }

                return '<div class=\"ban-chip\">' + secondsToReadable(duration) + '</div>';
            }
        ";
    }
}