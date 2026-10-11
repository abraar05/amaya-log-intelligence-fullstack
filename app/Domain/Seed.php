<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;

/**
 * Idempotent baseline data.
 */
final class Seed
{
    /**
     * Extracted from the user's "Sheet 2"
     * (spreadsheet 1_HuqeQMIaWOiATeHddFm5tUpsrj0x7EmatN1W_IJLRY, gid 1105115712),
     * which is a registry of operational systems and their links.
     */
    public const SOURCE_LINKS = [
        ['ERP TV',              'http://103.106.118.58:7583/',                                  'Internal system'],
        ['ERP Mobile',          'https://global.zhihuiji.cn/#/user/login',                        'Internal system'],
        ['TV Order Processing', 'https://docs.google.com/spreadsheets/d/1-uKP2AFJyVrwibGMZVN7qy9sgdIeKGM4z74SYd4GUXQ/edit?gid=1285786443#gid=1285786443', 'Google Sheet'],
        ['NW',                  'https://docs.google.com/spreadsheets/d/1mop7ZAi0wxPjLmz-Fxc8u0vBKA9gtglrvO234QTEXDo/edit?gid=929163505#gid=929163505',  'Google Sheet'],
        ['Cash Accounts',       'https://docs.google.com/spreadsheets/d/1HjbDxsXofIz_qmpK9RyEIDR60O0Pj8uuAfoTTZPDFCc/edit?gid=0#gid=0',                     'Google Sheet'],
        ['UNO',                 'https://docs.google.com/spreadsheets/d/1bqkVehJun-96yjFNrJRw9S89e3YSjt1pu8e6d0x07bk/edit?gid=1618809603#gid=1618809603', 'Google Sheet'],
        ['Alpana Cash',         'https://docs.google.com/spreadsheets/d/1gimiqrecqXwGxTaFjpcprgU6ZvMaMZKYN44599K62cY/edit?pli=1&gid=0#gid=0',      'Google Sheet'],
        ['DMS - TV',            'https://docs.google.com/spreadsheets/d/16xUAPeFNOJf0rAbAp0PkrvBxzWn0-4vs_KQ7WlbZvSI/edit?gid=1038613336#gid=1038613336', 'Google Sheet'],
        ['MI TV Billing',       'https://docs.google.com/spreadsheets/d/16xUAPeFNOJf0rAbAp0PkrvBxzWn0-4vs_KQ7WlbZvSI/edit?gid=1038613336#gid=1038613336', 'Google Sheet'],
    ];

    /**
     * The user's data sheets. Sheets 1 and 3 were confirmed private (HTTP 401),
     * so they are registered as sources but carry no preloaded rows.
     */
    public const SPREADSHEETS = [
        [
            'name'   => 'Primary operational register',
            'id'     => '1CcArUO1Ezisacnf_pR-1GtZbrhScCaGM',
            'gid'    => '1002876108',
            'status' => 'private',
        ],
        [
            'name'   => 'System link registry',
            'id'     => '1_HuqeQMIaWOiATeHddFm5tUpsrj0x7EmatN1W_IJLRY',
            'gid'    => '1105115712',
            'status' => 'public',
        ],
        [
            'name'   => 'Secondary operational register',
            'id'     => '1W56YQwx-2AVy-bOOOtplCFPUF27uC0YZShobStkBB_Y',
            'gid'    => '165729331',
            'status' => 'private',
        ],
    ];

    public static function departments(Database $db, array $names): void
    {
        foreach ($names as $name) {
            $exists = $db->fetchValue('SELECT id FROM departments WHERE name = ?', [$name]);
            if ($exists === null) {
                $db->run('INSERT INTO departments (name) VALUES (?)', [$name]);
            }
        }
    }

    public static function sourceLinks(Database $db): void
    {
        $existing = (int) $db->fetchValue('SELECT COUNT(*) FROM source_links');
        if ($existing > 0) {
            return;
        }
        foreach (self::SOURCE_LINKS as $i => [$label, $url, $category]) {
            $db->run(
                'INSERT INTO source_links (label, url, category, sort_order) VALUES (?, ?, ?, ?)',
                [$label, $url, $category, $i]
            );
        }
    }

    /**
     * Register the known spreadsheets as data sources (idempotent by name).
     */
    public static function spreadsheets(Database $db): void
    {
        foreach (self::SPREADSHEETS as $sheet) {
            $exists = $db->fetchValue('SELECT id FROM data_sources WHERE name = ?', [$sheet['name']]);
            if ($exists === null) {
                $db->run(
                    'INSERT INTO data_sources (name, source_type, source_url, sheet_gid, last_status) VALUES (?, ?, ?, ?, ?)',
                    [
                        $sheet['name'],
                        'Google Sheets CSV URL',
                        'https://docs.google.com/spreadsheets/d/' . $sheet['id'] . '/edit?gid=' . $sheet['gid'],
                        $sheet['gid'],
                        $sheet['status'] === 'public' ? 'connected' : 'private',
                    ]
                );
            }
        }
    }
}