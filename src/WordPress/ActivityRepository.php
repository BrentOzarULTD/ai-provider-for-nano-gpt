<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\WordPress;

use RuntimeException;

/**
 * Persists and queries the bounded local activity log.
 *
 * @since 2.0.0
 */
class ActivityRepository
{
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'nanogpt_activity_db_version';

    public static function maybeInstall(): void
    {
        if (get_option(self::DB_VERSION_OPTION, '') === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    public static function install(): void
    {
        if (!function_exists('dbDelta')) {
            $root = self::wordpressRoot();
            require_once $root . 'wp-admin/includes/upgrade.php';
        }
        $wpdb = self::database();
        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at_gmt datetime NOT NULL,
            duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            model varchar(191) NOT NULL DEFAULT '',
            capability varchar(32) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT '',
            http_status smallint(5) unsigned NULL,
            source_type varchar(32) NOT NULL DEFAULT '',
            source_name varchar(191) NOT NULL DEFAULT '',
            source_detail text NULL,
            request_context longtext NULL,
            request_config longtext NULL,
            prompt longtext NULL,
            response longtext NULL,
            error_code varchar(191) NOT NULL DEFAULT '',
            error_message text NULL,
            input_tokens bigint(20) unsigned NULL,
            output_tokens bigint(20) unsigned NULL,
            total_tokens bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY created_at_gmt (created_at_gmt),
            KEY status (status),
            KEY model (model),
            KEY source_name (source_name)
        ) {$charsetCollate};";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function uninstall(): void
    {
        $wpdb = self::database();
        $sql = $wpdb->prepare('DROP TABLE IF EXISTS %i', self::tableName());
        if (!is_string($sql)) {
            throw new RuntimeException('The uninstall query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $wpdb->query($sql);
        delete_option(self::DB_VERSION_OPTION);
        delete_option(ActivitySettings::ENABLED_OPTION);
        delete_option(ActivitySettings::CONTENT_OPTION);
        delete_option(ActivitySettings::RETENTION_DAYS_OPTION);
        delete_option(ActivitySettings::MAX_RECORDS_OPTION);
    }

    /**
     * @param array<string, int|string|null> $record Database record.
     */
    public function insert(array $record): void
    {
        $wpdb = self::database();
        self::maybeInstall();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Activity records are intentionally stored in the plugin table.
        $wpdb->insert(self::tableName(), $record);
        $this->prune(ActivitySettings::retentionDays(), ActivitySettings::maxRecords());
    }

    /**
     * @param array{status?: string, capability?: string, model?: string, source?: string} $filters Filters.
     * @return array{items: list<object>, total: int}
     */
    public function query(array $filters, int $page = 1, int $perPage = 50): array
    {
        $wpdb = self::database();
        self::maybeInstall();
        $table = self::tableName();
        $offset = max(0, ($page - 1) * $perPage);
        $itemsSql = $wpdb->prepare(
            'SELECT * FROM %i
                WHERE (%s = %s OR status = %s)
                AND (%s = %s OR capability = %s)
                AND (%s = %s OR model = %s)
                AND (%s = %s OR source_name = %s)
                ORDER BY created_at_gmt DESC, id DESC
                LIMIT %d OFFSET %d',
            $table,
            $filters['status'] ?? '',
            '',
            $filters['status'] ?? '',
            $filters['capability'] ?? '',
            '',
            $filters['capability'] ?? '',
            $filters['model'] ?? '',
            '',
            $filters['model'] ?? '',
            $filters['source'] ?? '',
            '',
            $filters['source'] ?? '',
            $perPage,
            $offset
        );
        if (!is_string($itemsSql)) {
            throw new RuntimeException('The activity query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $results = $wpdb->get_results($itemsSql);
        $items = [];
        if (is_array($results)) {
            foreach ($results as $result) {
                if (is_object($result)) {
                    $items[] = $result;
                }
            }
        }
        $countSql = $wpdb->prepare(
            'SELECT COUNT(*) FROM %i
                WHERE (%s = %s OR status = %s)
                AND (%s = %s OR capability = %s)
                AND (%s = %s OR model = %s)
                AND (%s = %s OR source_name = %s)',
            $table,
            $filters['status'] ?? '',
            '',
            $filters['status'] ?? '',
            $filters['capability'] ?? '',
            '',
            $filters['capability'] ?? '',
            $filters['model'] ?? '',
            '',
            $filters['model'] ?? '',
            $filters['source'] ?? '',
            '',
            $filters['source'] ?? ''
        );
        if (!is_string($countSql)) {
            throw new RuntimeException('The activity count query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $count = $wpdb->get_var($countSql);

        return [
            'items' => $items,
            'total' => is_numeric($count) ? (int) $count : 0,
        ];
    }

    /**
     * @return list<string>
     */
    public function distinctValues(string $column): array
    {
        $wpdb = self::database();
        if (!in_array($column, ['model', 'source_name'], true)) {
            return [];
        }
        self::maybeInstall();
        $sql = $column === 'model'
            ? $wpdb->prepare(
                'SELECT DISTINCT model FROM %i WHERE model <> %s ORDER BY model ASC',
                self::tableName(),
                ''
            )
            : $wpdb->prepare(
                'SELECT DISTINCT source_name FROM %i WHERE source_name <> %s ORDER BY source_name ASC',
                self::tableName(),
                ''
            );
        if (!is_string($sql)) {
            throw new RuntimeException('The activity filter query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $values = $wpdb->get_col($sql);

        return is_array($values)
            ? array_values(array_filter($values, 'is_string'))
            : [];
    }

    public function clear(): void
    {
        $wpdb = self::database();
        self::maybeInstall();
        $sql = $wpdb->prepare('DELETE FROM %i', self::tableName());
        if (!is_string($sql)) {
            throw new RuntimeException('The clear-log query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $wpdb->query($sql);
    }

    public function prune(int $retentionDays, int $maxRecords): void
    {
        $wpdb = self::database();
        self::maybeInstall();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $deleteExpiredSql = $wpdb->prepare(
            'DELETE FROM %i WHERE created_at_gmt < %s',
            self::tableName(),
            $cutoff
        );
        if (!is_string($deleteExpiredSql)) {
            throw new RuntimeException('The activity retention query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $wpdb->query($deleteExpiredSql);

        $boundarySql = $wpdb->prepare(
            'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
            self::tableName(),
            max(0, $maxRecords - 1)
        );
        if (!is_string($boundarySql)) {
            throw new RuntimeException('The activity boundary query could not be prepared.');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
        $boundary = $wpdb->get_var($boundarySql);
        if (is_numeric($boundary)) {
            $deleteOverflowSql = $wpdb->prepare(
                'DELETE FROM %i WHERE id < %d',
                self::tableName(),
                (int) $boundary
            );
            if (!is_string($deleteOverflowSql)) {
                throw new RuntimeException('The activity size-limit query could not be prepared.');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared and validated above.
            $wpdb->query($deleteOverflowSql);
        }
    }

    public static function tableName(): string
    {
        $wpdb = self::database();
        return $wpdb->prefix . 'nanogpt_ai_activity';
    }

    private static function database(): \wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new RuntimeException('WordPress database access is unavailable.');
        }

        return $wpdb;
    }

    private static function wordpressRoot(): string
    {
        if (!defined('ABSPATH')) {
            throw new RuntimeException('WordPress is not loaded.');
        }
        $root = constant('ABSPATH');
        if (!is_string($root) || $root === '') {
            throw new RuntimeException('The WordPress root path is invalid.');
        }

        return $root;
    }
}
