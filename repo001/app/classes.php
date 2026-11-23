<?php
declare(strict_types=1);

namespace LogBUk\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = \db();
        }

        return self::$connection;
    }
}

final class Http
{
    public static function json(array $data, int $status = 200): never
    {
        \json_response($data, $status);
    }

    public static function error(string $message, int $status = 400): never
    {
        \json_error($message, $status);
    }

    public static function requirePost(): void
    {
        \require_post();
    }

    public static function input(): array
    {
        return \request_input();
    }
}

namespace LogBUk\Security;

final class Auth
{
    public static function requirePage(): void
    {
        \require_admin_page();
    }

    public static function requireApi(): void
    {
        \require_admin();
    }

    public static function adminId(): ?int
    {
        return \current_admin_id();
    }
}

namespace LogBUk\Support;

final class View
{
    public static function escape(mixed $value): string
    {
        return \e($value);
    }

    public static function databaseError(string $message): array
    {
        return \db_error_hint($message);
    }
}

namespace LogBUk\Services;

final class OccupantService
{
    public static function list(array $filters, int $page = 1, int $perPage = OCCUPANTS_PER_PAGE, string $sort = 'id', string $direction = 'ASC'): array
    {
        return \occupants_page($filters, $page, $perPage, $sort, $direction);
    }

    public static function find(string $occupantId): ?array
    {
        return \occupant_find($occupantId);
    }

    public static function create(array $input, ?int $adminId = null): array
    {
        return \occupant_create($input, $adminId);
    }

    public static function update(string $occupantId, array $input, ?int $adminId = null): array
    {
        return \occupant_update($occupantId, $input, $adminId);
    }

    public static function deactivate(string $occupantId, ?string $reason = null, ?int $adminId = null): array
    {
        return \occupant_deactivate($occupantId, $reason, $adminId);
    }

    public static function reactivate(string $occupantId, string $roomId, ?int $adminId = null): array
    {
        return \occupant_reactivate($occupantId, $roomId, $adminId);
    }

    public static function rooms(): array
    {
        return \rooms_with_occupancy();
    }
}

final class AutoDeactivationService
{
    public static function run(int $everyHours = 24): array
    {
        return \auto_deactivate_if_due($everyHours);
    }

    public static function summary(array $result): string
    {
        return \auto_deactivate_summary($result);
    }
}