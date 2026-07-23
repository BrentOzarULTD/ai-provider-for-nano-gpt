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
        $wpdb->query(self::prepared($wpdb->prepare('DROP TABLE IF EXISTS %i', self::tableName())));
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
        $where = $this->whereClause($wpdb, $filters);
        $offset = max(0, ($page - 1) * $perPage);
        $from = self::prepared($wpdb->prepare('FROM %i', $table));
        $itemsSql = "SELECT * {$from} {$where} "
            . "ORDER BY created_at_gmt DESC, id DESC LIMIT {$perPage} OFFSET {$offset}";
        $results = $wpdb->get_results($itemsSql);
        $items = [];
        if (is_array($results)) {
            foreach ($results as $result) {
                if (is_object($result)) {
                    $items[] = $result;
                }
            }
        }
        $count = $wpdb->get_var("SELECT COUNT(*) {$from} {$where}");

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
        $values = $wpdb->get_col(
            self::prepared(
                $wpdb->prepare(
                    'SELECT DISTINCT %i FROM %i WHERE %i <> %s ORDER BY %i ASC',
                    $column,
                    self::tableName(),
                    $column,
                    '',
                    $column
                )
            )
        );

        return is_array($values)
            ? array_values(array_filter($values, 'is_string'))
            : [];
    }

    public function clear(): void
    {
        $wpdb = self::database();
        self::maybeInstall();
        $wpdb->query(self::prepared($wpdb->prepare('DELETE FROM %i', self::tableName())));
    }

    public function prune(int $retentionDays, int $maxRecords): void
    {
        $wpdb = self::database();
        self::maybeInstall();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $wpdb->query(
            self::prepared(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE created_at_gmt < %s',
                    self::tableName(),
                    $cutoff
                )
            )
        );

        $boundary = $wpdb->get_var(
            self::prepared(
                $wpdb->prepare(
                    'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
                    self::tableName(),
                    max(0, $maxRecords - 1)
                )
            )
        );
        if (is_numeric($boundary)) {
            $wpdb->query(
                self::prepared(
                    $wpdb->prepare(
                        'DELETE FROM %i WHERE id < %d',
                        self::tableName(),
                        (int) $boundary
                    )
                )
            );
        }
    }

    public static function tableName(): string
    {
        $wpdb = self::database();
        return $wpdb->prefix . 'nanogpt_ai_activity';
    }

    /**
     * @param array{status?: string, capability?: string, model?: string, source?: string} $filters Filters.
     */
    private function whereClause(\wpdb $wpdb, array $filters): string
    {
        $clauses = [];
        foreach (['status', 'capability', 'model'] as $field) {
            if (!isset($filters[$field]) || $filters[$field] === '') {
                continue;
            }
            $clauses[] = self::prepared(
                $wpdb->prepare('%i = %s', $field, $filters[$field])
            );
        }
        if (isset($filters['source']) && $filters['source'] !== '') {
            $clauses[] = self::prepared(
                $wpdb->prepare('source_name = %s', $filters['source'])
            );
        }

        return $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
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

    private static function prepared(?string $query): string
    {
        if ($query === null || $query === '') {
            throw new RuntimeException('A database query could not be prepared.');
        }

        return $query;
    }
}
